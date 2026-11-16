<?php
/** Dormant persistence foundation. No HTTP access or authentication here. */
namespace App\Services;

require_once __DIR__ . '/SchoolStructureInput.php';
require_once __DIR__ . '/AttendancePreparationReadBoundary.php';

final class SchoolStructureCreateOutcomeUnknown extends \RuntimeException {}

final class SchoolStructureActor
{
    private function __construct(private int $id, private string $role) {}
    /** Values must come from a freshly authenticated, revalidated server session. */
    public static function fromTrustedSession(int $id, string $role): self
    {
        if ($id <= 0 || !in_array($role, ['super_admin', 'school_admin', 'edu_dept'], true)) {
            throw new \DomainException('Structure management authorization required.');
        }
        return new self($id, $role);
    }
    public function id(): int { return $this->id; }
    public function role(): string { return $this->role; }
}

final class SchoolStructureCreate
{
    /** Caller must authenticate, revalidate and authorize before exposing to users. */
    public static function level(\mysqli $db, SchoolStructureActor $actor, mixed $name, mixed $order): int
    {
        return self::create($db, $actor, 'level', null, SchoolStructureInput::name($name), SchoolStructureInput::sortOrder($order));
    }

    public static function grade(\mysqli $db, SchoolStructureActor $actor, mixed $levelId, mixed $name, mixed $order): int
    {
        return self::create($db, $actor, 'grade', SchoolStructureInput::parentId($levelId), SchoolStructureInput::name($name), SchoolStructureInput::sortOrder($order));
    }

    public static function section(\mysqli $db, SchoolStructureActor $actor, mixed $gradeId, mixed $name, mixed $order): int
    {
        return self::create($db, $actor, 'section', SchoolStructureInput::parentId($gradeId), SchoolStructureInput::name($name), SchoolStructureInput::sortOrder($order));
    }

    private static function create(\mysqli $db, SchoolStructureActor $actor, string $kind, ?int $parent, string $name, int $order): int
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
            foreach (['school_education_levels', 'school_grades', 'school_grade_sections', 'school_academic_structure_audit'] as $table) {
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
            $stmt = $db->prepare('INSERT INTO school_academic_structure_audit (entity_kind, entity_id, parent_id, created_name, created_sort_order, actor_user_id, actor_role, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(6))');
            if (!$stmt) throw new \RuntimeException('Structure audit unavailable.');
            try {
                $actorId = $actor->id(); $actorRole = $actor->role();
                if (!$stmt->bind_param('siisiis', $kind, $id, $parent, $name, $order, $actorId, $actorRole)
                    || !$stmt->execute() || $stmt->affected_rows !== 1) {
                    throw new \RuntimeException('Structure audit failed.');
                }
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
