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
"${DB[@]}" -e "CREATE TABLE academic_years (id INT UNSIGNED PRIMARY KEY, status ENUM('upcoming','active','closed') NOT NULL, is_current TINYINT(1) NOT NULL) ENGINE=InnoDB; CREATE TABLE classes (id INT UNSIGNED PRIMARY KEY, is_active TINYINT(1) NOT NULL) ENGINE=InnoDB; INSERT INTO academic_years VALUES (1,'active',1),(2,'closed',0); INSERT INTO classes VALUES (11,1),(12,1),(13,1);"
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
# Relational proof only: real EnrollmentService stores legacy class and year,
# not the unrelated school section ID. These synthetic tables mirror relevant
# 013 keys, not the complete legacy application schema.
"${DB[@]}" -e "CREATE TABLE members (id INT UNSIGNED NOT NULL PRIMARY KEY, status VARCHAR(20) NOT NULL DEFAULT 'active') ENGINE=InnoDB; CREATE TABLE class_enrollments (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, member_id INT UNSIGNED NOT NULL, class_id INT UNSIGNED NOT NULL, academic_year_id INT UNSIGNED NOT NULL, enrolled_at DATE DEFAULT NULL, enrolled_by INT UNSIGNED DEFAULT NULL, status ENUM('active','withdrawn','completed','transferred') NOT NULL DEFAULT 'active', UNIQUE KEY unique_enrollment(member_id,class_id,academic_year_id), KEY class_id(class_id), KEY academic_year_id(academic_year_id)) ENGINE=InnoDB; INSERT INTO members(id) VALUES (777),(778); INSERT INTO class_enrollments(member_id,class_id,academic_year_id) VALUES(777,11,1);"
check "SELECT CONCAT(e.member_id,':',m.section_id,':',m.class_id,':',m.academic_year_id) FROM class_enrollments e JOIN school_section_year_class_map m ON m.class_id=e.class_id AND m.academic_year_id=e.academic_year_id WHERE e.member_id=777" '777:101:11:1'
check 'SELECT COUNT(*) FROM class_enrollments e JOIN school_grade_sections s ON s.id=e.class_id WHERE e.member_id=777' 0
check 'SELECT COUNT(*) FROM class_enrollments e JOIN school_section_year_class_map m ON m.class_id=e.class_id AND m.academic_year_id=e.academic_year_id WHERE e.member_id=777 AND m.academic_year_id=2' 0
"${DB[@]}" -e "START TRANSACTION; INSERT INTO class_enrollments(member_id,class_id,academic_year_id) VALUES (777,12,2); ROLLBACK;"
check 'SELECT COUNT(*) FROM class_enrollments WHERE member_id=777' 1
"${DB[@]}" < "$REPO/sql/098_school_section_year_class_map.sql"
check 'SELECT COUNT(*) FROM school_section_year_class_map' 2
echo 'PASS: empty/repeat schema, one-to-one per year, cross-year reuse, FK restrictions, rollback and synthetic enrollment join'
SMS_SECTION_MAP_FIXTURE_SOCKET="$D/mysql.sock" php "$REPO/tests/e2e/section_year_resolution.php"
"${DB[@]}" -e "CREATE TABLE users (id INT UNSIGNED NOT NULL PRIMARY KEY, role VARCHAR(50) NOT NULL, is_active TINYINT NOT NULL, password_hash VARCHAR(255) NOT NULL, authorization_version BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB; INSERT INTO users VALUES (42,'school_registrar',1,'fixture-hash',1),(43,'edu_dept',1,'fixture-hash',1);"
"${DB[@]}" < "$REPO/sql/099_school_registration_receipts.sql"
"${DB[@]}" < "$REPO/sql/099_school_registration_receipts.sql"
SMS_SECTION_MAP_FIXTURE_SOCKET="$D/mysql.sock" php "$REPO/tests/e2e/school_registration_receipt.php"
SMS_SECTION_MAP_FIXTURE_SOCKET="$D/mysql.sock" php "$REPO/tests/e2e/school_registrar_actor.php"
"${DB[@]}" < "$REPO/sql/095_school_calendar_configuration.sql"
"${DB[@]}" < "$REPO/sql/095_school_calendar_configuration.sql"
SMS_SECTION_MAP_FIXTURE_SOCKET="$D/mysql.sock" php "$REPO/tests/e2e/school_calendar_write_context.php"
