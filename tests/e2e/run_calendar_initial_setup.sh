#!/usr/bin/env bash
# Schema-only tests against a fresh, private, network-disabled MariaDB instance.
# Never point this runner at an existing database or application configuration.
set -euo pipefail
PATH="$PATH:/usr/sbin:/sbin"
REPO="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
for tool in mariadb-install-db mariadbd mariadb mktemp php; do
  command -v "$tool" >/dev/null || { echo "Missing required tool: $tool" >&2; exit 2; }
done
SQL="$REPO/sql/095_school_calendar_configuration.sql"
[ -f "$SQL" ] || { echo 'Calendar schema migration not found' >&2; exit 2; }
mkdir -p /home/user/.cache
D=$(mktemp -d /home/user/.cache/calendar-schema-db.XXXXXXXX)
SOCKET="$D/mysql.sock"
PID=''
cleanup() {
  if [ -n "$PID" ]; then kill "$PID" 2>/dev/null || true; wait "$PID" 2>/dev/null || true; fi
  case "$D" in /home/user/.cache/calendar-schema-db.[a-zA-Z0-9]*) rm -rf -- "$D";; *) echo 'Refusing unsafe cleanup' >&2;; esac
}
trap cleanup EXIT INT TERM
mariadb-install-db --no-defaults --datadir="$D/data" --auth-root-authentication-method=normal --skip-test-db > "$D/init.log" 2>&1 || { cat "$D/init.log" >&2; exit 1; }
mariadbd --no-defaults --datadir="$D/data" --socket="$SOCKET" --pid-file="$D/db.pid" --skip-networking --log-error="$D/db.log" & PID=$!
for _ in $(seq 1 100); do
  [ -S "$SOCKET" ] && mariadb --no-defaults --protocol=socket --socket="$SOCKET" -u root -e 'SELECT 1' >/dev/null 2>&1 && break
  kill -0 "$PID" 2>/dev/null || { cat "$D/db.log" >&2; exit 1; }
  sleep 0.1
done
[ -S "$SOCKET" ] || { echo 'Fixture socket unavailable' >&2; exit 1; }
ROOT=(mariadb --no-defaults --protocol=socket --socket="$SOCKET" -u root)
FACTS=$("${ROOT[@]}" -N -e 'SELECT @@skip_networking,@@datadir')
[ "$FACTS" = "$(printf '1\t%s/data/' "$D")" ] || { echo 'Refusing unexpected database server' >&2; exit 3; }
"${ROOT[@]}" -e "CREATE DATABASE sms_calendar_service_test; CREATE USER 'calendar_test'@'localhost' IDENTIFIED BY 'fixture-only'; GRANT ALL PRIVILEGES ON sms_calendar_service_test.* TO 'calendar_test'@'localhost';"
DB=(mariadb --no-defaults --protocol=socket --socket="$SOCKET" -u calendar_test --password=fixture-only sms_calendar_service_test)
"${DB[@]}" < "$SQL"
SSMS_AUDIT_TESTING=1 SMS_CALENDAR_TEST_SOCKET="$SOCKET" php "$REPO/tests/e2e/calendar_initial_setup.php"
