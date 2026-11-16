-- Dormant, database-only registration receipt foundation. No records are
-- inferred or imported. Requires matching InnoDB users, members and
-- class_enrollments IDs; migration-chain and restore preflight are mandatory.
-- Reservation and completion MUST belong to one owned business transaction;
-- the caller must never commit a processing receipt.
CREATE TABLE IF NOT EXISTS `school_registration_receipts` (
    `operation_hash` BINARY(32) NOT NULL,
    `actor_user_id` INT UNSIGNED NOT NULL,
    `request_hash` BINARY(32) NOT NULL,
    `owner_token` BINARY(32) NOT NULL,
    `receipt_state` ENUM('processing','completed') NOT NULL,
    `member_id` INT UNSIGNED DEFAULT NULL,
    `enrollment_id` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `completed_at` DATETIME(6) DEFAULT NULL,
    PRIMARY KEY (`operation_hash`),
    KEY `idx_school_receipt_actor` (`actor_user_id`),
    KEY `idx_school_receipt_member` (`member_id`),
    KEY `idx_school_receipt_enrollment` (`enrollment_id`),
    CONSTRAINT `fk_school_receipt_actor` FOREIGN KEY (`actor_user_id`)
        REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_school_receipt_member` FOREIGN KEY (`member_id`)
        REFERENCES `members` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_school_receipt_enrollment` FOREIGN KEY (`enrollment_id`)
        REFERENCES `class_enrollments` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
