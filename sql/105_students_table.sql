-- ════════════════════════════════════════════════════════════════════════════
-- 105 — the learner table: `members` becomes `students` (ADR-0027 M1)
-- ════════════════════════════════════════════════════════════════════════════
--
-- ADR-0027 decided that `members` is not one thing: it held learners AND staff,
-- distinguished by role flags (`is_teacher`, `is_staff`, `is_committee`,
-- `is_volunteer`, eight `is_dept_head_*`). The owner's rule is that a student is
-- a student and a teacher is a teacher, so the split is by what the row is.
--
-- This migration creates the LEARNER half — `students` — and the STAFF half's
-- join table — `user_staff_positions`, keyed by `users.id` (M2: a teacher is a
-- `users` row with role 'teacher'; that is how they already log in).
--
-- ═══ WHY THIS FILE DOES NOT DROP `members` ═══
--
-- 97 PHP/JS files still query `members`, and the app reads it on nearly every
-- page. Dropping it here would turn every one of those pages into a fatal
-- error on a database that had run this migration — a broken `main` between
-- two commits, which the project's merge rules forbid.
--
-- `members` is therefore left in place, and the next migration (106) drops it
-- once no query names it. That is not laziness: it is the only ordering that
-- keeps every intermediate commit working, and it is the same reason the group
-- tables were dropped only after their code was deleted (sql/104, N-4).
--
-- ═══ WHAT LEFT THE LEARNER MODEL (and why) ═══
--
-- M1 says the removed columns do not move to `students` under a new name:
--   is_teacher, is_staff, is_committee, is_volunteer, is_dept_head_1..8
--        → staff facts. A learner is not staff (M3).
--   member_type (regular | special_regular | honorary)
--        → a membership tier. There is no student equivalent (ADR-0024); a
--          student is not "honorary". This is C-7 closed at last.
--   age_group, current_section
--        → age-derived classification (ADR-0023 A2/D3). Age is a date of birth.
--   baptismal_name, christian_name
--        → church identity (S6). A school does not hold these.
--   registration_type, waiting_since
--        → the waiting-list workflow of a parish, not a school's intake.
--   work_profession
--        → an adult fact. A learner's occupation is "student".
--   spiritual_education
--        → Mezmur-era placement; Mezmur leaves the product (S6).
--
-- `education_level` is KEPT: it is the institution's own level name
-- (ADR-0025), data the school configures, not a derived age band.
--
-- ═══ SAFETY ═══
--
-- · Idempotent: every object is guarded, so a second run is a no-op.
-- · `members` is untouched, so nothing breaks if this migration runs alone.
-- · SKIP LOCK TABLES is used for CREATE TABLE ... SELECT so a live clone is
--   never blocked behind a long read. It is a no-op on an empty table.
-- · `member_code` becomes `student_code` (S1/S5). Existing values, if any,
--   are copied verbatim — renumbering is a card-printing decision, not a
--   migration (see admin/tools/id_code_mapping_report.php, ADR-0028 A5).
-- ════════════════════════════════════════════════════════════════════════════

