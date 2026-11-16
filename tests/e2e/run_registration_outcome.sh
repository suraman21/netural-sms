#!/usr/bin/env bash
# DESTRUCTIVE FIXTURE, isolated copy and isolated disposable MariaDB only.
set -euo pipefail
PATH="$PATH:/usr/sbin:/sbin"
REPO=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
for x in mariadbd mariadb-install-db mariadb php python3 git; do command -v "$x" >/dev/null || { echo "Missing $x" >&2; exit 2; }; done
D=$(mktemp -d /tmp/sms-timetable.XXXXXXXX)
DBPID=''; WEBPID=''
cleanup() {
  [ -z "$WEBPID" ] || { kill "$WEBPID" 2>/dev/null || true; wait "$WEBPID" 2>/dev/null || true; }
  [ -z "$DBPID" ] || { kill "$DBPID" 2>/dev/null || true; wait "$DBPID" 2>/dev/null || true; }
  case "$D" in /tmp/sms-timetable.[a-zA-Z0-9]*) rm -rf -- "$D";; *) echo 'Refusing unsafe cleanup' >&2;; esac
}
trap cleanup EXIT INT TERM
mkdir -p "$D/project" "$D/bin" "$D/ini" "$D/sessions" "$D/ratelimit"
git -C "$REPO" archive HEAD | tar -x -C "$D/project"
cp "$REPO/admin/js/wbws-calendar.js" "$D/project/admin/js/wbws-calendar.js"
cp "$REPO/admin/api_education.php" "$D/project/admin/api_education.php"
for test in test_academic_term_calendar_error.py test_grade_route_role_gate.py test_school_structure_read.py test_school_structure_create_http.py test_enrollment_transaction_outcome_http.py test_member_import_v2.py test_school_calendar_display_safety.py test_school_calendar.py; do
  if [ -f "$REPO/tests/security/$test" ]; then cp "$REPO/tests/security/$test" "$D/project/tests/security/$test"; fi
done
cp "$REPO/api/v1/routes/grades.php" "$D/project/api/v1/routes/grades.php"
if [ -f "$REPO/admin/api_school_structure.php" ]; then cp "$REPO/admin/api_school_structure.php" "$D/project/admin/api_school_structure.php"; fi
cp "$REPO/admin/api_school_structure_create.php" "$D/project/admin/api_school_structure_create.php"
cp "$REPO/admin/backend/login.php" "$D/project/admin/backend/login.php"
cp "$REPO/admin/backend/services/SchoolStructureCreate.php" "$D/project/admin/backend/services/SchoolStructureCreate.php"
cp "$REPO/admin/backend/services/EnrollmentService.php" "$D/project/admin/backend/services/EnrollmentService.php"
cp "$REPO/admin/backend/services/MemberImportTransaction.php" "$D/project/admin/backend/services/MemberImportTransaction.php"
cp "$REPO/admin/api_import_members.php" "$D/project/admin/api_import_members.php"
cp "$REPO/admin/backend/services/TestMemberSeed.php" "$D/project/admin/backend/services/TestMemberSeed.php"
cp "$REPO/admin/hr_register_member.php" "$D/project/admin/hr_register_member.php"
cp "$REPO/admin/dashboards/hr-dept.php" "$D/project/admin/dashboards/hr-dept.php"
cp "$REPO/admin/dashboards/school_admin.php" "$D/project/admin/dashboards/school_admin.php"
cp "$REPO/api/v1/routes/enrollment.php" "$D/project/api/v1/routes/enrollment.php"
cp "$REPO/admin/school_structure.php" "$D/project/admin/school_structure.php"
cp "$REPO/admin/school_calendar.php" "$D/project/admin/school_calendar.php"
cp "$REPO/admin/js/school_calendar.js" "$D/project/admin/js/school_calendar.js"
cp "$REPO/admin/js/school_structure.js" "$D/project/admin/js/school_structure.js"
for migration in 095_school_calendar_configuration.sql 096_school_academic_structure.sql 097_school_academic_structure_audit.sql; do
  if [ -f "$REPO/sql/$migration" ]; then cp "$REPO/sql/$migration" "$D/project/sql/$migration"; fi
