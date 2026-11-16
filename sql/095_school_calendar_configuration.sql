-- Calendar-mode storage foundation only. No existing runtime reads this yet.
-- A new installation must receive an explicit mode selection; the legacy
-- dept_settings.calendar_mode row is intentionally not copied or interpreted.
-- Apply only as part of an approved, isolated migration/deployment plan.

CREATE TABLE IF NOT EXISTS `school_calendar_configuration` (
    `id` TINYINT UNSIGNED NOT NULL,
    `mode` ENUM('ethiopian','gregorian') DEFAULT NULL,
    `revision` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` DATETIME(6) DEFAULT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `chk_school_calendar_singleton` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `school_calendar_configuration_audit` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `configuration_id` TINYINT UNSIGNED NOT NULL,
    `revision` BIGINT UNSIGNED NOT NULL,
    `previous_mode` ENUM('ethiopian','gregorian') DEFAULT NULL,
    `new_mode` ENUM('ethiopian','gregorian') NOT NULL,
    `actor_user_id` INT UNSIGNED NOT NULL,
    `occurred_at` DATETIME(6) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_school_calendar_audit_revision` (`revision`),
    CONSTRAINT `fk_school_calendar_audit_config`
        FOREIGN KEY (`configuration_id`) REFERENCES `school_calendar_configuration` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotent bootstrap: never overwrite a previously selected mode.
INSERT INTO `school_calendar_configuration` (`id`, `mode`, `revision`)
SELECT 1, NULL, 0
WHERE NOT EXISTS (SELECT 1 FROM `school_calendar_configuration` WHERE `id` = 1);
