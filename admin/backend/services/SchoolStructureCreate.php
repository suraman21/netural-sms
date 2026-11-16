<?php
/** Dormant persistence foundation. No HTTP access, authentication or audit here. */
namespace App\Services;

require_once __DIR__ . '/SchoolStructureInput.php';
require_once __DIR__ . '/AttendancePreparationReadBoundary.php';

final class SchoolStructureCreateOutcomeUnknown extends \RuntimeException {}

final class SchoolStructureCreate
{
    /** Caller must authorize and audit before this may be exposed to users. */
    public static function level(\mysqli $db, mixed $name, mixed $order): int
    {
        return self::create($db, 'level', null, SchoolStructureInput::name($name), SchoolStructureInput::sortOrder($order));
    }

    public static function grade(\mysqli $db, mixed $levelId, mixed $name, mixed $order): int
    {
        return self::create($db, 'grade', SchoolStructureInput::parentId($levelId), SchoolStructureInput::name($name), SchoolStructureInput::sortOrder($order));
    }

    public static function section(\mysqli $db, mixed $gradeId, mixed $name, mixed $order): int
    {
        return self::create($db, 'section', SchoolStructureInput::parentId($gradeId), SchoolStructureInput::name($name), SchoolStructureInput::sortOrder($order));
    }

    private static function create(\mysqli $db, string $kind, ?int $parent, string $name, int $order): int
    {
        $started = false; $configured = false; $commitAttempted = false;
        try {
            $res = $db->query('SELECT @@session.autocommit AS enabled');
            if (!$res instanceof \mysqli_result) throw new \RuntimeException('Transaction state unavailable.');
            try { $state = $res->fetch_assoc(); } finally { $res->free(); }
            if ((int)($state['enabled'] ?? 0) !== 1) throw new \RuntimeException('Independent transaction required.');
            // Rejected by the server within a pre-existing transaction, before BEGIN.
            if (!$db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')) throw new \RuntimeException('Transaction setup failed.');
            $configured = true;
            if (!$db->begin_transaction()) throw new \RuntimeException('Transaction start failed.');
            $started = true; $configured = false;
            foreach (['school_education_levels', 'school_grades', 'school_grade_sections'] as $table) {
                AttendancePreparationReadBoundary::requireTransactionalTable($db, $table);
            }
            if ($kind === 'grade') {
                self::requireActive($db, 'SELECT id FROM school_education_levels WHERE id=? AND is_active=1 FOR UPDATE', $parent);
            } elseif ($kind === 'section') {
                // Lock the level before the grade. The second read verifies that
                // the grade still belongs to the same active level after locking.
                $stmt = $db->prepare('SELECT level_id FROM school_grades WHERE id=?');
                if (!$stmt) throw new \RuntimeException('Grade lookup failed.');
                try {
                    if (!$stmt->bind_param('i', $parent) || !$stmt->execute()) throw new \RuntimeException('Grade lookup failed.');
                    $result = $stmt->get_result();
                    if (!$result) throw new \RuntimeException('Grade lookup failed.');
                    try { $row = $result->fetch_assoc(); } finally { $result->free(); }
                } finally { $stmt->close(); }
                if (!$row || !filter_var($row['level_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) throw new \DomainException('Active parent is required.');
                $level = (int)$row['level_id'];
                self::requireActive($db, 'SELECT id FROM school_education_levels WHERE id=? AND is_active=1 FOR UPDATE', $level);
                $stmt = $db->prepare('SELECT id FROM school_grades WHERE id=? AND level_id=? AND is_active=1 FOR UPDATE');
                if (!$stmt) throw new \RuntimeException('Grade lock failed.');
                try {
                    if (!$stmt->bind_param('ii', $parent, $level) || !$stmt->execute()) throw new \RuntimeException('Grade lock failed.');
                    $result = $stmt->get_result();
                    if (!$result) throw new \RuntimeException('Grade lock failed.');
                    try { $row = $result->fetch_assoc(); } finally { $result->free(); }
                    if (!$row) throw new \DomainException('Active parent is required.');
                } finally { $stmt->close(); }
            }
            $sql = match ($kind) {
                'level' => 'INSERT INTO school_education_levels (name, sort_order) VALUES (?, ?)',
                'grade' => 'INSERT INTO school_grades (level_id, name, sort_order) VALUES (?, ?, ?)',
                'section' => 'INSERT INTO school_grade_sections (grade_id, name, sort_order) VALUES (?, ?, ?)',
                default => throw new \LogicException('Unknown structure kind.'),
            };
            $stmt = $db->prepare($sql);
            if (!$stmt) throw new \RuntimeException('Structure insert unavailable.');
            try {
                $bound = $kind === 'level' ? $stmt->bind_param('si', $name, $order) : $stmt->bind_param('isi', $parent, $name, $order);
                if (!$bound || !$stmt->execute() || $stmt->affected_rows !== 1) throw new \RuntimeException('Structure insert failed.');
                $id = $stmt->insert_id;
                if (!is_int($id) || $id <= 0) throw new \RuntimeException('Structure identifier is outside supported range.');
            } finally { $stmt->close(); }
            $commitAttempted = true;
            if (!$db->commit()) throw new \RuntimeException('Structure commit not confirmed.');
            $started = false;
            return $id;
        } catch (\Throwable $error) {
            if ($commitAttempted || $configured) {
                try { $db->close(); } catch (\Throwable $ignored) {}
                throw new SchoolStructureCreateOutcomeUnknown('Outcome unknown; inspect state before retry.', 0, $error);
            }
            if ($started) {
                try {
                    if (!$db->rollback()) throw new \RuntimeException('Rollback not confirmed.');
                } catch (\Throwable $rollbackError) {
                    try { $db->close(); } catch (\Throwable $ignored) {}
                    throw new SchoolStructureCreateOutcomeUnknown('Outcome unknown; inspect state before retry.', 0, $rollbackError);
                }
            }
            throw $error;
        }
    }

    private static function requireActive(\mysqli $db, string $sql, int $id): void
    {
        $stmt = $db->prepare($sql);
        if (!$stmt) throw new \RuntimeException('Parent lock failed.');
        try {
            if (!$stmt->bind_param('i', $id) || !$stmt->execute()) throw new \RuntimeException('Parent lock failed.');
            $result = $stmt->get_result();
            if (!$result) throw new \RuntimeException('Parent lock failed.');
            try { $row = $result->fetch_assoc(); } finally { $result->free(); }
            if (!$row) throw new \DomainException('Active parent is required.');
        } finally { $stmt->close(); }
    }
}
