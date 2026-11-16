<?php
// Run only from the disposable, socket-only fixture runner.
require __DIR__ . '/../../admin/backend/services/SchoolStructureCreate.php';
use App\Services\SchoolStructureCreate as Create;
use App\Services\SchoolStructureCreateOutcomeUnknown;
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
$l = Create::level($db, '  የመጀመሪያ  ', 2);
$g = Create::grade($db, $l, 'Grade One', 3);
$s = Create::section($db, $g, 'A', 1);
check($l > 0 && $g > 0 && $s > 0);
$r = $db->query("SELECT l.name,g.name AS grade,s.name AS section FROM school_education_levels l JOIN school_grades g ON g.level_id=l.id JOIN school_grade_sections s ON s.grade_id=g.id WHERE s.id=$s")->fetch_assoc();
check($r === ['name'=>'የመጀመሪያ','grade'=>'Grade One','section'=>'A']);
fails(fn()=>Create::section($db, $g, 'a', 2), mysqli_sql_exception::class);
check((int)$db->query("SELECT COUNT(*) AS n FROM school_grade_sections WHERE grade_id=$g")->fetch_assoc()['n'] === 1);
$other = Create::grade($db, $l, 'Grade Two', 4);
check(Create::section($db, $other, 'A', 1) > 0);
fails(fn()=>Create::level($db, "bad\nname", 1), InvalidArgumentException::class);
fails(fn()=>Create::grade($db, 99999999, 'Missing', 1), DomainException::class);
$db->query("UPDATE school_grades SET is_active=0 WHERE id=$g");
fails(fn()=>Create::section($db, $g, 'B', 1), DomainException::class);
$db->query("UPDATE school_education_levels SET is_active=0 WHERE id=$l");
fails(fn()=>Create::grade($db, $l, 'Grade Three', 1), DomainException::class);
fails(fn()=>Create::section($db, $other, 'B', 1), DomainException::class);
$db->begin_transaction();
$db->query("INSERT INTO school_education_levels (name) VALUES ('outer fixture')");
fails(fn()=>Create::level($db, 'must not start', 0), mysqli_sql_exception::class);
$db->rollback();
check((int)$db->query("SELECT COUNT(*) AS n FROM school_education_levels WHERE name IN ('outer fixture','must not start')")->fetch_assoc()['n'] === 0);
class LostCommit extends mysqli {
    public function commit(int $flags = 0, ?string $name = null): bool { parent::commit($flags, $name); return false; }
}
$failure = new LostCommit('localhost', 'academic_test', 'fixture-only', 'sms_academic_schema_test', 0, $socket);
$failure->set_charset('utf8mb4');
fails(fn()=>Create::level($failure, 'commit response lost', 0), SchoolStructureCreateOutcomeUnknown::class);
check((int)$db->query("SELECT COUNT(*) AS n FROM school_education_levels WHERE name='commit response lost'")->fetch_assoc()['n'] === 1);
class LostRollback extends mysqli {
    public function rollback(int $flags = 0, ?string $name = null): bool { return false; }
}
$failure = new LostRollback('localhost', 'academic_test', 'fixture-only', 'sms_academic_schema_test', 0, $socket);
$failure->set_charset('utf8mb4');
fails(fn()=>Create::grade($failure, 99999999, 'rollback failure', 0), SchoolStructureCreateOutcomeUnknown::class);
// The fixture may alter its own schema, never an application database.
$db->query('RENAME TABLE school_grade_sections TO school_grade_sections_hidden');
try {
    fails(fn()=>Create::level($db, 'missing table', 0), mysqli_sql_exception::class);
    check((int)$db->query("SELECT COUNT(*) AS n FROM school_education_levels WHERE name='missing table'")->fetch_assoc()['n'] === 0);
} finally {
    $db->query('RENAME TABLE school_grade_sections_hidden TO school_grade_sections');
}
$db->close();
echo json_encode(['status'=>'PASS','checks'=>$checks])."\n";
