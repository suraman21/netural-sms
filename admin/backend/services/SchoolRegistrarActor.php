<?php
/**
 * Dormant transaction-time account revalidation. This is not session
 * authentication, an HTTP guard, or permission to create Registrar accounts.
 */
namespace App\Services;

require_once __DIR__ . '/AttendancePreparationReadBoundary.php';

final class SchoolRegistrarActor
{
    private function __construct(private int $id, private string $passwordVersion, private string $authorizationVersion) {}

    /** Values must come from a freshly authenticated, non-impersonated server session. */
    public static function fromTrustedSession(int $id, string $role, string $passwordVersion, string $authorizationVersion): self
    {
        // Distinct from the legacy ACM role 'registrar', which migration 081 rewrites.
        if ($id <= 0 || $id > 4294967295 || $role !== 'school_registrar'
            || !preg_match('/^[0-9a-f]{64}$/D', $passwordVersion)
            || !preg_match('/^[1-9][0-9]{0,19}$/D', $authorizationVersion)) {
            throw new \DomainException('Registrar authorization is required.');
        }
        return new self($id, $passwordVersion, $authorizationVersion);
    }

    public function id(): int { return $this->id; }

    /** Must be the caller's first row lock, within its owned transaction. */
    public function lockAndRevalidate(\mysqli $db): void
    {
        $result = $db->query('SELECT @@in_transaction AS active');
        if (!$result instanceof \mysqli_result) throw new \RuntimeException('Transaction state unavailable.');
        try { $state = $result->fetch_assoc(); } finally { $result->free(); }
        if ((int)($state['active'] ?? 0) !== 1) throw new \RuntimeException('An active transaction is required.');
        AttendancePreparationReadBoundary::requireTransactionalTable($db, 'users');
        $stmt = $db->prepare('SELECT role,is_active,password_hash,authorization_version FROM users WHERE id=? FOR UPDATE');
        if (!$stmt) throw new \RuntimeException('Account verification unavailable.');
        try {
            $id = $this->id;
            if (!$stmt->bind_param('i', $id) || !$stmt->execute()) throw new \RuntimeException('Account verification unavailable.');
            $result = $stmt->get_result();
            if (!$result) throw new \RuntimeException('Account verification unavailable.');
            try { $row = $result->fetch_assoc(); } finally { $result->free(); }
            if (!$row || (int)$row['is_active'] !== 1 || $row['role'] !== 'school_registrar'
                || (string)$row['authorization_version'] !== $this->authorizationVersion
                || !is_string($row['password_hash'])
                || !hash_equals($this->passwordVersion, hash('sha256', $row['password_hash']))) {
                throw new \DomainException('Registrar authorization is no longer valid.');
            }
        } finally { $stmt->close(); }
    }
}