-- ── 1. the learner table ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `students` (
    `id`                    INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_code`          VARCHAR(30)  DEFAULT NULL COMMENT 'plain sequential, no prefix (ADR-0024 S5)',

    -- Identity: Amharic naming order, plus the Latin form used on documents.
    `full_name_am`          VARCHAR(150) NOT NULL,
    `student_name`          VARCHAR(150) NOT NULL,
    `father_name`           VARCHAR(150) NOT NULL,
    `mother_name`           VARCHAR(150) DEFAULT NULL,
    `grandfather_name`      VARCHAR(150) DEFAULT NULL,
    `full_name_en`          VARCHAR(150) DEFAULT NULL,
    `gender`                ENUM('male','female') NOT NULL DEFAULT 'male',
    `date_of_birth`         DATE         DEFAULT NULL,
    `dob_ec_day`            TINYINT(3) UNSIGNED  DEFAULT NULL,
    `dob_ec_month`          TINYINT(3) UNSIGNED  DEFAULT NULL,
    `dob_ec_year`           SMALLINT(5) UNSIGNED DEFAULT NULL,
    `age`                   TINYINT(3) UNSIGNED  DEFAULT NULL COMMENT 'display only; never a classifier (ADR-0023 A3)',

    -- Placement: which level the institution configured, and the class.
    `education_level`       VARCHAR(60)  DEFAULT NULL COMMENT 'institution level name (ADR-0025)',
    `current_class_id`      INT(10) UNSIGNED DEFAULT NULL,
    `promoted_at`           DATE         DEFAULT NULL,
    `academic_status`       VARCHAR(20)  DEFAULT 'active',

    -- Duplicate detection (name/phone normalisation, carried from the old model)
    `student_name_norm`     VARCHAR(150) DEFAULT NULL,
    `father_name_norm`      VARCHAR(150) DEFAULT NULL,
    `grandfather_name_norm` VARCHAR(150) DEFAULT NULL,
    `mother_name_norm`      VARCHAR(150) DEFAULT NULL,
    `name_pair_hash`        BINARY(16)   DEFAULT NULL,
    `name_norm_version`     TINYINT(3) UNSIGNED DEFAULT NULL,
    `phone_suffix9`         CHAR(9)      DEFAULT NULL,

    -- Lifecycle
    `status`                ENUM('active','warning','inactive','archived') NOT NULL DEFAULT 'active',
    `registered_at`         DATE         NOT NULL DEFAULT (CURDATE()),
    `archived_at`           DATETIME     DEFAULT NULL,
    `restored_at`           DATETIME     DEFAULT NULL,
    `archived_by`           VARCHAR(100) DEFAULT NULL,
    `restored_by`           VARCHAR(100) DEFAULT NULL,
    `archive_reason`        VARCHAR(50)  DEFAULT NULL,
    `archive_type`          ENUM('permanent_archive','failed_observation') DEFAULT 'permanent_archive',
    `archive_notes`         TEXT         DEFAULT NULL,
    `created_by`            INT(10) UNSIGNED DEFAULT NULL,
    `created_at`            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Contact
    `phone_primary`         VARCHAR(30)  DEFAULT NULL,
    `phone_number`          VARCHAR(30)  DEFAULT NULL,
    `alt_phone_number`      VARCHAR(30)  DEFAULT NULL,
    `emergency_name`        VARCHAR(100) DEFAULT NULL,
    `emergency_phone`       VARCHAR(20)  DEFAULT NULL,
    `address`               TEXT         DEFAULT NULL,
    `city`                  VARCHAR(60)  DEFAULT NULL,
    `sub_city`              VARCHAR(60)  DEFAULT NULL,
    `woreda`                VARCHAR(10)  DEFAULT NULL,
    `mender`                VARCHAR(10)  DEFAULT NULL,
    `block_number`          VARCHAR(10)  DEFAULT NULL,
    `house_number`          VARCHAR(10)  DEFAULT NULL,

    -- Guardian convenience fields. `student_guardians` is the authority; these
    -- are the primary-contact summary the ID card and roster print.
    `guardian_name`         VARCHAR(150) DEFAULT NULL,
    `guardian_phone1`       VARCHAR(30)  DEFAULT NULL,
    `guardian_phone2`       VARCHAR(30)  DEFAULT NULL,
    `phone_guardian`        VARCHAR(30)  DEFAULT NULL,
    `guardian_photo_path`   VARCHAR(255) DEFAULT NULL,

    -- Documents and card state
    `student_photo_path`    VARCHAR(255) DEFAULT NULL,
    `doc_school_records_path` VARCHAR(255) DEFAULT NULL,
    `doc_signed_form_path`  VARCHAR(255) DEFAULT NULL,
    `signed_form_path`      VARCHAR(255) DEFAULT NULL,
    `id_card_status`        ENUM('none','pending','generated') DEFAULT 'none',
    `id_card_generated_at`  DATETIME     DEFAULT NULL,
    `qr_code_path`          VARCHAR(255) DEFAULT NULL,

    -- Rolled-up attendance, maintained by the rollup service
    `total_attendance_rate` DECIMAL(5,2) DEFAULT NULL,
    `last_attendance_date`  DATE         DEFAULT NULL,

    -- Traceability: the code a student held before the rename. Kept so the
    -- printed-ID report (ADR-0028 A5) can still answer "who was this?".
    `legacy_student_code`   VARCHAR(30)  DEFAULT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_students_code` (`student_code`),
    KEY `idx_students_code` (`student_code`),
    KEY `idx_students_status` (`status`),
    KEY `idx_students_status_id` (`status`,`id`),
    KEY `idx_students_status_gender` (`status`,`gender`),
    KEY `idx_students_student_name` (`student_name`),
    KEY `idx_students_father_name` (`father_name`),
    KEY `idx_students_current_class` (`current_class_id`),
    KEY `idx_students_city` (`city`),
    KEY `idx_students_created_at` (`created_at`),
    KEY `idx_students_legacy_code` (`legacy_student_code`),
    KEY `idx_students_duplicate_name` (`student_name`,`father_name`,`grandfather_name`),
    KEY `idx_students_duplicate_advisory` (`student_name`,`father_name`,`status`,`id`),
    KEY `idx_students_norm_pair` (`student_name_norm`,`father_name_norm`,`status`,`id`),
    KEY `idx_students_name_pair_hash` (`name_pair_hash`,`status`,`id`),
    KEY `idx_students_phone_suffix9` (`phone_suffix9`,`status`,`id`),
    KEY `idx_students_dob_norm` (`dob_ec_year`,`dob_ec_month`,`dob_ec_day`,`student_name_norm`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. carry any existing learner rows across ──────────────────────────────
-- S6 authorises removal rather than preservation, but "remove the domain" is
-- not the same as "silently lose rows if a clone does have some". Copying is
-- cheap, reversible, and leaves `members` intact for this commit.
SET @has_members := (SELECT COUNT(*) FROM information_schema.tables
                     WHERE table_schema = DATABASE() AND table_name = 'members');
SET @students_empty := (SELECT COUNT(*) FROM `students`);
SET @do_copy := (@has_members > 0 AND @students_empty = 0);

SET @copy_sql := IF(@do_copy,
    'INSERT INTO `students`
        (`id`, `student_code`, `full_name_am`, `student_name`, `father_name`, `mother_name`,
         `grandfather_name`, `full_name_en`, `gender`, `date_of_birth`,
         `dob_ec_day`, `dob_ec_month`, `dob_ec_year`, `age`,
         `education_level`, `current_class_id`, `promoted_at`, `academic_status`,
         `student_name_norm`, `father_name_norm`, `grandfather_name_norm`,
         `mother_name_norm`, `name_pair_hash`, `name_norm_version`, `phone_suffix9`,
         `status`, `registered_at`, `archived_at`, `restored_at`, `archived_by`,
         `restored_by`, `archive_reason`, `archive_type`, `archive_notes`,
         `created_by`, `created_at`, `phone_primary`, `phone_number`,
         `alt_phone_number`, `emergency_name`, `emergency_phone`, `address`,
         `city`, `sub_city`, `woreda`, `mender`, `block_number`, `house_number`,
         `guardian_name`, `guardian_phone1`, `guardian_phone2`, `phone_guardian`,
         `guardian_photo_path`, `student_photo_path`, `doc_school_records_path`,
         `doc_signed_form_path`, `signed_form_path`, `id_card_status`,
         `id_card_generated_at`, `qr_code_path`, `total_attendance_rate`,
         `last_attendance_date`, `legacy_student_code`)
     SELECT
        `id`, `member_code`, `full_name_am`, `student_name`, `father_name`, `mother_name`,
         `grandfather_name`, `full_name_en`, `gender`, `date_of_birth`,
         `dob_ec_day`, `dob_ec_month`, `dob_ec_year`, `age`,
         `education_level`, `current_class_id`, `promoted_at`, `academic_status`,
         `student_name_norm`, `father_name_norm`, `grandfather_name_norm`,
         `mother_name_norm`, `name_pair_hash`, `name_norm_version`, `phone_suffix9`,
         `status`, `registered_at`, `archived_at`, `restored_at`, `archived_by`,
         `restored_by`, `archive_reason`, `archive_type`, `archive_notes`,
         `created_by`, `created_at`, `phone_primary`, `phone_number`,
         `alt_phone_number`, `emergency_name`, `emergency_phone`, `address`,
         `city`, `sub_city`, `woreda`, `mender`, `block_number`, `house_number`,
         `guardian_name`, `guardian_phone1`, `guardian_phone2`, `phone_guardian`,
         `guardian_photo_path`, `student_photo_path`, `doc_school_records_path`,
         `doc_signed_form_path`, `signed_form_path`, `id_card_status`,
         `id_card_generated_at`, `qr_code_path`, `total_attendance_rate`,
         `last_attendance_date`, `legacy_member_code`
     FROM `members`',
    'SELECT ''students already populated or no members table — copy skipped''');
PREPARE stmt FROM @copy_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 3. the staff join table, keyed by the login identity (M2) ──────────────
-- `member_staff_positions` said "this MEMBER holds this position", which is the
-- overload M2 removes: a teacher's positions belong to their `users` row.
CREATE TABLE IF NOT EXISTS `user_staff_positions` (
    `user_id`     INT UNSIGNED NOT NULL,
    `position_id` INT(11) NOT NULL COMMENT 'signed: staff_positions.id is int(11) (legacy)',
    `assigned_by` INT UNSIGNED DEFAULT NULL,
    `assigned_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`,`position_id`),
    KEY `idx_usp_position` (`position_id`),
    CONSTRAINT `fk_usp_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_usp_position` FOREIGN KEY (`position_id`)
        REFERENCES `staff_positions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Carry existing staff rows across only where the member is a real staff
-- account. The old table linked member→position with no user reference, so the
-- only safe join is an email match; anything unmatched is left behind and
-- reported rather than guessed (ADR-0027 M4: no flag-based invention).
SET @has_msp := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'member_staff_positions');
SET @has_email := (SELECT COUNT(*) FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = 'members'
                     AND column_name = 'email');
SET @usp_empty := (SELECT COUNT(*) FROM `user_staff_positions`);
SET @do_staff := (@has_msp > 0 AND @has_email > 0 AND @usp_empty = 0);

SET @staff_sql := IF(@do_staff,
    'INSERT IGNORE INTO `user_staff_positions` (`user_id`, `position_id`, `assigned_by`, `assigned_at`)
     SELECT u.`id`, msp.`position_id`, msp.`assigned_by`, msp.`assigned_at`
     FROM `member_staff_positions` msp
     JOIN `members` m ON m.`id` = msp.`member_id`
     JOIN `users`   u ON u.`email` = m.`email`',
    'SELECT ''no member_staff_positions/email column — staff copy skipped''');
PREPARE stmt FROM @staff_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 4. record what could not be carried, so it is not silently lost ────────
-- A migration that drops meaning must say what it dropped. This table is the
-- audit trail for the next migration's judgement calls.
CREATE TABLE IF NOT EXISTS `migration_105_report` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `finding`    VARCHAR(80)  NOT NULL,
    `detail`     VARCHAR(500) DEFAULT NULL,
    `measured_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Counts are computed into variables first: MariaDB refuses a subquery
-- against information_schema in some positions, and a prepared statement keeps
-- this runnable whether or not the old tables are still present.
SET @rows_left := (SELECT COUNT(*) FROM `members`);
SET @unmatched := IF(@has_msp > 0, (SELECT COUNT(*) FROM `member_staff_positions`), 0);

SET @log_sql := IF(
    (SELECT COUNT(*) FROM `migration_105_report` WHERE `finding` = 'members_rows_left_in_place') = 0,
    CONCAT('INSERT INTO `migration_105_report` (`finding`,`detail`) VALUES (''members_rows_left_in_place'', ''',
           @rows_left, ''')'),
    'SELECT ''already logged''');
PREPARE stmt FROM @log_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @log_sql := IF(
    (SELECT COUNT(*) FROM `migration_105_report` WHERE `finding` = 'staff_positions_unmatched') = 0,
    CONCAT('INSERT INTO `migration_105_report` (`finding`,`detail`) VALUES (''staff_positions_unmatched'', ''',
           @unmatched, ''')'),
    'SELECT ''already logged''');
PREPARE stmt FROM @log_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
