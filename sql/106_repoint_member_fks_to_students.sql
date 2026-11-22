-- ════════════════════════════════════════════════════════════════════════════
-- 106 — repoint the 12 foreign keys from `members` to `students` (ADR-0027 M5)
-- ════════════════════════════════════════════════════════════════════════════
--
-- ADR-0027 M5: "The rename happens per referrer, not per file. The 13 FK
-- referrers to `members` are re-pointed one at a time according to which entity
-- owns the row." Measured against the migration source, there are **12**
-- constraints across 12 tables (the ADR said 13; the count moved with the
-- group tables leaving in sql/104 minus the receipt added in 099 — recorded
-- here rather than silently corrected).
--
-- ── EACH REFERRER, AND HOW IT WAS CLASSIFIED ──────────────────────────────
--
-- LEARNER (row belongs to a student — re-pointed here):
--   academic_records            grades a learner earned
--   attendance_month_rollup     a learner's monthly attendance figures
--   attendance_warnings         a learner's attendance warnings
--   attendance_session_records  one learner's mark in a session
--   assessment_followups        follow-up on one learner's assessment
--   finance_member_fees         a fee charged to a learner
--   finance_transactions        a payment made against a learner's fee
--   hr_attendance               VERIFIED learner-side: it snapshots
--                               `members.current_section` at record time and
--                               is written by DeptTakerService, i.e. it marks
--                               students in a section — not staff payroll
--   member_department_periods   per-learner department active/inactive spans
--                               (M3: the learner side owns these)
--   mezmur_attendance           a learner's choir attendance (surface retired
--                               by S6; the table keeps its FK until it leaves)
--   school_registration_receipts idempotency receipt naming the enrolled learner
--
-- STAFF (row belongs to a login identity — deliberately NOT re-pointed):
--   member_staff_positions      M2: positions belong to `users`, and
--                               `user_staff_positions` (sql/105) already holds
--                               them. This table is left alone here and dropped
--                               by sql/107 with `members`.
--
-- ── WHY NOT A BLANKET ALTER ───────────────────────────────────────────────
--
-- Each constraint is dropped and recreated only if it exists, so this runs on a
-- database in any of three states: never migrated, migrated once, or already
-- re-pointed. Nothing is inferred about a table that is absent.
--
-- ON DELETE rules are preserved exactly as each table declared them. The one
-- that matters is `school_registration_receipts` = RESTRICT: a receipt must not
-- silently lose the learner it names, and that behaviour is unchanged.
--
-- ════════════════════════════════════════════════════════════════════════════

-- Each block is written out rather than looped: MariaDB does not allow WHILE
-- outside a stored program (learned by trying it — error 1064, not a silent
-- failure), and an unrolled list is easier to audit against the 12 rows above
-- anyway. Every block is guarded, so the file is safe to re-run.


-- ── academic_records ─────────────────────────────────────────────────────────────────
SET @has_tbl := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='academic_records');
SET @has_old := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE constraint_schema=DATABASE() AND table_name='academic_records'
                   AND constraint_name='fk_ar_member' AND constraint_type='FOREIGN KEY');
SET @has_new := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE constraint_schema=DATABASE() AND table_name='academic_records'
                   AND constraint_name='fk_ar_student' AND constraint_type='FOREIGN KEY');
SET @has_stu := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='students');
SET @go := (@has_tbl > 0 AND @has_stu > 0 AND @has_new = 0);

SET @s := IF(@go AND @has_old > 0,
    'ALTER TABLE `academic_records` DROP FOREIGN KEY `fk_ar_member`', 'SELECT ''skip drop''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF(@go,
    'ALTER TABLE `academic_records` ADD CONSTRAINT `fk_ar_student` FOREIGN KEY (`member_id`) REFERENCES `students` (`id`) ON DELETE CASCADE',
    'SELECT ''skip add''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── attendance_month_rollup ─────────────────────────────────────────────────────────────────
SET @has_tbl := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='attendance_month_rollup');
SET @has_old := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE constraint_schema=DATABASE() AND table_name='attendance_month_rollup'
                   AND constraint_name='fk_amr_member' AND constraint_type='FOREIGN KEY');
