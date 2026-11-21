#!/usr/bin/env bash
# This test suite DROPS tables. Run only on a fresh local disposable server.
set -euo pipefail
PATH="$PATH:/usr/sbin:/sbin"
REPO="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
for tool in mariadb-install-db mariadbd mariadb php python3 mktemp; do
  command -v "$tool" >/dev/null 2>&1 || { echo "Missing required tool: $tool" >&2; exit 2; }
done
[ -f "$REPO/tests/security/test_attendance_offline_calendar.py" ] || exit 2
mkdir -p /home/user/.cache
D=$(mktemp -d /home/user/.cache/offline-calendar-db.XXXXXXXX)
SOCKET="$D/mysql.sock"
SERVER_PID=''
cleanup() {
  if [ -n "$SERVER_PID" ]; then kill "$SERVER_PID" 2>/dev/null || true; wait "$SERVER_PID" 2>/dev/null || true; fi
  case "$D" in /home/user/.cache/offline-calendar-db.[a-zA-Z0-9]*) rm -rf -- "$D";; *) echo 'Refusing cleanup outside fixture directory' >&2;; esac
}
trap cleanup EXIT INT TERM
mariadb-install-db --no-defaults --datadir="$D/data" --auth-root-authentication-method=normal --skip-test-db > "$D/init.log" 2>&1 || { cat "$D/init.log" >&2; exit 1; }
PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
mariadbd --no-defaults --datadir="$D/data" --socket="$SOCKET" --pid-file="$D/mysql.pid" --bind-address=127.0.0.1 --port="$PORT" --log-error="$D/error.log" &
SERVER_PID=$!
for _ in $(seq 1 100); do
  [ -S "$SOCKET" ] && mariadb --no-defaults --protocol=socket --socket="$SOCKET" -u root -N -e 'SELECT 1' >/dev/null 2>&1 && break
  kill -0 "$SERVER_PID" 2>/dev/null || { cat "$D/error.log" >&2; exit 1; }
  sleep 0.1
done
[ -S "$SOCKET" ] || { echo 'Database socket did not become ready' >&2; exit 1; }
FACTS=$(mariadb --no-defaults --protocol=socket --socket="$SOCKET" -u root -N -e 'SELECT @@datadir,@@port,@@bind_address')
[ "$FACTS" = "$(printf '%s/data/\t%s\t127.0.0.1' "$D" "$PORT")" ] || { echo 'Refusing: isolated server identity checks failed' >&2; exit 1; }
mariadb --no-defaults --protocol=socket --socket="$SOCKET" -u root <<'SQL'
CREATE DATABASE ssms_calendar_e2e;
CREATE USER 'calendar_fixture'@'127.0.0.1' IDENTIFIED BY 'fixture-only';
GRANT ALL PRIVILEGES ON ssms_calendar_e2e.* TO 'calendar_fixture'@'127.0.0.1';
SQL
# Verify TCP resolves to this exact data directory using the limited test user.
TCP_FACTS=$(MYSQL_PWD=fixture-only mariadb --no-defaults --protocol=tcp -h 127.0.0.1 -P "$PORT" -u calendar_fixture -N -e 'SELECT @@datadir')
[ "$TCP_FACTS" = "$D/data/" ] || { echo 'Refusing: TCP reached unexpected server' >&2; exit 1; }
cd "$REPO"
CAL_TEST_DB_HOST="127.0.0.1:$PORT" CAL_TEST_DB_USER=calendar_fixture CAL_TEST_DB_PASS=fixture-only CAL_TEST_DB_NAME=ssms_calendar_e2e python3 -m pytest tests/security/test_attendance_offline_calendar.py -q -rs
