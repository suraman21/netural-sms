<?php
/**
 * Dormant database-only receipt primitive, NOT an authorization boundary or a
 * registration workflow. Caller must own the transaction, lock/revalidate the
 * actor first, and atomically commit the business writes and completed receipt.
 */
namespace App\Services;

require_once __DIR__ . '/AttendancePreparationReadBoundary.php';

final class SchoolRegistrationReceiptConflict extends \DomainException {}
final class SchoolRegistrationReceiptNeedsReview extends \RuntimeException {}

final class SchoolRegistrationReceipt
{
    /**
     * Claim before writing/staging files. A committed processing record is NOT
     * recoverable by timeout: fail closed and require reconciliation.
     * Request digest must cover normalized input, mode revision and file content.
     *
     * @return array{state:string,owner_token?:string,member_id?:int,enrollment_id?:int}
     */
    public static function claim(\mysqli $db, mixed $actorId, mixed $key, mixed $requestHash): array
    {
        [$actor,$operation,$payload] = self::input($actorId,$key,$requestHash);
        self::requireTransaction($db);
        foreach (['school_registration_receipts','users','members','class_enrollments'] as $table) {
            AttendancePreparationReadBoundary::requireTransactionalTable($db, $table);
        }
        $ownerToken = random_bytes(32);
        $stmt = $db->prepare("INSERT INTO school_registration_receipts (operation_hash,actor_user_id,request_hash,owner_token,receipt_state) VALUES (?,?,?,?,'processing')");
        if (!$stmt) throw new \RuntimeException('Registration receipt unavailable.');
        $duplicate = false;
        try {
            try {
                if (!$stmt->bind_param('siss',$operation,$actor,$payload,$ownerToken)) throw new \RuntimeException('Registration receipt unavailable.');
                if (!$stmt->execute()) {
                    if ((int)$stmt->errno === 1062) $duplicate = true;
                    else throw new \RuntimeException('Registration receipt unavailable.');
                }
            } catch (\mysqli_sql_exception $e) {
                if ((int)$e->getCode() !== 1062) throw $e;
                $duplicate = true;
            }
        } finally { $stmt->close(); }
        if (!$duplicate) return ['state'=>'acquired','owner_token'=>bin2hex($ownerToken)];
        $stmt = $db->prepare('SELECT actor_user_id,request_hash,receipt_state,member_id,enrollment_id FROM school_registration_receipts WHERE operation_hash=? FOR UPDATE');
        if (!$stmt) throw new \RuntimeException('Registration receipt unavailable.');
        try {
            if (!$stmt->bind_param('s',$operation) || !$stmt->execute()) throw new \RuntimeException('Registration receipt unavailable.');
            $result = $stmt->get_result();
            if (!$result) throw new \RuntimeException('Registration receipt unavailable.');
            try { $row = $result->fetch_assoc(); } finally { $result->free(); }
        } finally { $stmt->close(); }
        if (!$row || (int)$row['actor_user_id'] !== $actor || !is_string($row['request_hash'])) {
            throw new \RuntimeException('Registration receipt unavailable.');
        }
        if (!hash_equals($payload,$row['request_hash'])) throw new SchoolRegistrationReceiptConflict('Request key reused for different data.');
        if (($row['receipt_state'] ?? null) !== 'completed' || (int)($row['member_id'] ?? 0) <= 0 || (int)($row['enrollment_id'] ?? 0) <= 0) {
            throw new SchoolRegistrationReceiptNeedsReview('Registration receipt requires review.');
        }
        return ['state'=>'replay','member_id'=>(int)$row['member_id'],'enrollment_id'=>(int)$row['enrollment_id']];
    }

    /** Complete only after the caller's business writes, before the same commit. */
    public static function complete(\mysqli $db, mixed $actorId, mixed $key, mixed $requestHash, mixed $ownerToken, mixed $memberId, mixed $enrollmentId): void
    {
        [$actor,$operation,$payload] = self::input($actorId,$key,$requestHash);
        if (!is_string($ownerToken) || !preg_match('/^[0-9a-f]{64}$/D',$ownerToken)) throw new \InvalidArgumentException('Invalid receipt ownership.');
        $owner = hex2bin($ownerToken);
        if (!is_int($memberId) || $memberId <= 0 || !is_int($enrollmentId) || $enrollmentId <= 0) throw new \InvalidArgumentException('Invalid registration identifiers.');
        self::requireTransaction($db);
        $stmt = $db->prepare('SELECT id FROM class_enrollments WHERE id=? AND member_id=? FOR UPDATE');
        if (!$stmt) throw new \RuntimeException('Enrollment verification unavailable.');
        try {
            if (!$stmt->bind_param('ii',$enrollmentId,$memberId) || !$stmt->execute()) throw new \RuntimeException('Enrollment verification unavailable.');
            $result=$stmt->get_result();
            if (!$result) throw new \RuntimeException('Enrollment verification unavailable.');
            try { $row=$result->fetch_assoc(); } finally { $result->free(); }
            if (!$row) throw new \DomainException('Registration enrollment does not match the student.');
        } finally { $stmt->close(); }
        $stmt=$db->prepare("UPDATE school_registration_receipts SET receipt_state='completed',member_id=?,enrollment_id=?,completed_at=NOW(6) WHERE operation_hash=? AND actor_user_id=? AND request_hash=? AND owner_token=? AND receipt_state='processing'");
        if (!$stmt) throw new \RuntimeException('Registration receipt unavailable.');
        try {
            if (!$stmt->bind_param('iisiss',$memberId,$enrollmentId,$operation,$actor,$payload,$owner) || !$stmt->execute() || $stmt->affected_rows !== 1) {
                throw new \RuntimeException('Registration receipt could not be completed.');
            }
        } finally { $stmt->close(); }
    }

    /** @return array{int,string,string} */
    private static function input(mixed $actorId, mixed $key, mixed $requestHash): array
    {
        if (!is_int($actorId) || $actorId <= 0 || $actorId > 4294967295
            || !is_string($key) || strlen($key) < 16 || strlen($key) > 80 || !preg_match('/^[A-Za-z0-9._-]+$/D',$key)
            || !is_string($requestHash) || !preg_match('/^[0-9a-f]{64}$/D',$requestHash)) {
            throw new \InvalidArgumentException('Invalid registration request identity.');
        }
        return [$actorId,hash('sha256',"school-registration\0".$actorId."\0".$key,true),hex2bin($requestHash)];
    }

    private static function requireTransaction(\mysqli $db): void
    {
        $result=$db->query('SELECT @@in_transaction AS active');
        if (!$result instanceof \mysqli_result) throw new \RuntimeException('Transaction state unavailable.');
        try { $row=$result->fetch_assoc(); } finally { $result->free(); }
        if ((int)($row['active'] ?? 0) !== 1) throw new \RuntimeException('An active transaction is required.');
    }
}