SET @has_new := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE constraint_schema=DATABASE() AND table_name='attendance_month_rollup'
                   AND constraint_name='fk_amr_student' AND constraint_type='FOREIGN KEY');
SET @has_stu := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='students');
SET @go := (@has_tbl > 0 AND @has_stu > 0 AND @has_new = 0);

SET @s := IF(@go AND @has_old > 0,
    'ALTER TABLE `attendance_month_rollup` DROP FOREIGN KEY `fk_amr_member`', 'SELECT ''skip drop''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF(@go,
    'ALTER TABLE `attendance_month_rollup` ADD CONSTRAINT `fk_amr_student` FOREIGN KEY (`member_id`) REFERENCES `students` (`id`) ON DELETE CASCADE',
    'SELECT ''skip add''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── attendance_warnings ─────────────────────────────────────────────────────────────────
SET @has_tbl := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='attendance_warnings');
SET @has_old := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE constraint_schema=DATABASE() AND table_name='attendance_warnings'
                   AND constraint_name='fk_aw_member' AND constraint_type='FOREIGN KEY');
SET @has_new := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE constraint_schema=DATABASE() AND table_name='attendance_warnings'
                   AND constraint_name='fk_aw_student' AND constraint_type='FOREIGN KEY');
SET @has_stu := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='students');
SET @go := (@has_tbl > 0 AND @has_stu > 0 AND @has_new = 0);

SET @s := IF(@go AND @has_old > 0,
    'ALTER TABLE `attendance_warnings` DROP FOREIGN KEY `fk_aw_member`', 'SELECT ''skip drop''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF(@go,
    'ALTER TABLE `attendance_warnings` ADD CONSTRAINT `fk_aw_student` FOREIGN KEY (`member_id`) REFERENCES `students` (`id`) ON DELETE CASCADE',
    'SELECT ''skip add''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── attendance_session_records ─────────────────────────────────────────────────────────────────
SET @has_tbl := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='attendance_session_records');
SET @has_old := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE constraint_schema=DATABASE() AND table_name='attendance_session_records'
                   AND constraint_name='fk_session_record_member' AND constraint_type='FOREIGN KEY');
SET @has_new := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE constraint_schema=DATABASE() AND table_name='attendance_session_records'
                   AND constraint_name='fk_session_record_student' AND constraint_type='FOREIGN KEY');
SET @has_stu := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='students');
SET @go := (@has_tbl > 0 AND @has_stu > 0 AND @has_new = 0);

SET @s := IF(@go AND @has_old > 0,
    'ALTER TABLE `attendance_session_records` DROP FOREIGN KEY `fk_session_record_member`', 'SELECT ''skip drop''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF(@go,
    'ALTER TABLE `attendance_session_records` ADD CONSTRAINT `fk_session_record_student` FOREIGN KEY (`member_id`) REFERENCES `students` (`id`) ON DELETE CASCADE',
    'SELECT ''skip add''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── assessment_followups ─────────────────────────────────────────────────────────────────
SET @has_tbl := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='assessment_followups');
SET @has_old := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE constraint_schema=DATABASE() AND table_name='assessment_followups'
                   AND constraint_name='fk_followup_member' AND constraint_type='FOREIGN KEY');
SET @has_new := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE constraint_schema=DATABASE() AND table_name='assessment_followups'
                   AND constraint_name='fk_followup_student' AND constraint_type='FOREIGN KEY');
SET @has_stu := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='students');
SET @go := (@has_tbl > 0 AND @has_stu > 0 AND @has_new = 0);

