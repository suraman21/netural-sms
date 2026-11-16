<?php
// Only against the ephemeral, network-disabled fixture provisioned by the runner.
require __DIR__ . '/../../admin/backend/services/SchoolSectionYearResolver.php';
require __DIR__ . '/../../admin/backend/services/EnrollmentService.php';
use App\Services\SchoolSectionYearResolver as Resolver;
use App\Services\EnrollmentService;
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$socket=getenv('SMS_SECTION_MAP_FIXTURE_SOCKET');
if (!$socket || !preg_match('#^/tmp/sms-section-map\.[A-Za-z0-9]+/mysql\.sock$#D',$socket)) throw new RuntimeException('Private fixture required.');
function db(string $socket): mysqli {
    $db=new mysqli('localhost','root','','section_map_fixture',0,$socket);
    $state=$db->query('SELECT @@skip_networking AS disabled, DATABASE() AS name')->fetch_assoc();
    if ((int)$state['disabled']!==1 || $state['name']!=='section_map_fixture') throw new RuntimeException('Wrong server');
    return $db;
}
$n=0;
function check(bool $ok): void {global $n;$n++;if(!$ok)throw new RuntimeException('Check '.$n.' failed');}
function bad(callable $work,string $type): void {try{$work();}catch(Throwable $e){check($e instanceof $type);return;}throw new RuntimeException('Expected '.$type);}
$c=db($socket);
bad(fn()=>Resolver::resolveForUpdate($c,1,101),RuntimeException::class);
bad(fn()=>Resolver::resolveForUpdate($c,'1',101),InvalidArgumentException::class);
bad(fn()=>Resolver::resolveForUpdate($c,1,0),InvalidArgumentException::class);
$c->begin_transaction();
check(Resolver::resolveForUpdate($c,1,101)===11);
bad(fn()=>Resolver::resolveForUpdate($c,1,102),DomainException::class);
bad(fn()=>Resolver::resolveForUpdate($c,2,101),DomainException::class);
bad(fn()=>Resolver::resolveForUpdate($c,1,999),DomainException::class);
$other=db($socket); $other->query('SET SESSION innodb_lock_wait_timeout=1');
try { $other->query('UPDATE school_section_year_class_map SET class_id=12 WHERE academic_year_id=1 AND section_id=101'); throw new RuntimeException('Mapping was not locked'); }
catch (mysqli_sql_exception $e) { check((int)$e->getCode()===1205); }
$c->rollback();
check($other->query('UPDATE school_section_year_class_map SET class_id=class_id WHERE academic_year_id=1 AND section_id=101')===true);
foreach ([['school_education_levels',1],['school_grades',1],['school_grade_sections',101],['classes',11]] as [$table,$id]) {
    $other->query("UPDATE `$table` SET is_active=0 WHERE id=$id");
    try { $c->begin_transaction(); bad(fn()=>Resolver::resolveForUpdate($c,1,101),DomainException::class); $c->rollback(); }
    finally { $other->query("UPDATE `$table` SET is_active=1 WHERE id=$id"); }
}
$other->query("UPDATE academic_years SET is_current=0 WHERE id=1");
try { $c->begin_transaction(); bad(fn()=>Resolver::resolveForUpdate($c,1,101),DomainException::class); $c->rollback(); }
finally { $other->query("UPDATE academic_years SET is_current=1 WHERE id=1"); }
// A nontransactional temporary shadow must not masquerade as the real table.
$c->query('CREATE TEMPORARY TABLE classes (id INT UNSIGNED PRIMARY KEY,is_active TINYINT) ENGINE=MyISAM');
try { $c->begin_transaction(); bad(fn()=>Resolver::resolveForUpdate($c,1,101),RuntimeException::class); $c->rollback(); }
finally { $c->query('DROP TEMPORARY TABLE classes'); }
// Compose the resolver with the real EnrollmentService in a caller-owned
// transaction. The legacy global workflow hook is deliberately absent here.
$c->begin_transaction();
$classId=Resolver::resolveForUpdate($c,1,101);
$enrolled=EnrollmentService::enroll($c,778,$classId,1,42,true);
check(($enrolled['status']??'')==='success');
check((int)$c->query('SELECT COUNT(*) n FROM class_enrollments WHERE member_id=778 AND class_id=11 AND academic_year_id=1')->fetch_assoc()['n']===1);
check((int)$other->query('SELECT COUNT(*) n FROM class_enrollments WHERE member_id=778')->fetch_assoc()['n']===0);
$c->rollback();
check((int)$other->query('SELECT COUNT(*) n FROM class_enrollments WHERE member_id=778')->fetch_assoc()['n']===0);
$c->begin_transaction();
$classId=Resolver::resolveForUpdate($c,1,101);
$enrolled=EnrollmentService::enroll($c,778,$classId,1,42,true);
check(($enrolled['status']??'')==='success');
$c->commit();
check((int)$other->query('SELECT COUNT(*) n FROM class_enrollments WHERE member_id=778 AND class_id=11 AND academic_year_id=1')->fetch_assoc()['n']===1);
$c->close();$other->close();
echo "PASS: $n transactional resolution checks\n";
