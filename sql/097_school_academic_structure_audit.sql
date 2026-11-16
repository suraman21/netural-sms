-- Dormant creation audit only. No existing records are changed or inferred.
-- Apply only under a separately verified migration/deployment plan.
CREATE TABLE IF NOT EXISTS `school_academic_structure_audit` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `entity_kind` ENUM('level','grade','section') NOT NULL,
    `entity_id` BIGINT UNSIGNED NOT NULL,
    `parent_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_name` VARCHAR(150) NOT NULL,
    `created_sort_order` INT NOT NULL,
    `actor_user_id` BIGINT UNSIGNED NOT NULL,
    `actor_role` VARCHAR(50) NOT NULL,
    `occurred_at` DATETIME(6) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_structure_created_entity` (`entity_kind`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