SET @s := IF(@go AND @has_old > 0,
    'ALTER TABLE `assessment_followups` DROP FOREIGN KEY `fk_followup_member`', 'SELECT ''skip drop''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF(@go,
    'ALTER TABLE `assessment_followups` ADD CONSTRAINT `fk_followup_student` FOREIGN KEY (`member_id`) REFERENCES `students` (`id`) ON DELETE CASCADE',
    'SELECT ''skip add''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── finance_member_fees ─────────────────────────────────────────────────────────────────
SET @has_tbl := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='finance_member_fees');
SET @has_old := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE constraint_schema=DATABASE() AND table_name='finance_member_fees'
                   AND constraint_name='fk_fee_member' AND constraint_type='FOREIGN KEY');
SET @has_new := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE constraint_schema=DATABASE() AND table_name='finance_member_fees'
                   AND constraint_name='fk_fee_student' AND constraint_type='FOREIGN KEY');
SET @has_stu := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='students');
SET @go := (@has_tbl > 0 AND @has_stu > 0 AND @has_new = 0);

SET @s := IF(@go AND @has_old > 0,
    'ALTER TABLE `finance_member_fees` DROP FOREIGN KEY `fk_fee_member`', 'SELECT ''skip drop''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF(@go,
    'ALTER TABLE `finance_member_fees` ADD CONSTRAINT `fk_fee_student` FOREIGN KEY (`member_id`) REFERENCES `students` (`id`) ON DELETE CASCADE',
    'SELECT ''skip add''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── finance_transactions ─────────────────────────────────────────────────────────────────
SET @has_tbl := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='finance_transactions');
SET @has_old := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE constraint_schema=DATABASE() AND table_name='finance_transactions'
                   AND constraint_name='fk_fin_member' AND constraint_type='FOREIGN KEY');
SET @has_new := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE constraint_schema=DATABASE() AND table_name='finance_transactions'
                   AND constraint_name='fk_fin_student' AND constraint_type='FOREIGN KEY');
SET @has_stu := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='students');
SET @go := (@has_tbl > 0 AND @has_stu > 0 AND @has_new = 0);

SET @s := IF(@go AND @has_old > 0,
    'ALTER TABLE `finance_transactions` DROP FOREIGN KEY `fk_fin_member`', 'SELECT ''skip drop''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF(@go,
    'ALTER TABLE `finance_transactions` ADD CONSTRAINT `fk_fin_student` FOREIGN KEY (`member_id`) REFERENCES `students` (`id`) ON DELETE SET NULL',
    'SELECT ''skip add''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── hr_attendance ─────────────────────────────────────────────────────────────────
SET @has_tbl := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='hr_attendance');
SET @has_old := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE constraint_schema=DATABASE() AND table_name='hr_attendance'
                   AND constraint_name='fk_hr_attendance_member' AND constraint_type='FOREIGN KEY');
SET @has_new := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE constraint_schema=DATABASE() AND table_name='hr_attendance'
                   AND constraint_name='fk_hr_attendance_student' AND constraint_type='FOREIGN KEY');
SET @has_stu := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='students');
SET @go := (@has_tbl > 0 AND @has_stu > 0 AND @has_new = 0);

SET @s := IF(@go AND @has_old > 0,
    'ALTER TABLE `hr_attendance` DROP FOREIGN KEY `fk_hr_attendance_member`', 'SELECT ''skip drop''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF(@go,
    'ALTER TABLE `hr_attendance` ADD CONSTRAINT `fk_hr_attendance_student` FOREIGN KEY (`member_id`) REFERENCES `students` (`id`) ON DELETE CASCADE',
    'SELECT ''skip add''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── member_department_periods ─────────────────────────────────────────────────────────────────
SET @has_tbl := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='member_department_periods');
SET @has_old := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE constraint_schema=DATABASE() AND table_name='member_department_periods'
                   AND constraint_name='fk_mdp_member' AND constraint_type='FOREIGN KEY');
SET @has_new := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE constraint_schema=DATABASE() AND table_name='member_department_periods'
                   AND constraint_name='fk_mdp_student' AND constraint_type='FOREIGN KEY');
SET @has_stu := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='students');
SET @go := (@has_tbl > 0 AND @has_stu > 0 AND @has_new = 0);

