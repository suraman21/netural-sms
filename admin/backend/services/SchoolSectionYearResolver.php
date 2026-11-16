<?php
/**
 * Dormant transactional link between configured school sections and legacy
 * enrollment classes. This does not authorize, enroll, audit or own a transaction.
 */
namespace App\Services;

require_once __DIR__ . '/AttendancePreparationReadBoundary.php';

final class SchoolSectionYearResolver
{
    /**
     * Must be called AFTER a trusted actor has been locked/revalidated, inside
     * the caller's owned transaction; hold that transaction through enrollment.
     * The server must expose @@in_transaction; unsupported servers fail closed.
     *
     * @return int Legacy classes.id for this active section and active year.
     */
    public static function resolveForUpdate(\mysqli $db, mixed $yearId, mixed $sectionId): int
    {
        if (!is_int($yearId) || $yearId <= 0 || !is_int($sectionId) || $sectionId <= 0) {
            throw new \InvalidArgumentException('Valid year and section IDs are required.');
        }
        $result = $db->query('SELECT @@in_transaction AS active');
        if (!$result instanceof \mysqli_result) throw new \RuntimeException('Transaction state unavailable.');
        try { $state = $result->fetch_assoc(); } finally { $result->free(); }
        if ((int)($state['active'] ?? 0) !== 1) throw new \RuntimeException('An active transaction is required.');
        foreach (['academic_years','school_education_levels','school_grades','school_grade_sections','school_section_year_class_map','classes'] as $table) {
            AttendancePreparationReadBoundary::requireTransactionalTable($db, $table);
        }
        if (!self::row($db, "SELECT id FROM academic_years WHERE id=? AND status='active' AND is_current=1 FOR UPDATE", 'i', [$yearId])) {
            throw new \DomainException('The active academic year is required.');
        }
        // Obtain parent IDs without assuming that section and class IDs match.
        // Each parent relationship is subsequently rechecked under row locks.
        $parents = self::row($db, 'SELECT s.grade_id,g.level_id FROM school_grade_sections s JOIN school_grades g ON g.id=s.grade_id WHERE s.id=?', 'i', [$sectionId]);
        if (!$parents) throw new \DomainException('Active school section is required.');
        $gradeId = self::positiveId($parents['grade_id'] ?? null);
        $levelId = self::positiveId($parents['level_id'] ?? null);
        if (!self::row($db, 'SELECT id FROM school_education_levels WHERE id=? AND is_active=1 FOR UPDATE', 'i', [$levelId])) {
            throw new \DomainException('Active school section is required.');
        }
        if (!self::row($db, 'SELECT id FROM school_grades WHERE id=? AND level_id=? AND is_active=1 FOR UPDATE', 'ii', [$gradeId,$levelId])) {
            throw new \DomainException('Active school section is required.');
        }
        if (!self::row($db, 'SELECT id FROM school_grade_sections WHERE id=? AND grade_id=? AND is_active=1 FOR UPDATE', 'ii', [$sectionId,$gradeId])) {
            throw new \DomainException('Active school section is required.');
        }
        $mapping = self::row($db, 'SELECT class_id FROM school_section_year_class_map WHERE academic_year_id=? AND section_id=? FOR UPDATE', 'ii', [$yearId,$sectionId]);
        if (!$mapping) throw new \DomainException('No class is configured for this section and year.');
        $classId = self::positiveId($mapping['class_id'] ?? null);
        if (!self::row($db, 'SELECT id FROM classes WHERE id=? AND is_active=1 FOR UPDATE', 'i', [$classId])) {
            throw new \DomainException('The configured class is not active.');
        }
        return $classId;
    }

    /** @param list<int> $params @return array<string,mixed>|null */
    private static function row(\mysqli $db, string $sql, string $types, array $params): ?array
    {
        $stmt = $db->prepare($sql);
        if (!$stmt) throw new \RuntimeException('Section resolution unavailable.');
        try {
            if (!$stmt->bind_param($types, ...$params) || !$stmt->execute()) throw new \RuntimeException('Section resolution failed.');
            $result = $stmt->get_result();
            if (!$result) throw new \RuntimeException('Section resolution result unavailable.');
            try { return $result->fetch_assoc() ?: null; } finally { $result->free(); }
        } finally { $stmt->close(); }
    }

    private static function positiveId(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($id === false) throw new \RuntimeException('Invalid stored school identifier.');
        return $id;
    }
}
