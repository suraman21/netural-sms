#!/usr/bin/env bash
# Schema-only test for the institution-level foundation (sql/103, ADR-0025).
# Fresh private socket-only MariaDB under the caller's temp dir; never touches
# an existing database or application configuration.
set -euo pipefail
PATH="$PATH:/usr/sbin:/sbin"
REPO="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
for tool in mariadb-install-db mariadbd mariadb mktemp; do
  command -v "$tool" >/dev/null || { echo "Missing required tool: $tool" >&2; exit 2; }
done
BASE="${SSMS_FIXTURE_BASE:-/tmp}"
D=$(mktemp -d "$BASE/institution-levels-db.XXXXXXXX")
SOCKET="$D/mysql.sock"
PID=''
cleanup() {
  if [ -n "$PID" ]; then kill "$PID" 2>/dev/null || true; wait "$PID" 2>/dev/null || true; fi
  case "$D" in "$BASE"/institution-levels-db.[a-zA-Z0-9]*) rm -rf -- "$D";; *) echo 'Refusing unsafe cleanup' >&2;; esac
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
"${ROOT[@]}" -e "CREATE DATABASE sms_institution_test;"
DB=(mariadb --no-defaults --protocol=socket --socket="$SOCKET" -u root sms_institution_test)
check() { local got; got=$("${DB[@]}" -N -B -e "$1"); [ "$got" = "$2" ] || { printf 'Expected %s, got %s\n' "$2" "$got" >&2; exit 1; }; }

# The hierarchy 103 extends must exist first; 096 is the dormant foundation.
"${DB[@]}" < "$REPO/sql/096_school_academic_structure.sql"
"${DB[@]}" < "$REPO/sql/103_institution_levels_and_exam_gates.sql"
echo "PASS: first apply"

# Idempotent re-apply (hand-applied migrations must be re-runnable, ADR-0004).
"${DB[@]}" < "$REPO/sql/103_institution_levels_and_exam_gates.sql"
echo "PASS: second apply is idempotent"

# I1, and the invariant tests/security/test_school_structure_read.py pins: a
# fresh installation has NO academic structure. The migration must not invent a
# curriculum for an institution that has not declared one.
check "SELECT COUNT(*) FROM school_education_levels" "0"
check "SELECT COUNT(*) FROM school_grades" "0"
check "SELECT COUNT(*) FROM school_exam_gates" "0"
echo "PASS: a fresh install stays empty (nothing is assumed present)"

# The ladder is opted into explicitly, as a seed and not a migration.
"${DB[@]}" < "$REPO/sql/seeds/ethiopian_ladder.sql"
echo "PASS: the Ethiopian ladder is opt-in"
check "SELECT COUNT(*) FROM school_education_levels" "4"
check "SELECT GROUP_CONCAT(kind ORDER BY sort_order) FROM school_education_levels" "kg,primary,middle,secondary"
check "SELECT COUNT(*) FROM school_grades" "15"
check "SELECT COUNT(*) FROM school_grades g JOIN school_education_levels l ON l.id=g.level_id WHERE l.kind='kg'" "3"
check "SELECT COUNT(*) FROM school_grades g JOIN school_education_levels l ON l.id=g.level_id WHERE l.kind='primary'" "6"
check "SELECT COUNT(*) FROM school_grades g JOIN school_education_levels l ON l.id=g.level_id WHERE l.kind='middle'" "2"
check "SELECT COUNT(*) FROM school_grades g JOIN school_education_levels l ON l.id=g.level_id WHERE l.kind='secondary'" "4"
echo "PASS: Ethiopian ladder (KG 1-3, Grade 1-6, 7-8, 9-12)"

# The seed is idempotent as well: running it again changes nothing.
"${DB[@]}" < "$REPO/sql/seeds/ethiopian_ladder.sql"
check "SELECT COUNT(*) FROM school_education_levels" "4"
check "SELECT COUNT(*) FROM school_grades" "15"
echo "PASS: the seed is idempotent"

# National exam gates are recorded against the stage they gate (I3).
check "SELECT COUNT(*) FROM school_exam_gates" "3"
check "SELECT GROUP_CONCAT(g.name ORDER BY g.sort_order) FROM school_exam_gates e JOIN school_grades g ON g.id=e.grade_id" "Grade 6,Grade 8,Grade 12"
echo "PASS: national exam gates at grades 6, 8 and 12"

# A gate may not be attached to a stage that does not exist (FK RESTRICT).
if "${DB[@]}" -e "INSERT INTO school_exam_gates (grade_id,name) VALUES (999999,'orphan');" 2>/dev/null; then
  echo 'FAIL: exam gate accepted an unknown stage' >&2; exit 1
fi
echo "PASS: exam gate requires a real stage"

# Institutions configure their own levels (I1): a college-only installation is
# created by adding a college level and deleting the ladder it does not run,
# and a re-apply must NOT resurrect what was deleted.
"${DB[@]}" -e "INSERT INTO school_education_levels (name,kind,duration_months,award,sort_order)
               VALUES ('Diploma in Accounting','college',24,'Diploma',50);"
"${DB[@]}" -e "DELETE FROM school_exam_gates; DELETE FROM school_grades; DELETE FROM school_education_levels WHERE kind IN ('kg','primary','middle','secondary');"
"${DB[@]}" < "$REPO/sql/103_institution_levels_and_exam_gates.sql"
"${DB[@]}" < "$REPO/sql/seeds/ethiopian_ladder.sql"
check "SELECT COUNT(*) FROM school_education_levels" "1"
check "SELECT kind FROM school_education_levels" "college"
check "SELECT duration_months FROM school_education_levels" "24"
check "SELECT award FROM school_education_levels" "Diploma"
echo "PASS: a college-only institution survives a re-apply AND a seed without the ladder returning"

# A 3-month course is a level with a duration and no stages.
"${DB[@]}" -e "INSERT INTO school_education_levels (name,kind,duration_months,award,sort_order)
               VALUES ('Basic Computer Skills','course',3,'Certificate',60);"
check "SELECT COUNT(*) FROM school_education_levels WHERE kind='course' AND duration_months=3" "1"
echo "PASS: a 3-month course is expressible without inventing stages"

echo '{"status":"PASS","checks":21}'
