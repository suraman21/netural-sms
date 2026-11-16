-- ─────────────────────────────────────────────────────────────────────────────
-- sql/seeds/ethiopian_ladder.sql — OPT-IN, NOT A MIGRATION
--
-- Loads the ordinary Ethiopian school ladder (KG 1–3, Grade 1–6, Middle 7–8,
-- Secondary 9–12) and the three national exam gates (Grade 6 regional,
-- Grade 8, Grade 12 ESSLCE) into an installation that runs it.
--
-- This is a seed, not a migration: it creates no schema and is not applied by
-- the migration runner. It exists because ADR-0025 I1 says an institution
-- declares its levels and nothing is assumed present — so sql/103 changes
-- structure only, and a fresh install correctly reports an empty structure
-- until somebody — the institution, or an installer on its behalf — says which
-- levels it runs.
--
-- The guard is intentionally the whole table, not each row: this file refuses
-- to run at all once the institution has any level. That gives both properties
-- that matter —
--   * idempotent: running it twice leaves the same data;
--   * non-destructive: running it against a configured institution (including
--     a college-only one, or one that deliberately deleted the ladder) changes
--     nothing, so it can never resurrect a level the institution removed.
-- Every name and sort order below is editable afterwards; nothing in the
-- application depends on these names.
--
-- Apply with:
--   mariadb <db> < sql/seeds/ethiopian_ladder.sql
-- ─────────────────────────────────────────────────────────────────────────────

INSERT INTO `school_education_levels` (`name`, `kind`, `sort_order`)
SELECT * FROM (
    SELECT 'Kindergarten' AS name, 'kg' AS kind, 10 AS sort_order UNION ALL
    SELECT 'Primary',    'primary',   20 UNION ALL
    SELECT 'Middle',     'middle',    30 UNION ALL
    SELECT 'Secondary',  'secondary', 40
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `school_education_levels`);

INSERT INTO `school_grades` (`level_id`, `name`, `sort_order`)
SELECT l.id, g.name, g.sort_order
FROM (
    SELECT 'Kindergarten' AS level_name, 'KG 1' AS name, 1 AS sort_order UNION ALL
    SELECT 'Kindergarten', 'KG 2', 2 UNION ALL
    SELECT 'Kindergarten', 'KG 3', 3 UNION ALL
    SELECT 'Primary', 'Grade 1', 1 UNION ALL
    SELECT 'Primary', 'Grade 2', 2 UNION ALL
    SELECT 'Primary', 'Grade 3', 3 UNION ALL
    SELECT 'Primary', 'Grade 4', 4 UNION ALL
    SELECT 'Primary', 'Grade 5', 5 UNION ALL
    SELECT 'Primary', 'Grade 6', 6 UNION ALL
    SELECT 'Middle',  'Grade 7', 7 UNION ALL
    SELECT 'Middle',  'Grade 8', 8 UNION ALL
    SELECT 'Secondary', 'Grade 9',  9 UNION ALL
    SELECT 'Secondary', 'Grade 10', 10 UNION ALL
    SELECT 'Secondary', 'Grade 11', 11 UNION ALL
    SELECT 'Secondary', 'Grade 12', 12
) AS g
JOIN `school_education_levels` l ON l.name = g.level_name
WHERE NOT EXISTS (SELECT 1 FROM `school_grades`);

-- National exam gates (I3). A gate is an assessment point a student must pass
-- before advancing, never an automatic promotion. Recorded against the stage so
-- that a promotion routine can refuse to advance a student past an unsat gate.
INSERT INTO `school_exam_gates` (`grade_id`, `name`, `name_am`)
SELECT g.id, e.name, e.name_am
FROM (
    SELECT 'Grade 6' AS grade_name, 'Grade 6 Regional Examination' AS name, 'የ6ኛ ክፍል ክልላዊ ፈተና' AS name_am UNION ALL
    SELECT 'Grade 8', 'Grade 8 Examination', 'የ8ኛ ክፍል ፈተና' UNION ALL
    SELECT 'Grade 12', 'Grade 12 National Examination (ESSLCE)', 'የ12ኛ ክፍል ብሔራዊ ፈተና'
) AS e
JOIN `school_grades` g ON g.name = e.grade_name
WHERE NOT EXISTS (SELECT 1 FROM `school_exam_gates`);

-- Verification:
--   SELECT kind, COUNT(*) FROM school_education_levels GROUP BY kind;
--   SELECT g.name, e.name FROM school_exam_gates e JOIN school_grades g ON g.id = e.grade_id;