SET @s := IF(@go AND @has_old > 0,
    'ALTER TABLE `member_department_periods` DROP FOREIGN KEY `fk_mdp_member`', 'SELECT ''skip drop''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF(@go,
    'ALTER TABLE `member_department_periods` ADD CONSTRAINT `fk_mdp_student` FOREIGN KEY (`member_id`) REFERENCES `students` (`id`) ON DELETE CASCADE',
    'SELECT ''skip add''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── mezmur_attendance ─────────────────────────────────────────────────────────────────
SET @has_tbl := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='mezmur_attendance');
SET @has_old := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE constraint_schema=DATABASE() AND table_name='mezmur_attendance'
                   AND constraint_name='fk_mezmur_attendance_member' AND constraint_type='FOREIGN KEY');
SET @has_new := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE constraint_schema=DATABASE() AND table_name='mezmur_attendance'
                   AND constraint_name='fk_mezmur_attendance_student' AND constraint_type='FOREIGN KEY');
SET @has_stu := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='students');
SET @go := (@has_tbl > 0 AND @has_stu > 0 AND @has_new = 0);

SET @s := IF(@go AND @has_old > 0,
    'ALTER TABLE `mezmur_attendance` DROP FOREIGN KEY `fk_mezmur_attendance_member`', 'SELECT ''skip drop''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF(@go,
    'ALTER TABLE `mezmur_attendance` ADD CONSTRAINT `fk_mezmur_attendance_student` FOREIGN KEY (`member_id`) REFERENCES `students` (`id`) ON DELETE CASCADE',
    'SELECT ''skip add''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── school_registration_receipts ─────────────────────────────────────────────────────────────────
SET @has_tbl := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='school_registration_receipts');
SET @has_old := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE constraint_schema=DATABASE() AND table_name='school_registration_receipts'
                   AND constraint_name='fk_school_receipt_member' AND constraint_type='FOREIGN KEY');
SET @has_new := (SELECT COUNT(*) FROM information_schema.table_constraints
                 WHERE constraint_schema=DATABASE() AND table_name='school_registration_receipts'
                   AND constraint_name='fk_school_receipt_student' AND constraint_type='FOREIGN KEY');
SET @has_stu := (SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name='students');
SET @go := (@has_tbl > 0 AND @has_stu > 0 AND @has_new = 0);

SET @s := IF(@go AND @has_old > 0,
    'ALTER TABLE `school_registration_receipts` DROP FOREIGN KEY `fk_school_receipt_member`', 'SELECT ''skip drop''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF(@go,
    'ALTER TABLE `school_registration_receipts` ADD CONSTRAINT `fk_school_receipt_student` FOREIGN KEY (`member_id`) REFERENCES `students` (`id`) ON DELETE RESTRICT',
    'SELECT ''skip add''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── record the outcome so the next migration can be judged ─────────────────
CREATE TABLE IF NOT EXISTS `migration_106_report` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `finding`    VARCHAR(80)  NOT NULL,
    `detail`     VARCHAR(500) DEFAULT NULL,
    `measured_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @still_members := (SELECT COUNT(DISTINCT table_name)
                       FROM information_schema.referential_constraints
                       WHERE constraint_schema = DATABASE()
                         AND referenced_table_name = 'members');
SET @on_students := (SELECT COUNT(DISTINCT table_name)
                     FROM information_schema.referential_constraints
                     WHERE constraint_schema = DATABASE()
                       AND referenced_table_name = 'students');

SET @log := IF((SELECT COUNT(*) FROM `migration_106_report` WHERE `finding`='repointed_to_students')=0,
    CONCAT('INSERT INTO `migration_106_report` (`finding`,`detail`) VALUES (''repointed_to_students'',''', @on_students, '''), (''still_referencing_members'',''', @still_members, ''')'),
    'SELECT ''already logged''');
PREPARE s3 FROM @log; EXECUTE s3; DEALLOCATE PREPARE s3;
