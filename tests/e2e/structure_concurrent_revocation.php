<?php
// Independent-process concurrency test for disposable socket-only fixture.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$socket = getenv('SMS_STRUCTURE_FIXTURE_SOCKET');
if (!$socket || !str_contains($socket, '/academic-structure-db.')) throw new RuntimeException('Private fixture required.');
function conn(string $socket): mysqli {
    $db = new mysqli('localhost', 'academic_test', 'fixture-only', 'sms_academic_schema_test', 0, $socket);
    $row = $db->query('SELECT @@skip_networking AS offnet, DATABASE() AS name')->fetch_assoc();
    if ((int)$row['offnet'] !== 1 || $row['name'] !== 'sms_academic_schema_test') throw new RuntimeException('Wrong fixture.');
    return $db;
}
function startWorker(string $socket, string $mode): array {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __DIR__ . '/structure_revocation_worker.php', $socket, $mode],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Worker could not start.');
    stream_set_timeout($pipes[1], 10); stream_set_timeout($pipes[2], 10);
    return [$process, $pipes];
}
function line($stream): string {
    $value = fgets($stream);
    if ($value === false) throw new RuntimeException('Worker stopped or timed out.');
    return trim($value);
}
function assertTrue(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function waitForLock(mysqli $db, int $thread): void {
    $deadline = microtime(true) + 8;
    do {
        $query = 'SELECT COUNT(*) AS n FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX t ON t.trx_id=w.requesting_trx_id WHERE t.trx_mysql_thread_id=' . $thread;
        if ((int)$db->query($query)->fetch_assoc()['n'] > 0) return;
        // MariaDB can serve stale information_schema transaction snapshots.
        // Its engine monitor identifies the exact waiting thread and table.
        $engine = $db->query('SHOW ENGINE INNODB STATUS')->fetch_assoc()['Status'] ?? '';
        $pattern = '/---TRANSACTION [^\n]*\n(?:(?!---TRANSACTION).)*?LOCK WAIT(?:(?!---TRANSACTION).)*?MariaDB thread id ' . $thread . ',(?:(?!---TRANSACTION).)*?`sms_academic_schema_test`\.`users`/s';
        if (preg_match($pattern, $engine)) return;
        usleep(50000);
    } while (microtime(true) < $deadline);
    $processes = $db->query('SHOW FULL PROCESSLIST')->fetch_all(MYSQLI_ASSOC);
    $transactions = $db->query('SELECT trx_mysql_thread_id,trx_state,trx_query FROM information_schema.INNODB_TRX')->fetch_all(MYSQLI_ASSOC);
    $engine = $db->query('SHOW ENGINE INNODB STATUS')->fetch_assoc();
    throw new RuntimeException('Expected database lock wait was not observed. processes=' . json_encode($processes) . ' transactions=' . json_encode($transactions) . ' engine=' . ($engine['Status'] ?? ''));
}
function stopWorker($process, array $pipes): void {
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    $status = proc_get_status($process);
    if ($status['running']) proc_terminate($process);
    proc_close($process);
}
$admin = conn($socket); $observe = conn($socket); $proc = null; $pipes = [];
try {
    // Revocation locks first; the waiting create must see the committed bump.
    $admin->begin_transaction();
    $admin->query('UPDATE users SET authorization_version=2 WHERE id=42');
    [$proc,$pipes] = startWorker($socket, 'normal');
    $connected = line($pipes[1]);
    assertTrue((bool)preg_match('/^CONNECTED ([0-9]+)$/', $connected, $m), 'Worker did not connect.');
    try { waitForLock($observe, (int)$m[1]); } catch (Throwable $e) {
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        throw new RuntimeException($e->getMessage() . ' worker output: ' . stream_get_contents($pipes[1]) . ' stderr: ' . stream_get_contents($pipes[2]), 0, $e);
    }
    $admin->commit();
    assertTrue(line($pipes[1]) === 'DENIED', 'Stale creation was not denied.');
    stopWorker($proc,$pipes); $proc=null; $pipes=[];
    $result=$observe->query("SELECT (SELECT COUNT(*) FROM school_education_levels WHERE name='revocation wins') AS entity, (SELECT COUNT(*) FROM school_academic_structure_audit WHERE created_name='revocation wins') AS audit")->fetch_assoc();
    assertTrue((int)$result['entity'] === 0 && (int)$result['audit'] === 0, 'Stale creation wrote data.');
    $admin->query('UPDATE users SET authorization_version=1 WHERE id=42');

    // Creation holds the user lock through entity/audit insert and pauses at commit.
    [$proc,$pipes] = startWorker($socket, 'pause-commit');
    assertTrue((bool)preg_match('/^CONNECTED ([0-9]+)$/', line($pipes[1])), 'Worker did not connect.');
    assertTrue(line($pipes[1]) === 'BEFORE_COMMIT', 'Worker did not reach commit.');
    $revoker = conn($socket);
    $revokerId = (int)$revoker->query('SELECT CONNECTION_ID() AS id')->fetch_assoc()['id'];
    if (!$revoker->query('UPDATE users SET authorization_version=2 WHERE id=42', MYSQLI_ASYNC)) throw new RuntimeException('Revocation did not start.');
    waitForLock($observe, $revokerId);
    fwrite($pipes[0], "COMMIT\n"); fflush($pipes[0]);
    assertTrue((bool)preg_match('/^CREATED ([0-9]+)$/', line($pipes[1]), $m), 'Creation did not commit.');
    $id = (int)$m[1];
    $read = [$revoker]; $error = []; $reject = [];
    assertTrue(mysqli_poll($read, $error, $reject, 8) > 0 && $revoker->reap_async_query() === true, 'Revocation did not complete.');
    stopWorker($proc,$pipes); $proc=null; $pipes=[];
    $result=$observe->query("SELECT (SELECT COUNT(*) FROM school_education_levels WHERE id=$id AND name='creation wins') AS entity, (SELECT COUNT(*) FROM school_academic_structure_audit WHERE entity_kind='level' AND entity_id=$id AND created_name='creation wins') AS audit, (SELECT authorization_version FROM users WHERE id=42) AS version")->fetch_assoc();
    assertTrue((int)$result['entity'] === 1 && (int)$result['audit'] === 1 && (int)$result['version'] === 2, 'Committed creation or revocation missing.');
    $revoker->close();
    echo "PASS: both observed lock-wait orderings preserve authorization and audit atomicity\n";
} finally {
    if (is_resource($proc)) stopWorker($proc, $pipes);
    try { $admin->rollback(); } catch (Throwable $ignored) {}
    $admin->close(); $observe->close();
}
