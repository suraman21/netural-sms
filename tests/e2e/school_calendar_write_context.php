<?php
// Only network-disabled disposable fixture. Never connected to real schools.
require __DIR__ . '/../../admin/backend/services/SchoolCalendarWriteContext.php';
use App\Services\SchoolCalendarWriteContext as Context;
use App\Services\SchoolCalendarRevisionMismatch;
use App\Services\SchoolCalendarNotConfigured;
use App\Services\SchoolCalendarMode;
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$socket=getenv('SMS_SECTION_MAP_FIXTURE_SOCKET');
if (!$socket || !preg_match('#^/tmp/sms-section-map\.[A-Za-z0-9]+/mysql\.sock$#D',$socket)) throw new RuntimeException('Private fixture required.');
function db(string $socket): mysqli {$db=new mysqli('localhost','root','','section_map_fixture',0,$socket);$s=$db->query('SELECT @@skip_networking disabled,DATABASE() name')->fetch_assoc();if((int)$s['disabled']!==1||$s['name']!=='section_map_fixture')throw new RuntimeException('Wrong fixture');return $db;}
$n=0;
function check(bool $ok): void {global $n;$n++;if(!$ok)throw new RuntimeException('Check '.$n);}
function bad(callable $f,string $type): void {try{$f();}catch(Throwable $e){check($e instanceof $type);return;}throw new RuntimeException('Expected '.$type);}
$c=db($socket);$other=db($socket);
foreach ([null,'','0','01','-1',1,'a','123456789012345678901'] as $v) bad(fn()=>Context::lockForRevision($c,$v),InvalidArgumentException::class);
bad(fn()=>Context::lockForRevision($c,'1'),RuntimeException::class);
$c->begin_transaction();bad(fn()=>Context::lockForRevision($c,'1'),SchoolCalendarNotConfigured::class);$c->rollback();
$other->query("UPDATE school_calendar_configuration SET mode='ethiopian',revision=1 WHERE id=1");
$c->begin_transaction();$r=Context::lockForRevision($c,'1');check($r['mode']===SchoolCalendarMode::Ethiopian && $r['revision']==='1');
$other->query('SET SESSION innodb_lock_wait_timeout=1');
try {$other->query("UPDATE school_calendar_configuration SET mode='gregorian',revision=2 WHERE id=1");throw new RuntimeException('Calendar was not locked');}
catch(mysqli_sql_exception $e){check((int)$e->getCode()===1205);}
$c->rollback();$other->query("UPDATE school_calendar_configuration SET mode='gregorian',revision=2 WHERE id=1");
$c->begin_transaction();bad(fn()=>Context::lockForRevision($c,'1'),SchoolCalendarRevisionMismatch::class);$c->rollback();
$c->begin_transaction();$r=Context::lockForRevision($c,'2');check($r['mode']===SchoolCalendarMode::Gregorian && $r['revision']==='2');$c->rollback();
$other->query('RENAME TABLE school_calendar_configuration TO fixture_calendar_hidden');
try {$c->begin_transaction();bad(fn()=>Context::lockForRevision($c,'2'),mysqli_sql_exception::class);$c->rollback();}
finally {$other->query('RENAME TABLE fixture_calendar_hidden TO school_calendar_configuration');}
$other->query('UPDATE school_calendar_configuration SET mode=NULL,revision=0 WHERE id=1');
$c->close();$other->close();echo "PASS: $n calendar write context checks\n";
