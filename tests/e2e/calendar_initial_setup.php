<?php
/** Synthetic local-only fixture; never loads application configuration. */
if (PHP_SAPI !== 'cli' || getenv('SSMS_AUDIT_TESTING') !== '1') exit(2);
$socket = getenv('SMS_CALENDAR_TEST_SOCKET');
if (!is_string($socket) || !preg_match('#^/home/user/\.cache/calendar-schema-db\.[A-Za-z0-9]+/mysql\.sock$#D', $socket)) exit(2);
require_once __DIR__ . '/../../admin/backend/services/SchoolCalendarInitialSetup.php';
use App\Services\CalendarAlreadyConfigured;
use App\Services\CalendarSetupActor;
use App\Services\CalendarSetupOutcomeUnknown;
use App\Services\SchoolCalendarInitialSetup;

// Failure injection is limited to the transaction boundary; SQL remains real.
class CalendarFaultConnection extends mysqli {
    public string $fault = '';
    public function commit(int $flags = 0, ?string $name = null): bool {
        if ($this->fault === 'lost-commit') {
            parent::commit($flags, $name);
            throw new RuntimeException('Synthetic lost commit confirmation');
        }
        return parent::commit($flags, $name);
    }
    public function rollback(int $flags = 0, ?string $name = null): bool {
        if ($this->fault === 'rollback-failed') return false;
        return parent::rollback($flags, $name);
    }
}

