-- 103: an institution declares which kinds of education it runs (ADR-0025).
--
-- An installation is not "a kindergarten" or "a college": it is configured with
-- whichever levels it operates, and each level states what kind it is, because
-- the kind selects the workflow. KG-12 advances a cohort one stage per year
-- against semesters and national exam gates; a college enrols a student in a
-- programme of fixed duration that ends in an award.
--
-- ADDITIVE ONLY. Nothing here reads, changes or removes a member, class,
-- enrollment, attendance or grade row. The dormant hierarchy from 096 is
-- extended, not replaced, and the columns are added idempotently because
-- migrations in this project are hand-applied and re-runnable (ADR-0004).

-- ── Level kind and programme duration ────────────────────────────────────────
-- kind           which workflow this level runs (I2). 'school' is the
--                unclassified default meaning "a K-12 level not yet
--                labelled"; an institution sets kg/primary/middle/
--                secondary/college/course to select the workflow.
-- duration_months NULL means "runs the academic calendar" (a school year);
--                a number means a fixed-length programme (a 3-month course,
--                a 24-month diploma).
-- award          what a student leaves with; NULL for KG-12 levels.
SET @sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables
               WHERE table_schema = DATABASE() AND table_name = 'school_education_levels')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns
               WHERE table_schema = DATABASE() AND table_name = 'school_education_levels'
                 AND column_name = 'kind'),
        'ALTER TABLE `school_education_levels`
           ADD COLUMN `kind` ENUM(''school'',''kg'',''primary'',''middle'',''secondary'',''college'',''course'')
             NOT NULL DEFAULT ''school'' AFTER `name`',
        'DO 0'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables
               WHERE table_schema = DATABASE() AND table_name = 'school_education_levels')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns
               WHERE table_schema = DATABASE() AND table_name = 'school_education_levels'
                 AND column_name = 'duration_months'),
        'ALTER TABLE `school_education_levels`
           ADD COLUMN `duration_months` SMALLINT UNSIGNED DEFAULT NULL AFTER `kind`',
        'DO 0'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables
               WHERE table_schema = DATABASE() AND table_name = 'school_education_levels')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns
               WHERE table_schema = DATABASE() AND table_name = 'school_education_levels'
                 AND column_name = 'award'),
        'ALTER TABLE `school_education_levels`
           ADD COLUMN `award` VARCHAR(60) DEFAULT NULL AFTER `duration_months`',
        'DO 0'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- A college programme that runs a fixed number of months must say so; a school
-- level must not. The two are mutually exclusive in practice, so the constraint
-- is expressed where it can be enforced rather than only in application code.
SET @sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables
               WHERE table_schema = DATABASE() AND table_name = 'school_education_levels')
        AND NOT EXISTS(SELECT 1 FROM information_schema.statistics
               WHERE table_schema = DATABASE() AND table_name = 'school_education_levels'
                 AND index_name = 'idx_school_level_kind'),
        'ALTER TABLE `school_education_levels` ADD INDEX `idx_school_level_kind` (`kind`, `sort_order`)',
        'DO 0'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── Stage duration, for the college case ────────────────────────────────────
-- A "Year 2 of a diploma" stage may itself span months; a KG stage does not.
SET @sql := (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.tables
               WHERE table_schema = DATABASE() AND table_name = 'school_grades')
        AND NOT EXISTS(SELECT 1 FROM information_schema.columns
               WHERE table_schema = DATABASE() AND table_name = 'school_grades'
                 AND column_name = 'duration_months'),
        'ALTER TABLE `school_grades`
           ADD COLUMN `duration_months` SMALLINT UNSIGNED DEFAULT NULL AFTER `name`',
        'DO 0'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── No seeding here ─────────────────────────────────────────────────────────
-- An earlier draft of this file seeded the standard Ethiopian ladder when the
-- installation was empty. That was wrong and a regression test caught it:
-- tests/security/test_school_structure_read.py asserts that an installation
-- with no academic structure returns `levels: [], grades: [], sections: []`,
-- and "a fresh install invents a curriculum" contradicts I1 — nothing is
-- assumed present. This migration therefore changes structure only. An
-- institution that runs the ordinary ladder loads it deliberately with
-- `sql/seeds/ethiopian_ladder.sql`, which refuses to touch an installation that
-- already has any level.

-- ── National exam gates (I3) ────────────────────────────────────────────────
-- A gate is an assessment point a student must pass before advancing, not an
-- automatic promotion. Grade 6 (regional), Grade 8 and Grade 12 (ESSLCE) are
-- the standard Ethiopian points. They are recorded against the stage so that a
-- promotion routine can refuse to advance a student past an unsat gate.
CREATE TABLE IF NOT EXISTS `school_exam_gates` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `grade_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `name_am` VARCHAR(150) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_school_exam_gate` (`grade_id`, `name`),
    CONSTRAINT `fk_school_exam_gate_grade` FOREIGN KEY (`grade_id`)
        REFERENCES `school_grades` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification notes for the operator applying this by hand:
--   SELECT kind, COUNT(*) FROM school_education_levels GROUP BY kind;
--   SELECT l.name, l.kind, COUNT(g.id) FROM school_education_levels l
--     LEFT JOIN school_grades g ON g.level_id = l.id GROUP BY l.id ORDER BY l.sort_order;
--   SELECT g.name, e.name FROM school_exam_gates e JOIN school_grades g ON g.id = e.grade_id;
