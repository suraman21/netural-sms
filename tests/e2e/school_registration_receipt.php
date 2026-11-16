<?php
// Private socket-only fixture. Not a user-facing registration endpoint.
require __DIR__ . '/../../admin/backend/services/SchoolRegistrationReceipt.php';
use App\Services\SchoolRegistrationReceipt as R;
use App\Services\SchoolRegistrationReceiptConflict;
use App\Services\SchoolRegistrationReceiptNeedsReview;
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$socket=getenv('SMS_SECTION_MAP_FIXTURE_SOCKET');
if (!$socket || !preg_match('#^/tmp/sms-section-map\.[A-Za-z0-9]+/mysql\.sock$#D',$socket)) throw new RuntimeException('Private fixture required.');
class ReceiptCommitLost extends mysqli {public function commit(int $flags=0,?string $name=null): bool {parent::commit($flags,$name);throw new RuntimeException('Synthetic lost commit response');}}
function db(string $socket,string $class=mysqli::class): mysqli {
    $db=new $class('localhost','root','','section_map_fixture',0,$socket);
    $state=$db->query('SELECT @@skip_networking disabled, DATABASE() name')->fetch_assoc();
    if ((int)$state['disabled']!==1 || $state['name']!=='section_map_fixture') throw new RuntimeException('Wrong server');
    return $db;
}
$n=0;
function check(bool $ok): void {global $n;$n++;if(!$ok)throw new RuntimeException('Check '.$n.' failed');}
function bad(callable $f,string $type): void {try{$f();}catch(Throwable $e){check($e instanceof $type);return;}throw new RuntimeException('Expected '.$type);}
$c=db($socket);$other=db($socket);$hash=hash('sha256','fixture request');$different=hash('sha256','other request');$key='fixture-operation-000001';
$enrollment=(int)$c->query('SELECT id FROM class_enrollments WHERE member_id=778 LIMIT 1')->fetch_assoc()['id'];
bad(fn()=>R::claim($c,42,$key,$hash),RuntimeException::class);
bad(fn()=>R::claim($c,'42',$key,$hash),InvalidArgumentException::class);
bad(fn()=>R::claim($c,42,'short',$hash),InvalidArgumentException::class);
bad(fn()=>R::claim($c,42,$key,'bad'),InvalidArgumentException::class);
$c->begin_transaction();$a=R::claim($c,42,$key,$hash);check($a['state']==='acquired' && strlen($a['owner_token'])===64);
bad(fn()=>R::complete($c,42,$key,$hash,$a['owner_token'],777,$enrollment),DomainException::class);
bad(fn()=>R::complete($c,42,$key,$hash,str_repeat('a',64),778,$enrollment),RuntimeException::class);
R::complete($c,42,$key,$hash,$a['owner_token'],778,$enrollment);
check((int)$other->query('SELECT COUNT(*) n FROM school_registration_receipts')->fetch_assoc()['n']===0);
$c->commit();
$c->begin_transaction();$r=R::claim($c,42,$key,$hash);check($r===['state'=>'replay','member_id'=>778,'enrollment_id'=>$enrollment]);$c->rollback();
$c->begin_transaction();bad(fn()=>R::claim($c,42,$key,$different),SchoolRegistrationReceiptConflict::class);$c->rollback();
$c->begin_transaction();$r=R::claim($c,43,$key,$hash);check($r['state']==='acquired');$c->rollback();
$c->begin_transaction();$r=R::claim($c,42,'fixture-rollback-0000001',$hash);check($r['state']==='acquired');$c->rollback();
$c->begin_transaction();$r=R::claim($c,42,'fixture-rollback-0000001',$hash);check($r['state']==='acquired');$c->rollback();
$c->begin_transaction();$r=R::claim($c,42,'fixture-incomplete-00001',$hash);check($r['state']==='acquired');$c->commit();
$c->begin_transaction();bad(fn()=>R::claim($c,42,'fixture-incomplete-00001',$hash),SchoolRegistrationReceiptNeedsReview::class);$c->rollback();
// Two independent connections contend on the same unique request key.
$concurrentKey='fixture-concurrent-00001';
$c->begin_transaction();$r=R::claim($c,42,$concurrentKey,$hash);R::complete($c,42,$concurrentKey,$hash,$r['owner_token'],778,$enrollment);
$op=hash('sha256',"school-registration\0".'42'."\0".$concurrentKey);$ph=bin2hex(hex2bin($hash));$owner=bin2hex(random_bytes(32));
$other->begin_transaction();check($other->query("INSERT INTO school_registration_receipts(operation_hash,actor_user_id,request_hash,owner_token,receipt_state) VALUES(UNHEX('$op'),42,UNHEX('$ph'),UNHEX('$owner'),'processing')",MYSQLI_ASYNC));
$read=[$other];$err=[];$reject=[];check(mysqli_poll($read,$err,$reject,0,200000)===0);
$c->commit();$read=[$other];$err=[];$reject=[];check(mysqli_poll($read,$err,$reject,5)>0);
try {$other->reap_async_query();throw new RuntimeException('Competing receipt inserted');} catch(mysqli_sql_exception $e) {check((int)$e->getCode()===1062);}
$r=R::claim($other,42,$concurrentKey,$hash);check($r['state']==='replay');$other->rollback();
// Commit succeeds but the connection reports an exception: a fresh request
// observes the completed durable receipt rather than acquiring work again.
$lost=db($socket,ReceiptCommitLost::class);$lost->begin_transaction();$r=R::claim($lost,42,'fixture-lost-commit-0001',$hash);R::complete($lost,42,'fixture-lost-commit-0001',$hash,$r['owner_token'],778,$enrollment);
bad(fn()=>$lost->commit(),RuntimeException::class);$lost->close();
$c->begin_transaction();$r=R::claim($c,42,'fixture-lost-commit-0001',$hash);check($r['state']==='replay');$c->rollback();
$c->close();$other->close();echo "PASS: $n durable receipt checks\n";
