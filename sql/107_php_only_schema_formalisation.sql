-- ════════════════════════════════════════════════════════════════════════════
-- 107 — formalise the DDL that only existed in PHP (fresh-clone repair)
-- ════════════════════════════════════════════════════════════════════════════
--
-- THE PROBLEM, measured against the live database (dump 2026-10-08, 96 tables):
--
--   A database built from sql/legacy/database_schema.sql + 012 + 013 + every
--   numbered migration is missing 15 tables and 22 columns that production has.
--   Twelve of those tables and both missing column sets exist ONLY inside the
--   PHP migrations under sql/legacy/php-migrations/, which no deployment path
--   runs. The result: `sql/003` adds foreign keys to columns that the chain
--   never creates, and the finance and materials subsystems are shells.
--
-- This file writes those definitions down as SQL, so a database can be rebuilt
-- from `sql/` alone. Column lists were verified equal to production for all
-- eleven tables (e.g. finance_transactions 15/15, assessments 16/16).
--
-- ── WHY THIS IS 107 AND NOT AN EDIT TO 002/003/004 ────────────────────────
--
-- Because 002/003/004 already ran in the live database. Editing a migration
-- that has been applied changes history rather than the schema: a fresh clone
-- and the live system would then disagree about what "003" means, and the
-- disagreement would never surface as a failure. Adding a new, additive,
-- idempotent migration is the only change that is safe in both directions.
--
-- ── WHAT THIS FIXES, AND WHAT IT DOES NOT ─────────────────────────────────
--
-- FIXES: a fresh clone now has assessments, subjects, the materials and
-- finance tables, departments.sort_order, members.membership_tier, and the
-- archive tables — everything the rest of the chain assumes.
--
-- DOES NOT FIX: migrations 014, 017, 029, 068, 075, 076 already ran (and some
-- failed) before 107 creates what they need. A single ordered chain cannot
-- repair its own past. After this migration those files can be re-run
-- successfully; the deployment guide must say so, and CI verifies it.
--
-- ── ON members.membership_tier ────────────────────────────────────────────
--
-- The owner has decided there are no membership concepts for students, and
-- sql/105 accordingly creates no such column on `students`. It is created here
-- anyway, as a legacy column on the legacy table, for one reason: 014 indexes
-- it and 068 alters around it, and a chain that cannot run is a worse defect
-- than one unused column. It disappears when `members` is dropped in 108.
--
-- ════════════════════════════════════════════════════════════════════════════


-- ═══ 1. the eleven tables that existed only in PHP ═════════════════════════
-- Verbatim from sql/legacy/php-migrations/{backend,admin}/00{2,3,4}_*.php.
-- IF NOT EXISTS on every one: a live database already has these, and this
-- migration must be a no-op there.

