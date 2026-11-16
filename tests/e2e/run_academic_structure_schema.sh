#!/usr/bin/env bash
# Schema-only tests against a fresh, private, network-disabled MariaDB instance.
# Never point this runner at an existing database or application configuration.
set -euo pipefail
PATH="$PATH:/usr/sbin:/sbin"
REPO="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
for tool in mariadb-install-db mariadbd mariadb mktemp; do
  command -v "$tool" >/dev/null || { echo "Missing required tool: $tool" >&2; exit 2; }
done
SQL="$REPO/sql/096_school_academic_structure.sql"
[ -f "$SQL" ] || { echo 'Academic schema migration not found' >&2; exit 2; }
mkdir -p /home/user/.cache
D=$(mktemp -d /home/user/.cache/academic-structure-db.XXXXXXXX)
SOCKET="$D/mysql.sock"
PID=''
cleanup() {
  if [ -n "$PID" ]; then kill "$PID" 2>/dev/null || true; wait "$PID" 2>/dev/null || true; fi
  case "$D" in /home/user/.cache/academic-structure-db.[a-zA-Z0-9]*) rm -rf -- "$D";; *) echo 'Refusing unsafe cleanup' >&2;; esac
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
"${ROOT[@]}" -e "CREATE DATABASE sms_academic_schema_test; CREATE USER 'academic_test'@'localhost' IDENTIFIED BY 'fixture-only'; GRANT ALL PRIVILEGES ON sms_academic_schema_test.* TO 'academic_test'@'localhost';"
DB=(mariadb --no-defaults --protocol=socket --socket="$SOCKET" -u academic_test --password=fixture-only --batch --skip-column-names sms_academic_schema_test)
"${DB[@]}" -e "CREATE TABLE users (id INT UNSIGNED PRIMARY KEY, role VARCHAR(50) NOT NULL, is_active TINYINT NOT NULL, password_hash VARCHAR(255) NOT NULL, authorization_version BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB; INSERT INTO users VALUES (42,'edu_dept',1,'fixture-hash',1);"
"${DB[@]}" < "$SQL"
"${DB[@]}" < "$SQL"
"${DB[@]}" < "$REPO/sql/097_school_academic_structure_audit.sql"
"${DB[@]}" < "$REPO/sql/097_school_academic_structure_audit.sql"
check() { local got; got=$("${DB[@]}" -e "$1"); [ "$got" = "$2" ] || { printf 'Expected %s, got %s\n' "$2" "$got" >&2; exit 1; }; }
check "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('school_education_levels','school_grades','school_grade_sections') AND ENGINE='InnoDB'" 3
check "SELECT (SELECT COUNT(*) FROM school_education_levels)+(SELECT COUNT(*) FROM school_grades)+(SELECT COUNT(*) FROM school_grade_sections)" 0
"${DB[@]}" -e "INSERT INTO school_education_levels(id,name,sort_order) VALUES(10,'Primary',1),(20,'Secondary',2); INSERT INTO school_grades(id,level_id,name,sort_order) VALUES(11,10,'Grade 1',1),(21,20,'Grade 9',1); INSERT INTO school_grade_sections(id,grade_id,name) VALUES(101,11,'A'),(201,21,'A'),(202,21,'B');"
if "${DB[@]}" -e "INSERT INTO school_grade_sections(grade_id,name) VALUES(21,'A')" >/dev/null 2>&1; then echo 'Duplicate section was accepted' >&2; exit 1; fi
if "${DB[@]}" -e "INSERT INTO school_grades(level_id,name) VALUES(999,'Orphan')" >/dev/null 2>&1; then echo 'Orphan grade was accepted' >&2; exit 1; fi
if "${DB[@]}" -e "DELETE FROM school_education_levels WHERE id=20" >/dev/null 2>&1; then echo 'Referenced level was deleted' >&2; exit 1; fi
if "${DB[@]}" -e "DELETE FROM school_grades WHERE id=21" >/dev/null 2>&1; then echo 'Referenced grade was deleted' >&2; exit 1; fi
"${DB[@]}" -e "START TRANSACTION; UPDATE school_education_levels SET name='Junior',sort_order=5,is_active=0 WHERE id=10; UPDATE school_grades SET name='Level One',sort_order=7,is_active=0 WHERE id=11; UPDATE school_grade_sections SET name='Blue',sort_order=3,is_active=0 WHERE id=101; ROLLBACK;"
check "SELECT CONCAT(name,':',sort_order,':',is_active) FROM school_education_levels WHERE id=10" 'Primary:1:1'
"${DB[@]}" -e "UPDATE school_education_levels SET name='Junior',sort_order=5,is_active=0 WHERE id=10; UPDATE school_grades SET name='Level One',sort_order=7,is_active=0 WHERE id=11; UPDATE school_grade_sections SET name='Blue',sort_order=3,is_active=0 WHERE id=101;"
check "SELECT CONCAT(l.name,':',g.name,':',s.name,':',l.is_active,':',g.is_active,':',s.is_active) FROM school_grade_sections s JOIN school_grades g ON g.id=s.grade_id JOIN school_education_levels l ON l.id=g.level_id WHERE s.id=101" 'Junior:Level One:Blue:0:0:0'
"${DB[@]}" < "$SQL"
check "SELECT COUNT(*) FROM school_grade_sections" 3
echo 'PASS: empty bootstrap, repeat apply, hierarchy, uniqueness, FK restrictions, rollback and updates'
command -v php >/dev/null || { echo 'Missing required tool: php' >&2; exit 2; }
SMS_STRUCTURE_FIXTURE_SOCKET="$SOCKET" php "$REPO/tests/e2e/academic_structure_create.php"
