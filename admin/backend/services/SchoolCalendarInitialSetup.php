<?php
/**
 * Dormant backend foundation for a one-time calendar selection. This is NOT an
 * HTTP endpoint and does not activate display mode. The calling adapter must
 * authenticate and revalidate a session before constructing the trusted actor.
 */
namespace App\Services;

require_once __DIR__ . '/SchoolCalendarMode.php';
require_once __DIR__ . '/AttendancePreparationReadBoundary.php';

final class CalendarSetupActor
{
    private function __construct(private int $userId, private string $passwordVersion, private string $authorizationVersion) {}

    /** $role and $userId must come from a freshly authenticated server session. */
    public static function fromTrustedSession(int $userId, string $role, string $passwordVersion, string $authorizationVersion): self
    {
        if ($userId <= 0 || $userId > 4294967295 || $role !== 'super_admin'
            || !preg_match('/^[0-9a-f]{64}$/D', $passwordVersion)
            || !preg_match('/^[1-9][0-9]{0,19}$/D', $authorizationVersion)) {
            throw new \DomainException('Super Admin authorization is required.');
        }
        return new self($userId, $passwordVersion, $authorizationVersion);
    }

    public function userId(): int { return $this->userId; }
    public function passwordVersion(): string { return $this->passwordVersion; }
    public function authorizationVersion(): string { return $this->authorizationVersion; }
}

final class CalendarAlreadyConfigured extends \DomainException {}
final class CalendarSetupOutcomeUnknown extends \RuntimeException {}

final class SchoolCalendarInitialSetup
{
    private static function revalidateActor(\mysqli $db, CalendarSetupActor $actor): void
    {
        $stmt = $db->prepare('SELECT role,is_active,password_hash,authorization_version FROM users WHERE id=? FOR UPDATE');
        if (!$stmt) throw new \RuntimeException('Calendar account verification unavailable.');
        try {
            $id = $actor->userId();
            if (!$stmt->bind_param('i', $id) || !$stmt->execute()) throw new \RuntimeException('Calendar account verification unavailable.');
            $result = $stmt->get_result();
            if (!$result) throw new \RuntimeException('Calendar account verification unavailable.');
            try { $row = $result->fetch_assoc(); } finally { $result->free(); }
            if (!$row || (int)$row['is_active'] !== 1 || $row['role'] !== 'super_admin'
                || (string)$row['authorization_version'] !== $actor->authorizationVersion()
                || !is_string($row['password_hash'])
                || !hash_equals($actor->passwordVersion(), hash('sha256', $row['password_hash']))) {
                throw new \DomainException('Calendar setup authorization no longer valid.');
            }
        } finally { $stmt->close(); }
    }

    /**
     * Owns its transaction. Initial selection only; does not switch a mode.
     * A commit/rollback failure has an unknown outcome: read state before retry.
     *
     * @return array{mode:string,revision:int}
     */
    public static function choose(\mysqli $db, CalendarSetupActor $actor, mixed $choice): array
    {
        $mode = SchoolCalendarMode::fromExplicitChoice($choice)->value;
        $started = false;
        $configured = false;
        $commitAttempted = false;
        try {
            $result = $db->query('SELECT @@session.autocommit AS enabled');
            if (!$result instanceof \mysqli_result) throw new \RuntimeException('Cannot inspect transaction state.');
            try { $row = $result->fetch_assoc(); } finally { $result->free(); }
            if ((int)($row['enabled'] ?? 0) !== 1) throw new \RuntimeException('Independent transaction required.');
            // MySQL/MariaDB reject changing next-transaction isolation inside
            // an existing transaction. Never BEGIN before this check succeeds.
            if (!$db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')) throw new \RuntimeException('Cannot configure transaction.');
            $configured = true;
            if (!$db->begin_transaction()) throw new \RuntimeException('Cannot start transaction.');
            $started = true;
            $configured = false;
            foreach (['users', 'school_calendar_configuration', 'school_calendar_configuration_audit'] as $table) {
                AttendancePreparationReadBoundary::requireTransactionalTable($db, $table);
            }
            // Lock the account first, then configuration. A future mode-switch
            // service must use compatible lock ordering to avoid deadlocks.
            self::revalidateActor($db, $actor);
            $result = $db->query('SELECT mode, revision FROM school_calendar_configuration WHERE id=1 FOR UPDATE');
            if (!$result instanceof \mysqli_result) throw new \RuntimeException('Calendar configuration unavailable.');
            try { $state = $result->fetch_assoc(); } finally { $result->free(); }
            if (!$state || $state['mode'] !== null || (string)$state['revision'] !== '0') {
                throw new CalendarAlreadyConfigured('Calendar is already configured or needs administrator review.');
            }
            $stmt = $db->prepare('UPDATE school_calendar_configuration SET mode=?, revision=1, updated_at=NOW(6) WHERE id=1 AND mode IS NULL AND revision=0');
            if (!$stmt) throw new \RuntimeException('Calendar update unavailable.');
            try {
                if (!$stmt->bind_param('s', $mode) || !$stmt->execute() || $stmt->affected_rows !== 1) {
                    throw new \RuntimeException('Calendar update failed.');
                }
            } finally { $stmt->close(); }
            $uid = $actor->userId();
            $stmt = $db->prepare('INSERT INTO school_calendar_configuration_audit (configuration_id,revision,previous_mode,new_mode,actor_user_id,occurred_at) VALUES (1,1,NULL,?,?,NOW(6))');
            if (!$stmt) throw new \RuntimeException('Calendar audit unavailable.');
            try {
                if (!$stmt->bind_param('si', $mode, $uid) || !$stmt->execute() || $stmt->affected_rows !== 1) {
                    throw new \RuntimeException('Calendar audit failed.');
                }
            } finally { $stmt->close(); }
            $commitAttempted = true;
            if (!$db->commit()) throw new \RuntimeException('Calendar commit not confirmed.');
            $started = false;
            return ['mode' => $mode, 'revision' => 1];
        } catch (\Throwable $error) {
            if ($commitAttempted || $configured) {
                try { $db->close(); } catch (\Throwable $ignored) {}
                throw new CalendarSetupOutcomeUnknown('Calendar setup outcome is unknown; inspect state before retry.', 0, $error);
            }
            if ($started) {
                try {
                    if (!$db->rollback()) throw new \RuntimeException('Rollback was not confirmed.');
                } catch (\Throwable $rollbackError) {
                    try { $db->close(); } catch (\Throwable $ignored) {}
                    throw new CalendarSetupOutcomeUnknown('Calendar setup outcome is unknown; inspect state before retry.', 0, $rollbackError);
                }
            }
            throw $error;
        }
    }
}
