<?php
// Run only from the disposable, socket-only fixture runner.
require __DIR__ . '/../../admin/backend/services/SchoolStructureCreate.php';
use App\Services\SchoolStructureCreate as Create;
use App\Services\SchoolStructureCreateOutcomeUnknown;
use App\Services\SchoolStructureActor;
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$socket = getenv('SMS_STRUCTURE_FIXTURE_SOCKET');
if (!$socket || !str_contains($socket, '/academic-structure-db.')) throw new RuntimeException('Disposable fixture socket required.');
function connection(string $socket): mysqli {
    $db = new mysqli('localhost', 'academic_test', 'fixture-only', 'sms_academic_schema_test', 0, $socket);
    $db->set_charset('utf8mb4');
    $r = $db->query('SELECT @@skip_networking AS no_network, DATABASE() AS dbname')->fetch_assoc();
    if ($r['no_network'] != 1 || $r['dbname'] !== 'sms_academic_schema_test') throw new RuntimeException('Wrong fixture.');
    return $db;
}
$checks = 0;
function check(bool $value): void { global $checks; $checks++; if (!$value) throw new RuntimeException('Check failed: '.$checks); }
function fails(callable $fn, string $type): void {
    try { $fn(); } catch (Throwable $e) { check($e instanceof $type); return; }
    throw new RuntimeException('Expected failure: '.$type);
}
$db = connection($socket);
$fingerprint = hash('sha256', 'fixture-hash');
$actor = SchoolStructureActor::fromTrustedSession(42, 'edu_dept', $fingerprint, '1');
foreach (['teacher', 'finance_dept', 'hr_dept', ''] as $role) fails(fn()=>SchoolStructureActor::fromTrustedSession(42, $role, $fingerprint, '1'), DomainException::class);
fails(fn()=>SchoolStructureActor::fromTrustedSession(0, 'super_admin', $fingerprint, '1'), DomainException::class);
check(SchoolStructureActor::fromTrustedSession(7, 'school_admin', $fingerprint, '1')->id() === 7);
check(SchoolStructureActor::fromTrustedSession(8, 'super_admin', $fingerprint, '1')->role() === 'super_admin');
fails(fn()=>SchoolStructureActor::fromTrustedSession(42, 'edu_dept', '', '1'), DomainException::class);
fails(fn()=>SchoolStructureActor::fromTrustedSession(42, 'edu_dept', $fingerprint, ''), DomainException::class);
fails(fn()=>SchoolStructureActor::fromTrustedSession(42, 'edu_dept', $fingerprint, '01'), DomainException::class);
$db->query("UPDATE users SET authorization_version=2 WHERE id=42");
fails(fn()=>Create::level($db, $actor, 'revoked version', 1), DomainException::class);
check((int)$db->query("SELECT COUNT(*) n FROM school_education_levels WHERE name='revoked version'")->fetch_assoc()['n'] === 0);
$db->query("UPDATE users SET authorization_version=1 WHERE id=42");
$db->query("UPDATE users SET is_active=0 WHERE id=42");
fails(fn()=>Create::level($db, $actor, 'disabled actor', 1), DomainException::class);
$db->query("UPDATE users SET is_active=1, role='finance_dept' WHERE id=42");
fails(fn()=>Create::level($db, $actor, 'changed role', 1), DomainException::class);
$db->query("UPDATE users SET role='edu_dept', password_hash='changed' WHERE id=42");
fails(fn()=>Create::level($db, $actor, 'changed password', 1), DomainException::class);
$db->query("UPDATE users SET password_hash='fixture-hash' WHERE id=42");
$missing = SchoolStructureActor::fromTrustedSession(999, 'edu_dept', $fingerprint, '1');
fails(fn()=>Create::level($db, $missing, 'missing user', 1), DomainException::class);
check((int)$db->query("SELECT COUNT(*) n FROM school_education_levels WHERE name IN ('disabled actor','changed role','changed password','missing user')")->fetch_assoc()['n'] === 0);
check((int)$db->query('SELECT COUNT(*) n FROM school_academic_structure_audit')->fetch_assoc()['n'] === 0);
$db->query('RENAME TABLE users TO fixture_users_hidden');
try {
    fails(fn()=>Create::level($db, $actor, 'user table missing', 1), mysqli_sql_exception::class);
    check((int)$db->query("SELECT COUNT(*) n FROM school_education_levels WHERE name='user table missing'")->fetch_assoc()['n'] === 0);
} finally { $db->query('RENAME TABLE fixture_users_hidden TO users'); }
$l = Create::level($db, $actor, '  የመጀመሪያ  ', 2);
$g = Create::grade($db, $actor, $l, 'Grade One', 3);
$s = Create::section($db, $actor, $g, 'A', 1);
check($l > 0 && $g > 0 && $s > 0);
$audit = $db->query("SELECT entity_kind,entity_id,parent_id,created_name,created_sort_order,actor_user_id,actor_role FROM school_academic_structure_audit ORDER BY id")->fetch_all(MYSQLI_ASSOC);
check(count($audit) === 3);
check($audit[0]['entity_kind'] === 'level' && $audit[0]['parent_id'] === null && (int)$audit[0]['entity_id'] === $l && $audit[0]['created_name'] === 'የመጀመሪያ' && (int)$audit[0]['actor_user_id'] === 42 && $audit[0]['actor_role'] === 'edu_dept');
check($audit[1]['entity_kind'] === 'grade' && (int)$audit[1]['parent_id'] === $l && (int)$audit[1]['entity_id'] === $g);
check($audit[2]['entity_kind'] === 'section' && (int)$audit[2]['parent_id'] === $g && (int)$audit[2]['entity_id'] === $s);
$r = $db->query("SELECT l.name,g.name AS grade,s.name AS section FROM school_education_levels l JOIN school_grades g ON g.level_id=l.id JOIN school_grade_sections s ON s.grade_id=g.id WHERE s.id=$s")->fetch_assoc();
check($r === ['name'=>'የመጀመሪያ','grade'=>'Grade One','section'=>'A']);
fails(fn()=>Create::section($db, $actor, $g, 'a', 2), mysqli_sql_exception::class);
check((int)$db->query("SELECT COUNT(*) AS n FROM school_grade_sections WHERE grade_id=$g")->fetch_assoc()['n'] === 1);
$other = Create::grade($db, $actor, $l, 'Grade Two', 4);
check(Create::section($db, $actor, $other, 'A', 1) > 0);
fails(fn()=>Create::level($db, $actor, "bad\nname", 1), InvalidArgumentException::class);
fails(fn()=>Create::grade($db, $actor, 99999999, 'Missing', 1), DomainException::class);
$db->query("UPDATE school_grades SET is_active=0 WHERE id=$g");
fails(fn()=>Create::section($db, $actor, $g, 'B', 1), DomainException::class);
$db->query("UPDATE school_education_levels SET is_active=0 WHERE id=$l");
fails(fn()=>Create::grade($db, $actor, $l, 'Grade Three', 1), DomainException::class);
fails(fn()=>Create::section($db, $actor, $other, 'B', 1), DomainException::class);
$db->begin_transaction();
$db->query("INSERT INTO school_education_levels (name) VALUES ('outer fixture')");
fails(fn()=>Create::level($db, $actor, 'must not start', 0), mysqli_sql_exception::class);
$db->rollback();
check((int)$db->query("SELECT COUNT(*) AS n FROM school_education_levels WHERE name IN ('outer fixture','must not start')")->fetch_assoc()['n'] === 0);
class LostCommit extends mysqli {
    public function commit(int $flags = 0, ?string $name = null): bool { parent::commit($flags, $name); return false; }
}
$failure = new LostCommit('localhost', 'academic_test', 'fixture-only', 'sms_academic_schema_test', 0, $socket);
$failure->set_charset('utf8mb4');
fails(fn()=>Create::level($failure, $actor, 'commit response lost', 0), SchoolStructureCreateOutcomeUnknown::class);
check((int)$db->query("SELECT COUNT(*) AS n FROM school_education_levels WHERE name='commit response lost'")->fetch_assoc()['n'] === 1);
check((int)$db->query("SELECT COUNT(*) AS n FROM school_academic_structure_audit WHERE created_name='commit response lost'")->fetch_assoc()['n'] === 1);
// An audit failure occurs after the entity INSERT and must roll it back.
$db->query("CREATE TRIGGER fixture_audit_failure BEFORE INSERT ON school_academic_structure_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected'");
try {
    fails(fn()=>Create::level($db, $actor, 'audit failure', 0), mysqli_sql_exception::class);
    check((int)$db->query("SELECT COUNT(*) AS n FROM school_education_levels WHERE name='audit failure'")->fetch_assoc()['n'] === 0);
} finally { $db->query('DROP TRIGGER fixture_audit_failure'); }
$db->query('RENAME TABLE school_academic_structure_audit TO fixture_audit_hidden');
try {
    fails(fn()=>Create::level($db, $actor, 'missing audit', 0), mysqli_sql_exception::class);
    check((int)$db->query("SELECT COUNT(*) AS n FROM school_education_levels WHERE name='missing audit'")->fetch_assoc()['n'] === 0);
} finally { $db->query('RENAME TABLE fixture_audit_hidden TO school_academic_structure_audit'); }
class LostRollback extends mysqli {
    public function rollback(int $flags = 0, ?string $name = null): bool { return false; }
}
$failure = new LostRollback('localhost', 'academic_test', 'fixture-only', 'sms_academic_schema_test', 0, $socket);
$failure->set_charset('utf8mb4');
fails(fn()=>Create::grade($failure, $actor, 99999999, 'rollback failure', 0), SchoolStructureCreateOutcomeUnknown::class);
// The fixture may alter its own schema, never an application database.
$db->query('RENAME TABLE school_grade_sections TO school_grade_sections_hidden');
try {
    fails(fn()=>Create::level($db, $actor, 'missing table', 0), mysqli_sql_exception::class);
    check((int)$db->query("SELECT COUNT(*) AS n FROM school_education_levels WHERE name='missing table'")->fetch_assoc()['n'] === 0);
} finally {
    $db->query('RENAME TABLE school_grade_sections_hidden TO school_grade_sections');
}
$db->close();
echo json_encode(['status'=>'PASS','checks'=>$checks])."\n";