done
# The archived copy has its own parent config, which takes precedence.
cat > "$D/.fkss_env.php" <<'PHP'
<?php
define('DB_HOST','localhost'); define('DB_NAME','ssms_test');
define('DB_USER','fixture_only'); define('DB_PASS','fixture_only_password');
define('JWT_SECRET',str_repeat('a',64)); define('BACKUP_KEY',str_repeat('b',64));
define('HEALTH_KEY',str_repeat('c',64)); define('MONITOR_SECRET_KEY',str_repeat('d',64));
define('TELEMETRY_HASH_SECRET',str_repeat('e',64));
define('FEATURE_ATTENDANCE_V2', true);
PHP
chmod 600 "$D/.fkss_env.php"
SOCKET="$D/mysql.sock"
mariadb-install-db --no-defaults --datadir="$D/data" --auth-root-authentication-method=normal --skip-test-db > "$D/init.log" 2>&1
mariadbd --no-defaults --datadir="$D/data" --socket="$SOCKET" --pid-file="$D/db.pid" --skip-networking --log-error="$D/db.log" & DBPID=$!
for _ in $(seq 1 100); do [ -S "$SOCKET" ] && mariadb --no-defaults --protocol=socket --socket="$SOCKET" -u root -e 'SELECT 1' >/dev/null 2>&1 && break; kill -0 "$DBPID" 2>/dev/null || { cat "$D/db.log" >&2; exit 1; }; sleep .1; done
FACTS=$(mariadb --no-defaults --protocol=socket --socket="$SOCKET" -u root -N -e 'SELECT @@skip_networking,@@datadir')
[ "$FACTS" = "$(printf '1\t%s/data/' "$D")" ] || { echo 'Refusing unexpected server' >&2; exit 3; }
# Only intercept the exact helper command expected by provisioning and tests.
cat > "$D/bin/sudo" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
[ "${1:-}" = '-n' ] && [ "${2:-}" = 'mariadb' ] || { echo 'Refusing unexpected sudo command' >&2; exit 3; }
shift 2
[ -S "$SMS_DISPOSABLE_SOCKET" ] || exit 3
exec /usr/bin/mariadb --no-defaults --protocol=socket --socket="$SMS_DISPOSABLE_SOCKET" -u root "$@"
SH
chmod 700 "$D/bin/sudo"
printf 'mysqli.default_socket=%s\npdo_mysql.default_socket=%s\nsession.save_path=%s\n' "$SOCKET" "$SOCKET" "$D/sessions" > "$D/ini/fixture.ini"
export SMS_DISPOSABLE_SOCKET="$SOCKET" PATH="$D/bin:$PATH" PHP_INI_SCAN_DIR=":$D/ini" SSMS_AUDIT_TESTING=1
# Avoid the upstream script's global scratch paths in this copied checkout.
python3 - "$D/project/tests/e2e/provision_env.sh" "$D" <<'PY'
import pathlib, sys
p = pathlib.Path(sys.argv[1]); s = p.read_text(); d = sys.argv[2]
s = s.replace('/tmp/prov_e', d + '/prov_e').replace('/tmp/legacy_tables.sql', d + '/legacy_tables.sql')
p.write_text(s)
# Test helper normally clears a global scratch directory; scope it to the disposable fixture.
p = pathlib.Path(sys.argv[1]).parent.parent / "security/test_member_import_v2.py"
s = p.read_text().replace("/tmp/ssms_ratelimit", d + "/ratelimit")
p.write_text(s)
PY
cd "$D/project"
bash tests/e2e/provision_env.sh > "$D/provision.log" 2>&1 || { cat "$D/provision.log" >&2; exit 1; }
# The upstream script can continue after failures. Preserve visibility of them.
echo 'Provisioning warnings:'
grep -E 'known-stale|index-only residue|Error|ERROR|Fatal' "$D/provision.log" || true
if grep -Eq 'known-stale, patched below\): sql/09[56]_' "$D/provision.log"; then echo 'New foundation migration failed' >&2; exit 1; fi
if [ -f sql/095_school_calendar_configuration.sql ]; then
  MODE=$(sudo -n mariadb -N -B ssms_test -e "SELECT CONCAT(id,':',IFNULL(mode,'unconfigured'),':',revision) FROM school_calendar_configuration")
  [ "$MODE" = '1:unconfigured:0' ] || { echo "Unexpected calendar bootstrap: $MODE" >&2; exit 1; }
  echo 'Calendar 095 present and unconfigured after legacy test provisioning'
