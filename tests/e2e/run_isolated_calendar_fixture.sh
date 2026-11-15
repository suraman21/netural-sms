#!/usr/bin/env bash
# Disposable, socket-only fixture for the existing attendance calendar tests.
# Does not read application configuration or touch an existing database.
set -euo pipefail
PATH="$PATH:/usr/sbin:/sbin"
REPO="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
for tool in mariadb-install-db mariadbd mariadb php python3 mktemp; do
  command -v "$tool" >/dev/null 2>&1 || { echo "Missing required tool: $tool" >&2; exit 2; }
done
[ -f "$REPO/tests/e2e/attendance_preparation_calendar.php" ] || { echo 'Repository fixture not found' >&2; exit 2; }
mkdir -p /home/user/.cache
D=$(mktemp -d /home/user/.cache/strict-preparation-db.XXXXXXXX)
SOCKET="$D/mysql.sock"
SERVER_PID=''
cleanup() {
  if [ -n "$SERVER_PID" ]; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
  # Only a new directory created by mktemp in this invocation is removed.
  case "$D" in /home/user/.cache/strict-preparation-db.[a-zA-Z0-9]*) rm -rf -- "$D";; *) echo 'Refusing cleanup outside fixture directory' >&2;; esac
}
trap cleanup EXIT INT TERM
mariadb-install-db --no-defaults --datadir="$D/data" --auth-root-authentication-method=normal --skip-test-db > "$D/init.log" 2>&1 || { cat "$D/init.log" >&2; exit 1; }
mariadbd --no-defaults --datadir="$D/data" --socket="$SOCKET" --pid-file="$D/mysql.pid" --skip-networking --log-error="$D/error.log" &
SERVER_PID=$!
for _ in $(seq 1 100); do
  [ -S "$SOCKET" ] && mariadb --no-defaults --protocol=socket --socket="$SOCKET" -u root -N -e 'SELECT 1' >/dev/null 2>&1 && break
  kill -0 "$SERVER_PID" 2>/dev/null || { cat "$D/error.log" >&2; exit 1; }
  sleep 0.1
done
[ -S "$SOCKET" ] || { echo 'Database socket did not become ready' >&2; exit 1; }
FACTS=$(mariadb --no-defaults --protocol=socket --socket="$SOCKET" -u root -N -e 'SELECT @@skip_networking,@@datadir')
[ "$FACTS" = "$(printf '1\t%s/data/' "$D")" ] || { echo 'Refusing: isolated server safety checks failed' >&2; exit 1; }
mariadb --no-defaults --protocol=socket --socket="$SOCKET" -u root <<'SQL'
CREATE DATABASE ssms_comm_e2e;
CREATE USER 'ssms'@'localhost' IDENTIFIED BY 'ssms';
GRANT CREATE TEMPORARY TABLES ON ssms_comm_e2e.* TO 'ssms'@'localhost';
SQL
cd "$REPO"
SSMS_AUDIT_TESTING=1 SSMS_PREPARATION_TEST_SOCKET="$SOCKET" python3 -m pytest tests/security/test_attendance_preparation_calendar.py -q -rs
