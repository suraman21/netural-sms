<?php
// Owned transaction fault injection. Private disposable socket-only fixture only.
require __DIR__ . '/../../admin/backend/services/EnrollmentService.php';
require __DIR__ . '/../../admin/backend/services/MemberImportTransaction.php';
use App\Services\MemberImportTransaction;
use App\Services\EnrollmentService;
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$socket = getenv('SMS_STRUCTURE_FIXTURE_SOCKET');
if (!$socket || !str_contains($socket, '/academic-structure-db.')) throw new RuntimeException('Private fixture required.');
class CommitFalse extends mysqli { public function commit(int $flags=0, ?string $name=null): bool { parent::commit($flags,$name); return false; } }
class CommitThrows extends mysqli { public function commit(int $flags=0, ?string $name=null): bool { parent::commit($flags,$name); throw new RuntimeException('synthetic response loss'); } }
class RollbackFalse extends mysqli { public function rollback(int $flags=0, ?string $name=null): bool { return false; } }
class BeginFalse extends mysqli { public function begin_transaction(int $flags=0, ?string $name=null): bool { return false; } }
class SavepointFailure extends mysqli {
    public string $failPrefix = '';
    public function query(string $query, int $result_mode=MYSQLI_STORE_RESULT): mysqli_result|bool {
        if ($this->failPrefix !== '' && str_starts_with($query, $this->failPrefix)) return false;
        return parent::query($query, $result_mode);
    }
}
function connectFixture(string $socket, string $class = mysqli::class): mysqli {
    $db = new $class('localhost', 'academic_test', 'fixture-only', 'sms_academic_schema_test', 0, $socket);
    $r = $db->query('SELECT @@skip_networking AS offnet, DATABASE() AS dbname')->fetch_assoc();
    if ((int)$r['offnet'] !== 1 || $r['dbname'] !== 'sms_academic_schema_test') throw new RuntimeException('Wrong fixture.');
    return $db;
}
$checks=0;
function check(bool $ok): void { global $checks; $checks++; if (!$ok) throw new RuntimeException('Check '.$checks.' failed.'); }
function closed(mysqli $db): bool { try { $db->ping(); return false; } catch (Throwable $e) { return true; } }
function runScope(mysqli $db, callable $fn, bool $withinTransaction=false): array {
    $method = new ReflectionMethod(EnrollmentService::class, 'transactionScope');
    return $method->invoke(null, $db, $withinTransaction, $fn);
}
function insert(mysqli $db, string $value): void {
    $s=$db->prepare('INSERT INTO fixture_enrollment_outcome (name) VALUES (?)');
    $s->bind_param('s',$value); $s->execute(); $s->close();
}
$observe=connectFixture($socket);
$observe->query('CREATE TABLE fixture_enrollment_outcome (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL UNIQUE) ENGINE=InnoDB');
$db=connectFixture($socket);
$r=runScope($db, function() use ($db) { insert($db,'success'); return ['status'=>'success']; });
check($r['status']==='success');
check((int)$observe->query("SELECT COUNT(*) n FROM fixture_enrollment_outcome WHERE name='success'")->fetch_assoc()['n']===1);
$r=runScope($db, function() use ($db) { insert($db,'validation'); return ['status'=>'error','message'=>'invalid']; });
check($r['status']==='error' && $r['message']==='invalid' && empty($r['outcome_unknown']));
check((int)$observe->query("SELECT COUNT(*) n FROM fixture_enrollment_outcome WHERE name='validation'")->fetch_assoc()['n']===0);
$db->begin_transaction(); insert($db,'outer');
$r=runScope($db, fn()=>['status'=>'success']);
check($r['status']==='error' && empty($r['outcome_unknown']));
$db->rollback();
check((int)$observe->query("SELECT COUNT(*) n FROM fixture_enrollment_outcome WHERE name='outer'")->fetch_assoc()['n']===0);
$db->begin_transaction(); insert($db,'outer-kept');
$r=runScope($db, function() use ($db) { insert($db,'nested-failed'); return ['status'=>'error','message'=>'nested rejected']; }, true);
check($r['status']==='error' && $r['message']==='nested rejected');
check((int)$db->query("SELECT COUNT(*) n FROM fixture_enrollment_outcome WHERE name='outer-kept'")->fetch_assoc()['n']===1);
check((int)$db->query("SELECT COUNT(*) n FROM fixture_enrollment_outcome WHERE name='nested-failed'")->fetch_assoc()['n']===0);
$db->rollback();
check((int)$observe->query("SELECT COUNT(*) n FROM fixture_enrollment_outcome WHERE name='outer-kept'")->fetch_assoc()['n']===0);
$db->close();
foreach ([CommitFalse::class, CommitThrows::class] as $i=>$class) {
    $db=connectFixture($socket,$class); $name='commit-lost-'.$i;
    $r=runScope($db, function() use ($db,$name) { insert($db,$name); return ['status'=>'success']; });
    check($r['status']==='error' && !empty($r['outcome_unknown']) && str_contains($r['message'],'Check'));
    check(closed($db));
    check((int)$observe->query("SELECT COUNT(*) n FROM fixture_enrollment_outcome WHERE name='commit-lost-$i'")->fetch_assoc()['n']===1);
}
$db=connectFixture($socket,RollbackFalse::class);
$r=runScope($db, function() use ($db) { insert($db,'rollback-uncertain'); return ['status'=>'error']; });
check($r['status']==='error' && !empty($r['outcome_unknown'])); check(closed($db));
check((int)$observe->query("SELECT COUNT(*) n FROM fixture_enrollment_outcome WHERE name='rollback-uncertain'")->fetch_assoc()['n']===0);
$db=connectFixture($socket,BeginFalse::class);
$r=runScope($db, fn()=>['status'=>'success']);
check($r['status']==='error' && empty($r['outcome_unknown'])); check(closed($db));
// Savepoint setup, rollback and release failures must poison/discard the outer connection.
foreach (['SAVEPOINT ', 'ROLLBACK TO SAVEPOINT ', 'RELEASE SAVEPOINT '] as $prefix) {
    $db=connectFixture($socket, SavepointFailure::class); $db->begin_transaction();
    insert($db,'outer-savepoint'); $db->failPrefix=$prefix;
    $r=runScope($db, function() use ($db, $prefix) {
        insert($db,'inner-savepoint');
        return ['status'=>$prefix === 'RELEASE SAVEPOINT ' ? 'success' : 'error'];
    }, true);
    check($r['status']==='error' && !empty($r['outcome_unknown'])); check(closed($db));
    check((int)$observe->query("SELECT COUNT(*) n FROM fixture_enrollment_outcome WHERE name IN ('outer-savepoint','inner-savepoint')")->fetch_assoc()['n']===0);
}
// The import boundary checks commit/rollback instead of claiming false certainty.
$db=connectFixture($socket); $tx=new MemberImportTransaction($db); $tx->start(); insert($db,'import-ok'); $tx->commit();
check((int)$observe->query("SELECT COUNT(*) n FROM fixture_enrollment_outcome WHERE name='import-ok'")->fetch_assoc()['n']===1); $db->close();
$db=connectFixture($socket); $tx=new MemberImportTransaction($db); $tx->start(); insert($db,'import-rollback');
check($tx->unknownAfterFailure()===false);
check((int)$observe->query("SELECT COUNT(*) n FROM fixture_enrollment_outcome WHERE name='import-rollback'")->fetch_assoc()['n']===0); $db->close();
foreach ([CommitFalse::class, CommitThrows::class] as $i=>$class) {
    $db=connectFixture($socket,$class); $tx=new MemberImportTransaction($db); $tx->start(); $name='import-lost-'.$i; insert($db,$name);
    try { $tx->commit(); throw new RuntimeException('Commit unexpectedly succeeded.'); } catch (Throwable $e) { check($tx->unknownAfterFailure()===true); }
    check(closed($db));
    check((int)$observe->query("SELECT COUNT(*) n FROM fixture_enrollment_outcome WHERE name='import-lost-$i'")->fetch_assoc()['n']===1);
}
$db=connectFixture($socket,RollbackFalse::class); $tx=new MemberImportTransaction($db); $tx->start(); insert($db,'import-rollback-lost');
check($tx->unknownAfterFailure()===true); check(closed($db));
check((int)$observe->query("SELECT COUNT(*) n FROM fixture_enrollment_outcome WHERE name='import-rollback-lost'")->fetch_assoc()['n']===0);
$db=connectFixture($socket); $tx=new MemberImportTransaction($db); $tx->start(); insert($db,'nested-unknown');
check($tx->unknownAfterFailure(true)===true); check(closed($db));
check((int)$observe->query("SELECT COUNT(*) n FROM fixture_enrollment_outcome WHERE name='nested-unknown'")->fetch_assoc()['n']===0);
$observe->close();
echo json_encode(['status'=>'PASS','checks'=>$checks])."\n";
