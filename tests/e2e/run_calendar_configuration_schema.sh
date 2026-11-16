#!/usr/bin/env bash
# Schema-only tests against a fresh, private, network-disabled MariaDB instance.
# Never point this runner at an existing database or application configuration.
set -euo pipefail
PATH="$PATH:/usr/sbin:/sbin"
REPO="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
for tool in mariadb-install-db mariadbd mariadb mktemp; do
  command -v "$tool" >/dev/null || { echo "Missing required tool: $tool" >&2; exit 2; }
done
SQL="$REPO/sql/095_school_calendar_configuration.sql"
[ -f "$SQL" ] || { echo 'Calendar schema migration not found' >&2; exit 2; }
# Fixture base: /tmp, never a user's home. The MariaDB AppArmor profile
# shipped with a stock Ubuntu runner allows /tmp/** and denies paths under
# /home, so a datadir in a home directory fails with 'Errcode: 13
# Permission denied'. Override with SSMS_FIXTURE_BASE if /tmp is unsuitable.
BASE="${SSMS_FIXTURE_BASE:-/tmp}"
mkdir -p "$BASE"
D=$(mktemp -d "$BASE/calendar-schema-db.XXXXXXXX")
SOCKET="$D/mysql.sock"
PID=''
cleanup() {
  if [ -n "$PID" ]; then kill "$PID" 2>/dev/null || true; wait "$PID" 2>/dev/null || true; fi
  case "$D" in "$BASE"/calendar-schema-db.[a-zA-Z0-9]*) rm -rf -- "$D";; *) echo 'Refusing unsafe cleanup' >&2;; esac
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
"${ROOT[@]}" -e "CREATE DATABASE sms_calendar_schema_test; CREATE USER 'calendar_test'@'localhost' IDENTIFIED BY 'fixture-only'; GRANT ALL PRIVILEGES ON sms_calendar_schema_test.* TO 'calendar_test'@'localhost';"
DB=(mariadb --no-defaults --protocol=socket --socket="$SOCKET" -u calendar_test --password=fixture-only --batch --skip-column-names sms_calendar_schema_test)
"${DB[@]}" < "$SQL"
check() { local got; got=$("${DB[@]}" -e "$1"); [ "$got" = "$2" ] || { printf 'Unexpected SQL result: expected %s, got %s\n' "$2" "$got" >&2; exit 1; }; }
check "SELECT CONCAT(id,':',IFNULL(mode,'unconfigured'),':',revision) FROM school_calendar_configuration" '1:unconfigured:0'
check "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('school_calendar_configuration','school_calendar_configuration_audit') AND ENGINE='InnoDB'" '2'
# The singleton constraint must actually be enforced, not merely declared.
if "${DB[@]}" -e "INSERT INTO school_calendar_configuration(id,mode,revision) VALUES (2,NULL,0)" >/dev/null 2>&1; then echo 'Singleton constraint failed' >&2; exit 1; fi
# Exercise rollback and commit using synthetic actor 42, not production data.
"${DB[@]}" -e "START TRANSACTION; UPDATE school_calendar_configuration SET mode='ethiopian',revision=1 WHERE id=1; INSERT INTO school_calendar_configuration_audit(configuration_id,revision,previous_mode,new_mode,actor_user_id,occurred_at) VALUES(1,1,NULL,'ethiopian',42,NOW(6)); ROLLBACK;"
check "SELECT CONCAT(IFNULL(mode,'unconfigured'),':',revision,':',(SELECT COUNT(*) FROM school_calendar_configuration_audit)) FROM school_calendar_configuration WHERE id=1" 'unconfigured:0:0'
"${DB[@]}" -e "START TRANSACTION; UPDATE school_calendar_configuration SET mode='ethiopian',revision=1 WHERE id=1; INSERT INTO school_calendar_configuration_audit(configuration_id,revision,previous_mode,new_mode,actor_user_id,occurred_at) VALUES(1,1,NULL,'ethiopian',42,NOW(6)); COMMIT;"
"${DB[@]}" < "$SQL"
check "SELECT CONCAT(mode,':',revision,':',(SELECT COUNT(*) FROM school_calendar_configuration_audit)) FROM school_calendar_configuration WHERE id=1" 'ethiopian:1:1'
if "${DB[@]}" -e "INSERT INTO school_calendar_configuration_audit(configuration_id,revision,previous_mode,new_mode,actor_user_id,occurred_at) VALUES(1,1,'ethiopian','gregorian',42,NOW(6))" >/dev/null 2>&1; then echo 'Audit revision uniqueness failed' >&2; exit 1; fi
echo 'PASS: unconfigured bootstrap, singleton, InnoDB, rollback, commit, idempotence and audit revision constraint'