if (($argv[1] ?? '') === 'worker') {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $workerDb = new mysqli('localhost', 'calendar_test', 'fixture-only', 'sms_calendar_service_test', 0, $socket);
    echo "READY\n"; flush();
    try {
        SchoolCalendarInitialSetup::choose($workerDb, CalendarSetupActor::fromTrustedSession(42, 'super_admin'), $argv[2] ?? '');
        echo "SUCCESS\n";
    } catch (CalendarAlreadyConfigured $e) { echo "CONFLICT\n"; }
    $workerDb->close();
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('localhost', 'calendar_test', 'fixture-only', 'sms_calendar_service_test', 0, $socket);
$checks = 0;
function expect(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function denied(callable $fn, string $class): void {
    try { $fn(); } catch (Throwable $e) { expect($e instanceof $class, 'Unexpected error: ' . get_class($e)); return; }
    throw new RuntimeException('Expected rejection did not occur');
}
function state(mysqli $db): array {
    return $db->query('SELECT mode,revision,(SELECT COUNT(*) FROM school_calendar_configuration_audit) AS audits FROM school_calendar_configuration WHERE id=1')->fetch_assoc();
}
function resetFixture(mysqli $db): void {
    $db->query('DELETE FROM school_calendar_configuration_audit');
    $db->query('UPDATE school_calendar_configuration SET mode=NULL,revision=0,updated_at=NULL WHERE id=1');
}
try {
    denied(fn()=>CalendarSetupActor::fromTrustedSession(1, 'school_admin'), DomainException::class);
    denied(fn()=>CalendarSetupActor::fromTrustedSession(0, 'super_admin'), DomainException::class);
    $actor = CalendarSetupActor::fromTrustedSession(42, 'super_admin');
    foreach ([MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, MYSQLI_REPORT_OFF] as $reportMode) {
        mysqli_report($reportMode);
        foreach (['ethiopian','gregorian'] as $mode) {
            resetFixture($db);
            denied(fn()=>SchoolCalendarInitialSetup::choose($db,$actor,'both'), InvalidArgumentException::class);
            $initial = state($db);
            expect($initial['mode'] === null && (int)$initial['revision'] === 0 && (int)$initial['audits'] === 0, 'Invalid input changed state');
            $result = SchoolCalendarInitialSetup::choose($db,$actor,$mode);
            expect($result === ['mode'=>$mode,'revision'=>1], 'Incorrect result');
            $actual = state($db);
            expect($actual['mode'] === $mode && (int)$actual['revision'] === 1 && (int)$actual['audits'] === 1, 'Selection/audit missing');
            $audit = $db->query('SELECT previous_mode,new_mode,actor_user_id FROM school_calendar_configuration_audit WHERE revision=1')->fetch_assoc();
            expect($audit['previous_mode'] === null && $audit['new_mode'] === $mode && (int)$audit['actor_user_id'] === 42, 'Audit actor/mode incorrect');
            denied(fn()=>SchoolCalendarInitialSetup::choose($db,$actor,$mode), CalendarAlreadyConfigured::class);
            denied(fn()=>SchoolCalendarInitialSetup::choose($db,$actor,$mode === 'ethiopian' ? 'gregorian' : 'ethiopian'), CalendarAlreadyConfigured::class);
            expect(state($db) === $actual, 'Second setup changed state');
        }
        resetFixture($db);
        // Duplicate audit revision forces the real insert to fail after UPDATE.
        $db->query("INSERT INTO school_calendar_configuration_audit(configuration_id,revision,previous_mode,new_mode,actor_user_id,occurred_at) VALUES (1,1,NULL,'gregorian',7,NOW(6))");
        denied(fn()=>SchoolCalendarInitialSetup::choose($db,$actor,'ethiopian'), RuntimeException::class);
        $actual = state($db);
        expect($actual['mode'] === null && (int)$actual['revision'] === 0 && (int)$actual['audits'] === 1, 'Audit failure did not roll back setting');
        resetFixture($db);
        $db->begin_transaction();
        denied(fn()=>SchoolCalendarInitialSetup::choose($db,$actor,'ethiopian'), Throwable::class);
        expect((int)$db->query('SELECT @@in_transaction')->fetch_row()[0] === 1, 'Outer transaction was ended');
        $db->rollback();
    }
    // Commit succeeds on the server, but its confirmation is deliberately lost.
    // The service must report unknown, and a fresh connection sees the commit.
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    resetFixture($db);
    $faultDb = new CalendarFaultConnection('localhost', 'calendar_test', 'fixture-only', 'sms_calendar_service_test', 0, $socket);
    $faultDb->fault = 'lost-commit';
    denied(fn()=>SchoolCalendarInitialSetup::choose($faultDb,$actor,'ethiopian'), CalendarSetupOutcomeUnknown::class);
    $afterLost = state($db);
    expect($afterLost['mode'] === 'ethiopian' && (int)$afterLost['revision'] === 1 && (int)$afterLost['audits'] === 1, 'Lost response state was not committed');
    // An audit failure followed by a rollback failure is also unknown. The
    // service closes the faulty connection rather than returning it for reuse.
    resetFixture($db);
    $db->query("INSERT INTO school_calendar_configuration_audit(configuration_id,revision,previous_mode,new_mode,actor_user_id,occurred_at) VALUES (1,1,NULL,'gregorian',7,NOW(6))");
    $faultDb = new CalendarFaultConnection('localhost', 'calendar_test', 'fixture-only', 'sms_calendar_service_test', 0, $socket);
    $faultDb->fault = 'rollback-failed';
    denied(fn()=>SchoolCalendarInitialSetup::choose($faultDb,$actor,'ethiopian'), CalendarSetupOutcomeUnknown::class);
    $afterFailure = state($db);
    expect($afterFailure['mode'] === null && (int)$afterFailure['revision'] === 0 && (int)$afterFailure['audits'] === 1, 'Unexpected state after closed connection');
    // Hold the singleton row while two independently connected workers start.
    // Both enter setup before the row is released; exactly one can win.
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    resetFixture($db);
    $db->begin_transaction();
    $db->query('SELECT id FROM school_calendar_configuration WHERE id=1 FOR UPDATE')->free();
    $workers = [];
    foreach (['ethiopian','gregorian'] as $mode) {
        $proc = proc_open([PHP_BINARY, __FILE__, 'worker', $mode],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($proc)) throw new RuntimeException('Worker could not start');
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 10);
        if (trim((string)fgets($pipes[1])) !== 'READY') throw new RuntimeException('Worker did not become ready');
        $workers[] = [$proc,$pipes];
    }
    $db->commit();
    $outcomes = [];
    foreach ($workers as [$proc,$pipes]) {
        $outcomes[] = trim(stream_get_contents($pipes[1]));
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        expect(proc_close($proc) === 0 && $err === '', 'Concurrent worker failed: '.$err);
    }
    sort($outcomes);
    expect($outcomes === ['CONFLICT','SUCCESS'], 'Concurrent initial selection did not have one winner');
    $final = state($db);
    expect(in_array($final['mode'], ['ethiopian','gregorian'], true) && (int)$final['revision'] === 1 && (int)$final['audits'] === 1, 'Concurrent state/audit invalid');
    echo json_encode(['status'=>'PASS','checks'=>$checks], JSON_THROW_ON_ERROR), "\n";
} finally { $db->close(); }