CREATE TABLE IF NOT EXISTS `subjects` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subject_name` VARCHAR(100) NOT NULL COMMENT 'e.g., መጽሐፍ ቅዱስ, ቅዳሴ',
    `subject_name_en` VARCHAR(100) DEFAULT NULL,
    `subject_code` VARCHAR(20) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `subject_code` (`subject_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `assessments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `class_id` INT UNSIGNED NOT NULL,
    `subject_id` INT UNSIGNED NOT NULL,
    `academic_year_id` INT UNSIGNED NOT NULL,
    `term_id` INT UNSIGNED DEFAULT NULL,
    `assessment_name` VARCHAR(100) NOT NULL COMMENT 'e.g., Quiz 1, Midterm, Final Exam',
    `assessment_type` ENUM('test', 'quiz', 'midterm', 'final', 'assignment', 'project', 'participation', 'other') NOT NULL DEFAULT 'test',
    `weight_percentage` DECIMAL(5,2) NOT NULL COMMENT 'Weight in total grade, e.g., 10.00 for 10%',
    `max_score` DECIMAL(6,2) NOT NULL DEFAULT 100.00 COMMENT 'Maximum possible score',
    `description` TEXT DEFAULT NULL,
    `due_date` DATE DEFAULT NULL,
    `assessment_order` TINYINT UNSIGNED DEFAULT 1 COMMENT 'Display order',
    `is_published` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether grades are published to students',
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `class_id` (`class_id`),
    KEY `subject_id` (`subject_id`),
    KEY `academic_year_id` (`academic_year_id`),
    KEY `term_id` (`term_id`),
    CONSTRAINT `fk_assess_class` FOREIGN KEY (`class_id`) REFERENCES `classes`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_assess_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_assess_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_assess_term` FOREIGN KEY (`term_id`) REFERENCES `academic_terms`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `department_tasks` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `task_type` VARCHAR(50) DEFAULT NULL COMMENT 'approval, review, action, info',
    `priority` ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
    `status` ENUM('pending', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    `from_dept` VARCHAR(50) NOT NULL COMMENT 'Originating department',
    `from_user_id` INT UNSIGNED DEFAULT NULL,
    `to_dept` VARCHAR(50) DEFAULT NULL COMMENT 'Target department',
    `to_user_id` INT UNSIGNED DEFAULT NULL COMMENT 'Specific assignee',
    `related_member_id` INT UNSIGNED DEFAULT NULL COMMENT 'If task is about a member',
    `related_data` JSON DEFAULT NULL COMMENT 'Additional context',
    `due_date` DATE DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `completed_by` INT UNSIGNED DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `status` (`status`),
    KEY `from_dept` (`from_dept`),
    KEY `to_dept` (`to_dept`),
    KEY `to_user_id` (`to_user_id`),
    KEY `related_member_id` (`related_member_id`),
    KEY `due_date` (`due_date`),
    CONSTRAINT `fk_task_member` FOREIGN KEY (`related_member_id`) REFERENCES `members`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_changes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `member_id` INT UNSIGNED NOT NULL,
    `change_type` VARCHAR(50) NOT NULL COMMENT 'registered, updated, archived, role_changed, class_enrolled, promoted',
    `field_changed` VARCHAR(100) DEFAULT NULL COMMENT 'Specific field that changed',
    `old_value` TEXT DEFAULT NULL,
    `new_value` TEXT DEFAULT NULL,
    `change_summary` VARCHAR(255) DEFAULT NULL COMMENT 'Human readable summary',
    `changed_by_dept` VARCHAR(50) DEFAULT NULL,
    `changed_by_user` INT UNSIGNED DEFAULT NULL,
    `requires_sync` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Does this need other depts to be notified',
    `synced_to` VARCHAR(255) DEFAULT NULL COMMENT 'Which depts have acknowledged',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `member_id` (`member_id`),
    KEY `change_type` (`change_type`),
    KEY `changed_by_dept` (`changed_by_dept`),
    KEY `requires_sync` (`requires_sync`),
    KEY `created_at` (`created_at`),
    CONSTRAINT `fk_mc_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shared_documents` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `file_type` VARCHAR(50) DEFAULT NULL,
    `file_size` INT UNSIGNED DEFAULT NULL COMMENT 'Size in bytes',
    `category` VARCHAR(50) DEFAULT NULL COMMENT 'report, form, announcement, policy',
    `uploaded_by` INT UNSIGNED DEFAULT NULL,
    `uploaded_dept` VARCHAR(50) DEFAULT NULL,
    `visibility` ENUM('all', 'departments_only', 'specific') NOT NULL DEFAULT 'all',
    `visible_to` VARCHAR(255) DEFAULT NULL COMMENT 'Comma-separated dept codes if specific',
    `download_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
    `expires_at` DATE DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `category` (`category`),
    KEY `uploaded_dept` (`uploaded_dept`),
    KEY `visibility` (`visibility`),
    KEY `is_pinned` (`is_pinned`),
    KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `finance_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `type` ENUM('income','expense') NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `finance_budgets` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `category_id` INT UNSIGNED DEFAULT NULL,
    `ec_year` SMALLINT UNSIGNED NOT NULL,
    `budgeted_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `description` VARCHAR(255) DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_cat_year` (`category_id`, `ec_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `finance_member_fees` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `member_id` INT UNSIGNED NOT NULL,
    `fee_type` VARCHAR(100) NOT NULL DEFAULT 'monthly',
    `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `ec_month` TINYINT UNSIGNED DEFAULT NULL,
    `ec_year` SMALLINT UNSIGNED DEFAULT NULL,
    `paid_date` DATE DEFAULT NULL,
    `status` ENUM('paid','unpaid','partial') DEFAULT 'unpaid',
    `recorded_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_member` (`member_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `finance_transactions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `type` ENUM('income','expense') NOT NULL,
    `category_id` INT UNSIGNED DEFAULT NULL,
    `member_id` INT UNSIGNED DEFAULT NULL,
    `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `description` VARCHAR(500) DEFAULT NULL,
    `receipt_number` VARCHAR(50) DEFAULT NULL,
    `payment_method` ENUM('cash','bank_transfer','mobile_money','check','other') DEFAULT 'cash',
    `transaction_date` DATE NOT NULL,
    `ec_month` TINYINT UNSIGNED DEFAULT NULL,
    `ec_year` SMALLINT UNSIGNED DEFAULT NULL,
    `recorded_by` INT UNSIGNED DEFAULT NULL,
    `status` ENUM('confirmed','pending','cancelled') DEFAULT 'confirmed',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_type` (`type`),
    KEY `idx_date` (`transaction_date`),
    KEY `idx_member` (`member_id`),
    KEY `idx_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `material_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `material_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `category_id` INT UNSIGNED DEFAULT NULL,
    `description` VARCHAR(500) DEFAULT NULL,
    `quantity` INT DEFAULT 0,
    `min_quantity` INT DEFAULT 0,
    `unit` VARCHAR(30) DEFAULT 'piece',
    `location` VARCHAR(100) DEFAULT NULL,
    `condition_status` ENUM('good','fair','poor','damaged','disposed') DEFAULT 'good',
    `purchase_date` DATE DEFAULT NULL,
    `purchase_price` DECIMAL(12,2) DEFAULT NULL,
    `status` ENUM('in_stock','low_stock','out_of_stock','maintenance') DEFAULT 'in_stock',
    `added_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_category` (`category_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `material_requests` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `item_id` INT UNSIGNED DEFAULT NULL,
    `item_name` VARCHAR(150) DEFAULT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `requested_by` VARCHAR(100) NOT NULL,
    `department` VARCHAR(100) DEFAULT NULL,
    `reason` VARCHAR(500) DEFAULT NULL,
    `status` ENUM('pending','approved','denied','fulfilled') DEFAULT 'pending',
    `approved_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `material_transactions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `item_id` INT UNSIGNED NOT NULL,
    `type` ENUM('incoming','outgoing','adjustment','disposal') NOT NULL,
    `quantity` INT NOT NULL DEFAULT 0,
    `reason` VARCHAR(255) DEFAULT NULL,
    `handled_by` VARCHAR(100) DEFAULT NULL,
    `recorded_by` INT UNSIGNED DEFAULT NULL,
    `transaction_date` DATE NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_item` (`item_id`),
    KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ═══ 2. columns the pre-versioning era created by hand ════════════════════
-- provision_env.sh has done this with `ALTER TABLE ... ADD COLUMN` since the
-- harness was written (lines 115 and 120). Same effect, now recorded in the
-- chain instead of only in a shell script.

SET @has_dept := (SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema=DATABASE() AND table_name='departments');
SET @has_sort := (SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema=DATABASE() AND table_name='departments'
                    AND column_name='sort_order');
SET @s := IF(@has_dept > 0 AND @has_sort = 0,
    'ALTER TABLE `departments` ADD COLUMN `sort_order` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0',
    'SELECT ''departments.sort_order present or table absent''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @has_mem := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='members');
SET @has_tier := (SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema=DATABASE() AND table_name='members'
                    AND column_name='membership_tier');
SET @s := IF(@has_mem > 0 AND @has_tier = 0,
    'ALTER TABLE `members` ADD COLUMN `membership_tier` ENUM(''temporary'',''permanent'') NOT NULL DEFAULT ''permanent'' AFTER `status`',
    'SELECT ''members.membership_tier present or table absent''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ═══ 3. archive tables from 003's failing tail ════════════════════════════
-- 003 creates these AFTER statements that fail on a re-run, so on a fresh
-- database they never appear. Created unconditionally here; LIKE against a
-- missing source table would error, so each is guarded.

SET @s := IF((SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema=DATABASE() AND table_name='attendance') > 0,
    'CREATE TABLE IF NOT EXISTS `attendance_archive` LIKE `attendance`',
    'SELECT ''attendance absent, archive skipped''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema=DATABASE() AND table_name='academic_records') > 0,
    'CREATE TABLE IF NOT EXISTS `academic_records_archive` LIKE `academic_records`',
    'SELECT ''academic_records absent, archive skipped''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF((SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema=DATABASE() AND table_name='activity_logs') > 0,
    'CREATE TABLE IF NOT EXISTS `activity_logs_archive` LIKE `activity_logs`',
    'SELECT ''activity_logs absent, archive skipped''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ═══ 4. record what this migration found and did ══════════════════════════
CREATE TABLE IF NOT EXISTS `migration_107_report` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `finding`     VARCHAR(80)  NOT NULL,
    `detail`      VARCHAR(255) DEFAULT NULL,
    `measured_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @missing := (SELECT COUNT(*) FROM (
    SELECT 'subjects' AS t UNION ALL SELECT 'assessments' UNION ALL
    SELECT 'department_tasks' UNION ALL SELECT 'finance_transactions' UNION ALL
    SELECT 'material_items' UNION ALL SELECT 'shared_documents'
) AS need WHERE need.t NOT IN (SELECT table_name FROM information_schema.tables
                               WHERE table_schema=DATABASE()));
SET @s := IF((SELECT COUNT(*) FROM `migration_107_report`
              WHERE `finding`='core_tables_still_missing') = 0,
    CONCAT('INSERT INTO `migration_107_report` (`finding`,`detail`) VALUES (''core_tables_still_missing'',''', @missing, ''')'),
    'SELECT ''already logged''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
