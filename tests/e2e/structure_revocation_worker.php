<?php
// Child process for private fixture test only; never connects to application DB.
require __DIR__ . '/../../admin/backend/services/SchoolStructureCreate.php';
use App\Services\SchoolStructureCreate;
use App\Services\SchoolStructureActor;
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$socket = $argv[1] ?? '';
$mode = $argv[2] ?? '';
if (!str_contains($socket, '/academic-structure-db.') || !in_array($mode, ['normal','pause-commit'], true)) exit(2);
class PausingStructureConnection extends mysqli {
    public function commit(int $flags = 0, ?string $name = null): bool {
        echo "BEFORE_COMMIT\n"; flush();
        if (trim((string)fgets(STDIN)) !== 'COMMIT') throw new RuntimeException('Fixture worker not released.');
        return parent::commit($flags, $name);
    }
}
$db = $mode === 'pause-commit'
    ? new PausingStructureConnection('localhost', 'academic_test', 'fixture-only', 'sms_academic_schema_test', 0, $socket)
    : new mysqli('localhost', 'academic_test', 'fixture-only', 'sms_academic_schema_test', 0, $socket);
$db->set_charset('utf8mb4');
$r = $db->query('SELECT @@skip_networking AS offnet, DATABASE() AS name, CONNECTION_ID() AS connection_id')->fetch_assoc();
if ((int)$r['offnet'] !== 1 || $r['name'] !== 'sms_academic_schema_test') exit(2);
echo 'CONNECTED ' . $r['connection_id'] . "\n"; flush();
try {
    $actor = SchoolStructureActor::fromTrustedSession(42, 'edu_dept', hash('sha256', 'fixture-hash'), '1');
    $id = SchoolStructureCreate::level($db, $actor, $mode === 'normal' ? 'revocation wins' : 'creation wins', 0);
    echo 'CREATED ' . $id . "\n";
} catch (DomainException $error) {
    echo "DENIED\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n"); exit(1);
}