fi
if [ -f sql/096_school_academic_structure.sql ]; then
  N=$(sudo -n mariadb -N -B ssms_test -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('school_education_levels','school_grades','school_grade_sections') AND engine='InnoDB'")
  [ "$N" = '3' ] || { echo 'Academic structure tables missing' >&2; exit 1; }
  echo 'Academic hierarchy 096 present after legacy test provisioning'
fi
PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
php -d error_reporting=24575 -S "127.0.0.1:$PORT" -t "$D/project" > "$D/web.log" 2>&1 & WEBPID=$!
export SSMS_TEST_BASE="http://127.0.0.1:$PORT" SSMS_STRUCTURE_FIXTURE=1 SSMS_TEST_HR_USER=fixture_hr
for _ in $(seq 1 100); do python3 -c 'import urllib.request,os; urllib.request.urlopen(os.environ["SSMS_TEST_BASE"]+"/admin/index.php",timeout=1).read()' >/dev/null 2>&1 && break; kill -0 "$WEBPID" 2>/dev/null || { cat "$D/web.log" >&2; exit 1; }; sleep .1; done
# Fault injection exists only in this disposable copied service.
python3 - "$D/project/admin/backend/services/MemberImportTransaction.php" <<'INJECT'
import pathlib,sys
p=pathlib.Path(sys.argv[1]); s=p.read_text()
old=r"if (!$this->db->commit()) throw new \RuntimeException('Commit not confirmed.');"
new=old+"\n"+r"        if (($_POST['fixture_commit_response_loss'] ?? '') === '1') throw new \RuntimeException('Fixture lost commit response');"
assert s.count(old)==1
s=s.replace(old,new)
old=r"if (!$this->db->rollback()) throw new \RuntimeException('Rollback not confirmed.');"
assert s.count(old)==1
s=s.replace(old, old+"\n"+r"                if (($_POST['fixture_rollback_response_loss'] ?? '') === '1') throw new \RuntimeException('Fixture lost rollback response');")
p.write_text(s)
INJECT
python3 - "$D/project/admin/hr_register_member.php" <<'INJECT'
import pathlib,sys
p=pathlib.Path(sys.argv[1]); s=p.read_text()
old='$transaction->start();'
assert s.count(old)==1
s=s.replace(old, old+"\n    if (($_POST['fixture_before_write_failure'] ?? '') === '1') throw new RuntimeException('Fixture pre-write failure');")
old='$transaction->commit();'
assert s.count(old)==1
s=s.replace(old, "if (($_POST['fixture_after_write_failure'] ?? '') === '1') throw new RuntimeException('Fixture post-write failure');\n    "+old)
p.write_text(s)
INJECT
cp "$REPO/tests/security/test_registration_upload_outcome_http.py" "$REPO/tests/security/test_registration_response_loss_ui.py" "$D/project/tests/security/"
export SSMS_REGISTRATION_FAULT_FIXTURE=1
python3 -m pytest tests/security/test_registration_upload_outcome_http.py tests/security/test_registration_response_loss_ui.py -q -rs > "$D/regtest.log" 2>&1 || { cat "$D/regtest.log"; tail -40 "$D/web.log"; exit 1; }
cat "$D/regtest.log"
if grep -Eq '[0-9]+ skipped' "$D/regtest.log"; then exit 1; fi
# Fail if the live-test class was not executed, rather than accepting skips.
python3 -m pytest tests/security/test_timetable_builder.py tests/security/test_academic_term_calendar_error.py tests/security/test_academic_year_admin_fix.py tests/security/test_grade_route_role_gate.py tests/security/test_school_structure_read.py tests/security/test_school_structure_create_http.py tests/security/test_enrollment_transaction_outcome_http.py tests/security/test_member_import_v2.py tests/security/test_school_calendar.py tests/security/test_school_calendar_display_safety.py -q -rs -W error::pytest.PytestWarning > "$D/test.log" 2>&1 || { cat "$D/test.log"; tail -30 "$D/web.log"; exit 1; }
cat "$D/test.log"
if grep -Eq '[0-9]+ skipped' "$D/test.log"; then echo 'Refusing to treat skipped live checks as a pass' >&2; exit 1; fi
