<?php
/** Transaction boundary for member import. Caller owns validation and authorization. */
namespace App\Services;

final class MemberImportTransaction
{
    private bool $started = false;
    private bool $commitAttempted = false;
    public function __construct(private \mysqli $db) {}

    public function start(): void
    {
        $result = $this->db->query('SELECT @@session.autocommit AS enabled');
        if (!$result instanceof \mysqli_result) throw new \RuntimeException('Transaction state unavailable.');
        try { $state = $result->fetch_assoc(); } finally { $result->free(); }
        if ((int)($state['enabled'] ?? 0) !== 1) throw new \RuntimeException('Independent transaction required.');
        try {
            if (!$this->db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')) throw new \RuntimeException('Transaction setup failed.');
            if (!$this->db->begin_transaction()) throw new \RuntimeException('Transaction start failed.');
        } catch (\Throwable $error) {
            try { $this->db->close(); } catch (\Throwable $ignored) {}
            throw $error;
        }
        $this->started = true;
    }

    public function commit(): void
    {
        if (!$this->started) throw new \LogicException('Import transaction has not started.');
        $this->commitAttempted = true;
        if (!$this->db->commit()) throw new \RuntimeException('Commit not confirmed.');
        $this->started = false;
    }

    /** Returns true if outcome/cleanup is uncertain and connection was discarded. */
    public function unknownAfterFailure(bool $nestedUnknown = false): bool
    {
        if ($nestedUnknown || $this->commitAttempted) {
            try { $this->db->close(); } catch (\Throwable $ignored) {}
            return true;
        }
        if ($this->started) {
            try {
                if (!$this->db->rollback()) throw new \RuntimeException('Rollback not confirmed.');
                $this->started = false;
            } catch (\Throwable $error) {
                try { $this->db->close(); } catch (\Throwable $ignored) {}
                return true;
            }
        }
        return false;
    }
}
