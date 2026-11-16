<?php
/**
 * Dormant transaction-only calendar context for a future school write.
 * Does not authorize, configure/switch the calendar, or handle offline work.
 */
namespace App\Services;

require_once __DIR__ . '/SchoolCalendarMode.php';
require_once __DIR__ . '/AttendancePreparationReadBoundary.php';

final class SchoolCalendarRevisionMismatch extends \DomainException {}
final class SchoolCalendarNotConfigured extends \DomainException {}

final class SchoolCalendarWriteContext
{
    /**
     * Call only after the actor is authenticated, locked and revalidated, inside
     * an owned transaction; hold the lock through date conversion and writes.
     * A stale revision must be reviewed, never silently converted in a new mode.
     *
     * @return array{mode:SchoolCalendarMode,revision:string}
     */
    public static function lockForRevision(\mysqli $db, mixed $expectedRevision): array
    {
        if (!is_string($expectedRevision) || !preg_match('/^[1-9][0-9]{0,19}$/D', $expectedRevision)) {
            throw new \InvalidArgumentException('A valid calendar revision is required.');
        }
        $result = $db->query('SELECT @@in_transaction AS active');
        if (!$result instanceof \mysqli_result) throw new \RuntimeException('Transaction state unavailable.');
        try { $state = $result->fetch_assoc(); } finally { $result->free(); }
        if ((int)($state['active'] ?? 0) !== 1) throw new \RuntimeException('An active transaction is required.');
        AttendancePreparationReadBoundary::requireTransactionalTable($db, 'school_calendar_configuration');
        $result = $db->query('SELECT mode,revision FROM school_calendar_configuration WHERE id=1 FOR UPDATE');
        if (!$result instanceof \mysqli_result) throw new \RuntimeException('Calendar configuration unavailable.');
        try { $row = $result->fetch_assoc(); } finally { $result->free(); }
        if (!$row || !is_string($row['mode'] ?? null)
            || !preg_match('/^[1-9][0-9]{0,19}$/D', (string)($row['revision'] ?? ''))) {
            throw new SchoolCalendarNotConfigured('School calendar is not configured.');
        }
        $mode = SchoolCalendarMode::tryFrom($row['mode']);
        if (!$mode) throw new SchoolCalendarNotConfigured('School calendar is not configured.');
        $revision = (string)$row['revision'];
        if (!hash_equals($revision, $expectedRevision)) {
            throw new SchoolCalendarRevisionMismatch('School calendar changed; review the pending dates.');
        }
        return ['mode'=>$mode,'revision'=>$revision];
    }
}
