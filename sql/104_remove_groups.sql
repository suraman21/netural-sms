-- ============================================================
-- 104_remove_groups.sql   (IDEMPOTENT / RE-RUNNABLE)
--
-- Owner decision N-4, 2026-11-16:
--   "remove group related things or use a student instead"
--
-- Groups & Associations were a Sunday-school concept: a *group* was a
-- parish-level association with its own person table (`wbws_group_members`),
-- its own leaders, and an `is_under_sunday_school` flag separating "our" groups
-- from the parish's. A school has no such thing — a student is enrolled in a
-- programme stage (ADR-0025), not sorted into an association.
--
-- The code went first (admin/groups.php, admin/backend/groups_api.php,
-- admin/dashboards/groups.php, the redirect shim, the nav entry, the feature
-- gate, the AI overview block and the super-admin integrity checks). This
-- migration removes the storage that is left.
--
-- WHY NO DATA MIGRATION: this repository is a fresh clone and the legacy domain
-- is being removed rather than carried over (ADR-0024 S6, ADR-0027 M4). There is
-- no live group data to preserve and no student to map a group member onto —
-- inventing one would fabricate enrolment records. If an installation ever
-- needs this, that is a per-row human decision, not a script.
--
-- WHY THE DROP IS GUARDED: migrations in this project are hand-applied and
-- re-runnable (ADR-0004). Every statement checks information_schema first, so a
-- re-run is a no-op and a half-applied migration is safe to run again.
-- ============================================================

-- Drop in FK order: members -> leaders -> groups.
SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.tables
           WHERE table_schema = DATABASE() AND table_name = 'wbws_group_members'),
    'DROP TABLE `wbws_group_members`',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Guardian aliases referenced nothing here, but group leaders did reference
-- groups; dropping leaders before groups keeps the FK graph satisfied even on a
-- deployment where the constraints were created in a different order.
SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.tables
           WHERE table_schema = DATABASE() AND table_name = 'wbws_group_leaders'),
    'DROP TABLE `wbws_group_leaders`',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.tables
           WHERE table_schema = DATABASE() AND table_name = 'wbws_groups'),
    'DROP TABLE `wbws_groups`',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- No feature-flag cleanup is needed: FeatureGate stores capabilities as PHP
-- constants (FEATURE_*), so removing 'groups' from code removes the capability.
-- This migration deliberately has no DELETE for a table that does not exist.
--
-- Verification for the operator applying this by hand:
--   SELECT table_name FROM information_schema.tables
--    WHERE table_schema = DATABASE() AND table_name LIKE 'wbws_group%';   -- empty
--   git grep -n "wbws_group" -- '*.php'                                    -- no code hits
