<?php
/** Create one standalone academic hierarchy item. No enrollment integration. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/SchoolStructureCreate.php';

use App\Services\SchoolStructureActor;
use App\Services\SchoolStructureCreate;
use App\Services\SchoolStructureCreateOutcomeUnknown;

function structureCreateError(int $status, string $message): never {
    http_response_code($status);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
    structureCreateError(401, 'Authentication required.');
}
if (!empty($_SESSION['original_admin_role']) || !in_array($_SESSION['admin_role'] ?? '', ['super_admin', 'school_admin', 'edu_dept'], true)) {
    structureCreateError(403, 'You cannot manage school structure.');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    structureCreateError(405, 'Use POST to create a school structure item.');
}
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($csrf) || !validateCsrf($csrf)) {
    structureCreateError(403, 'Security token expired. Please refresh.');
}
$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') structureCreateError(415, 'JSON is required.');
$body = file_get_contents('php://input', false, null, 0, 4097);
if (!is_string($body) || strlen($body) > 4096) structureCreateError(413, 'Request is too large.');
try {
    $input = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input) || array_is_list($input)) throw new InvalidArgumentException();
    $kind = $input['kind'] ?? null;
    if (!is_string($kind) || !in_array($kind, ['level', 'grade', 'section'], true)) throw new InvalidArgumentException();
    $keys = $kind === 'level' ? ['kind', 'name', 'sort_order'] : ['kind', 'name', 'parent_id', 'sort_order'];
    $supplied = array_keys($input);
    sort($keys); sort($supplied);
    if ($keys !== $supplied) throw new InvalidArgumentException();
    if (!$conn instanceof mysqli) throw new RuntimeException('Database unavailable.');
    $actor = SchoolStructureActor::fromTrustedSession((int)$_SESSION['admin_id'], (string)$_SESSION['admin_role'], (string)($_SESSION['AUTH_PASSWORD_VERSION'] ?? ''));
    $id = match ($kind) {
        'level' => SchoolStructureCreate::level($conn, $actor, $input['name'], $input['sort_order']),
        'grade' => SchoolStructureCreate::grade($conn, $actor, $input['parent_id'], $input['name'], $input['sort_order']),
        'section' => SchoolStructureCreate::section($conn, $actor, $input['parent_id'], $input['name'], $input['sort_order']),
    };
    http_response_code(201);
    echo json_encode(['status' => 'success', 'kind' => $kind, 'id' => $id]);
} catch (SchoolStructureCreateOutcomeUnknown $error) {
    structureCreateError(503, 'The outcome is unknown. Check the school structure before retrying.');
} catch (JsonException | InvalidArgumentException $error) {
    structureCreateError(400, 'Invalid school structure request.');
} catch (DomainException $error) {
    structureCreateError(403, 'Account or parent is not authorized or active. Refresh and check your settings.');
} catch (mysqli_sql_exception $error) {
    if ((int)$error->getCode() === 1062) structureCreateError(409, 'A matching item already exists.');
    error_log('School structure creation failed.');
    structureCreateError(503, 'School structure creation is temporarily unavailable.');
} catch (Throwable $error) {
    error_log('School structure creation failed.');
    structureCreateError(503, 'School structure creation is temporarily unavailable.');
}
