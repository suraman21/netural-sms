<?php
/**
 * Read-only management view of the new school academic hierarchy.
 * The hierarchy is not yet connected to classes, enrollment or reports.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/AttendancePreparationReadBoundary.php';

if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Authentication required.']);
    exit;
}
if (!in_array((string)($_SESSION['admin_role'] ?? ''), ['super_admin', 'school_admin', 'edu_dept'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'You cannot view school structure settings.']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['status' => 'error', 'message' => 'Use GET for school structure.']);
    exit;
}

try {
    $queries = [
        'levels' => 'SELECT id, name, sort_order, is_active FROM school_education_levels ORDER BY sort_order, id',
        'grades' => 'SELECT id, level_id, name, sort_order, is_active FROM school_grades ORDER BY level_id, sort_order, id',
        'sections' => 'SELECT id, grade_id, name, sort_order, is_active FROM school_grade_sections ORDER BY grade_id, sort_order, id',
    ];
    $data = \App\Services\AttendancePreparationReadBoundary::read(
        $conn,
        ['school_education_levels', 'school_grades', 'school_grade_sections'],
        static function (mysqli $db) use ($queries): array {
            $data = [];
            foreach ($queries as $key => $sql) {
                $result = $db->query($sql);
                if (!$result instanceof mysqli_result) throw new RuntimeException('School structure query failed.');
                try {
                    $data[$key] = [];
                    while ($row = $result->fetch_assoc()) {
                        foreach (['id', 'level_id', 'grade_id', 'sort_order', 'is_active'] as $field) {
                            if (array_key_exists($field, $row)) $row[$field] = (int)$row[$field];
                        }
                        $data[$key][] = $row;
                    }
                } finally { $result->free(); }
            }
            return $data;
        }
    );
    header('Cache-Control: private, no-store');
    echo json_encode(['status' => 'success'] + $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('School structure read failed.');
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'School structure is temporarily unavailable.']);
}
