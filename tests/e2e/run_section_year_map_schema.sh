#!/usr/bin/env bash
# Destructive tests on a fresh private socket-only database, NEVER on school data.
set -euo pipefail
PATH="$PATH:/usr/sbin:/sbin"
REPO=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
for x in mariadbd mariadb-install-db mariadb mktemp; do command -v "$x" >/dev/null || { echo "Missing $x" >&2; exit 2; }; done
D=$(mktemp -d /tmp/sms-section-map.XXXXXXXX); PID=''
cleanup() {
    [ -z "$PID" ] || { kill "$PID" 2>/dev/null || true; wait "$PID" 2>/dev/null || true; }
    case "$D" in /tmp/sms-section-map.[a-zA-Z0-9]*) rm -rf -- "$D";; *) echo 'Unsafe cleanup path' >&2;; esac
}
trap cleanup EXIT INT TERM
mariadb-install-db --no-defaults --datadir="$D/data" --auth-root-authentication-method=normal --skip-test-db >"$D/init.log" 2>&1
mariadbd --no-defaults --datadir="$D/data" --socket="$D/mysql.sock" --pid-file="$D/mysql.pid" --skip-networking --log-error="$D/db.log" & PID=$!
for _ in $(seq 1 100); do
    [ -S "$D/mysql.sock" ] && mariadb --no-defaults --protocol=socket --socket="$D/mysql.sock" -u root -e 'SELECT 1' >/dev/null 2>&1 && break
    kill -0 "$PID" 2>/dev/null || { cat "$D/db.log" >&2; exit 1; }
    sleep .1
done
ROOT=(mariadb --no-defaults --protocol=socket --socket="$D/mysql.sock" -u root)
FACTS=$("${ROOT[@]}" -N -e 'SELECT @@skip_networking,@@datadir')
[ "$FACTS" = "$(printf '1\t%s/data/' "$D")" ] || { echo 'Wrong server; refusing to run' >&2; exit 3; }
"${ROOT[@]}" -e "CREATE DATABASE section_map_fixture CHARACTER SET utf8mb4;"
DB=("${ROOT[@]}" --batch --skip-column-names section_map_fixture)
"${DB[@]}" -e "CREATE TABLE academic_years (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB; CREATE TABLE classes (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB; INSERT INTO academic_years VALUES (1),(2); INSERT INTO classes VALUES (11),(12),(13);"
"${DB[@]}" < "$REPO/sql/096_school_academic_structure.sql"
"${DB[@]}" < "$REPO/sql/098_school_section_year_class_map.sql"
"${DB[@]}" < "$REPO/sql/098_school_section_year_class_map.sql"
check() { local got; got=$("${DB[@]}" -e "$1"); [ "$got" = "$2" ] || { printf 'Expected %s, got %s\n' "$2" "$got" >&2; exit 1; }; }
refuse() { if "${DB[@]}" -e "$1" >/dev/null 2>&1; then echo "Unexpected SQL success: $1" >&2; exit 1; fi; }
check 'SELECT COUNT(*) FROM school_section_year_class_map' 0
check "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='school_section_year_class_map' AND ENGINE='InnoDB'" 1
"${DB[@]}" -e "INSERT INTO school_education_levels(id,name) VALUES (1,'Primary'); INSERT INTO school_grades(id,level_id,name) VALUES (1,1,'Grade 1'); INSERT INTO school_grade_sections(id,grade_id,name) VALUES (101,1,'A'),(102,1,'B'); INSERT INTO school_section_year_class_map(academic_year_id,section_id,class_id) VALUES (1,101,11),(2,101,11);"
check 'SELECT COUNT(*) FROM school_section_year_class_map' 2
refuse 'INSERT INTO school_section_year_class_map(academic_year_id,section_id,class_id) VALUES(1,101,12)'
refuse 'INSERT INTO school_section_year_class_map(academic_year_id,section_id,class_id) VALUES(1,102,11)'
refuse 'INSERT INTO school_section_year_class_map(academic_year_id,section_id,class_id) VALUES(99,102,12)'
refuse 'INSERT INTO school_section_year_class_map(academic_year_id,section_id,class_id) VALUES(1,999,12)'
refuse 'INSERT INTO school_section_year_class_map(academic_year_id,section_id,class_id) VALUES(1,102,999)'
refuse 'DELETE FROM academic_years WHERE id=1'
refuse 'DELETE FROM classes WHERE id=11'
refuse 'DELETE FROM school_grade_sections WHERE id=101'
"${DB[@]}" -e 'START TRANSACTION; INSERT INTO school_section_year_class_map(academic_year_id,section_id,class_id) VALUES(1,102,12); ROLLBACK;'
check 'SELECT COUNT(*) FROM school_section_year_class_map' 2
"${DB[@]}" < "$REPO/sql/098_school_section_year_class_map.sql"
check 'SELECT COUNT(*) FROM school_section_year_class_map' 2
echo 'PASS: empty/repeat schema, one-to-one per year, cross-year reuse, FK restrictions, rollback'
