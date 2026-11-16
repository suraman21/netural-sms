<?php
// Run only against network-disabled synthetic fixture.
require __DIR__ . '/../../admin/backend/services/SchoolRegistrarActor.php';
use App\Services\SchoolRegistrarActor as Actor;
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$socket=getenv('SMS_SECTION_MAP_FIXTURE_SOCKET');
if (!$socket || !preg_match('#^/tmp/sms-section-map\.[A-Za-z0-9]+/mysql\.sock$#D',$socket)) throw new RuntimeException('Private fixture required.');
function db(string $socket): mysqli {
    $db=new mysqli('localhost','root','','section_map_fixture',0,$socket);
    $s=$db->query('SELECT @@skip_networking disabled,DATABASE() name')->fetch_assoc();
    if ((int)$s['disabled']!==1 || $s['name']!=='section_map_fixture') throw new RuntimeException('Wrong fixture');
    return $db;
}
$n=0;
function check(bool $ok): void {global $n;$n++;if(!$ok)throw new RuntimeException('Check '.$n);}
function bad(callable $f,string $type): void {try{$f();}catch(Throwable $e){check($e instanceof $type);return;}throw new RuntimeException('Expected '.$type);}
$pass=hash('sha256','fixture-hash');$actor=Actor::fromTrustedSession(42,'school_registrar',$pass,'1');
check($actor->id()===42);
foreach (['registrar','hr_dept','edu_dept','super_admin','school_admin',''] as $role) bad(fn()=>Actor::fromTrustedSession(42,$role,$pass,'1'),DomainException::class);
foreach ([0,-1,4294967296] as $id) bad(fn()=>Actor::fromTrustedSession($id,'school_registrar',$pass,'1'),DomainException::class);
bad(fn()=>Actor::fromTrustedSession(42,'school_registrar','invalid','1'),DomainException::class);
foreach (['0','01','','invalid'] as $v) bad(fn()=>Actor::fromTrustedSession(42,'school_registrar',$pass,$v),DomainException::class);
$c=db($socket);$other=db($socket);bad(fn()=>$actor->lockAndRevalidate($c),RuntimeException::class);
$c->begin_transaction();$actor->lockAndRevalidate($c);check(true);
$other->query('SET SESSION innodb_lock_wait_timeout=1');
try {$other->query('UPDATE users SET is_active=0 WHERE id=42');throw new RuntimeException('User was not locked');}
catch(mysqli_sql_exception $e) {check((int)$e->getCode()===1205);}
$c->rollback();
foreach (["is_active=0","role='hr_dept'","password_hash='changed'","authorization_version=2"] as $change) {
    $other->query("UPDATE users SET $change WHERE id=42");
    try {$c->begin_transaction();bad(fn()=>$actor->lockAndRevalidate($c),DomainException::class);$c->rollback();}
    finally {$other->query("UPDATE users SET is_active=1,role='school_registrar',password_hash='fixture-hash',authorization_version=1 WHERE id=42");}
}
$missing=Actor::fromTrustedSession(999,'school_registrar',$pass,'1');$c->begin_transaction();bad(fn()=>$missing->lockAndRevalidate($c),DomainException::class);$c->rollback();
$c->begin_transaction();$actor->lockAndRevalidate($c);check(true);$c->rollback();
$c->close();$other->close();echo "PASS: $n Registrar actor checks\n";
