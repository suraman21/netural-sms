-- Dormant compatibility foundation; does not create or infer any mappings.
-- Requires 096 plus compatible InnoDB academic_years/classes (INT UNSIGNED IDs).
-- Migration-chain collisions and deployment preflight must be reviewed first.
-- Not an enrollment workflow: mapping lifecycle, authorization, audit,
-- concurrency and historical label semantics are not implemented here.
CREATE TABLE IF NOT EXISTS `school_section_year_class_map` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `academic_year_id` INT UNSIGNED NOT NULL,
    `section_id` BIGINT UNSIGNED NOT NULL,
    `class_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_school_map_year_section` (`academic_year_id`, `section_id`),
    UNIQUE KEY `uq_school_map_year_class` (`academic_year_id`, `class_id`),
    KEY `idx_school_map_section` (`section_id`),
    KEY `idx_school_map_class` (`class_id`),
    CONSTRAINT `fk_school_map_year` FOREIGN KEY (`academic_year_id`)
        REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_school_map_section` FOREIGN KEY (`section_id`)
        REFERENCES `school_grade_sections` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_school_map_class` FOREIGN KEY (`class_id`)
        REFERENCES `classes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
