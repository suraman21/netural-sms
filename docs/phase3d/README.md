# Phase 3d — what is ready, and what is deliberately held back

This folder holds work that is **written, tested against a real database, and
not merged** because merging it in this order would break `main`.

## `106_repoint_member_fks_to_students.sql.pending`

Re-points the 11 learner foreign keys from `members` to `students`
(ADR-0027 M5). Verified on MariaDB 11.8:

- 11 keys moved, 1 deliberately left (`member_staff_positions` → belongs to
  `users`, M2)
- idempotent: re-applying changes nothing and errors nothing
- `ON DELETE SET NULL` and `ON DELETE RESTRICT` preserved on the two tables
  that declared them
- FK accepts a real student id, rejects a nonexistent one
- `RESTRICT` refused deleting a student named by a registration receipt
- `SET NULL` nulled a transaction's student on delete

## Why it is `.pending` and not `sql/106`

It was merged first, CI went red, and the failure was **correct**.

The keys point at `students`, but the application and its fixtures still write
to `members`. Two consequences, both real:

1. `tests/security/test_attendance_phase4.py` seeds `mezmur_attendance` for
   member 900005 and inserts no `students` row, so the FK rejects it —
   `ERROR 1452 … fk_mezmur_attendance_student`.
2. **35 test files** seed `members` directly. Any insert whose row id is
   re-pointed now needs a matching `students` row first.

Worse, and the reason this is not just "fix the tests": once the keys point at
`students`, `INSERT INTO members …` **succeeds silently** while writing a row
nothing can reference. A half-migrated schema is more dangerous than an
un-migrated one, because it fails by accepting data rather than rejecting it.

## The correct order

1. Point the code at `students` (the 96 files, per referrer — M5 forbids a
   blanket `sed`).
2. Point the fixtures at `students`.
3. **Then** run `106`, and drop `members` in `107`.

## `test_member_fk_repoint.py.pending`

The guard test for 106: pins the exact 11 re-pointed tables, their delete
rules, and the exclusion of `member_staff_positions`. Verified against a
deliberate violation — flattening `SET NULL` to `CASCADE` makes it fail.
Restore it to `tests/security/` in the same commit as 106.

---

# Slice 1 findings — why the code cannot move before the fixtures

## What I converted, and why it was reverted

Five services were converted to read `students` and passed `php -l`:

- `DepartmentStatusService` (4 refs, learner rows only)
- `AttendanceRiskService` (1 join)
- `AssessmentFollowupService` (2 refs)
- `SubmissionService` (5 refs)
- `MezmurSubmissionService` (2 refs)

**All five were reverted before merging.** The reason is a single line in an
e2e fixture:

```php
// tests/e2e/attendance_preparation_batch.php:24
CREATE TEMPORARY TABLE members (id INT, student_name VARCHAR(80), ...)
```

The attendance tests do not use the real schema. They build **temporary tables
named `members`** and insert into those. A service that queries `students` does
not find the fixture's rows — it finds the real (empty) table, or fails outright
in strict mode.

So the ordering constraint is stricter than documented before: **code cannot
precede fixtures, and cannot even follow them immediately**, because the
fixtures are not one fleet. Each test's temporary schema has to be renamed in
the same commit as the service it exercises.

## What that means for the slice plan

| Order | Step | Files |
|---|---|---|
| 1 | service + its own fixture, together | 5 services / 5 fixture files |
| 2 | next subsystem, same rule | … |
| 3 | `sql/106`, then `sql/107` drops `members` | last |

A slice is therefore "one service and every fixture that exercises it", not
"ten files that mention members". The earlier recommendation of ten-file slices
was wrong for this codebase and is corrected here.

## A separate bug found while doing it — not caused by the rename

**`membership_tier` does not exist, and 17 files use it.**

- Not in `sql/legacy/database_schema.sql`
- No migration adds it
- `sql/014` line 39 fails: `ERROR 1072  Key column 'membership_tier' doesn't exist`
- `sql/068` line 8 fails: `ERROR 1054  Unknown column 'membership_tier' in 'members'`
- `admin/api_export_members.php:37` **defaults to `$tier = 'permanent'`**, so the
  member export queries a non-existent column by default
- `admin/info_manage_member.php:668` has already lost its badge
  (`?? 'temporary'` always fires)

This is pre-existing and orthogonal to ADR-0027, but it is exactly the kind of
thing the remodel is meant to remove: ADR-0024 says `member_type` has no student
equivalent, and `membership_tier` (`temporary`/`permanent`) is the same
membership concept under a second name, half-built and never finished.

**Resolution:** `membership_tier` does not migrate to `students`. But fixing it
now would mean a schema change independent of the rename, so it is recorded here
and raised to the owner rather than fixed in a phase-3d commit.

Two migrations (`014`, `068`) are consequently **dead on a fresh clone** — a
fact `docs/DATABASE.md` should state, and does not yet.

## A converted file's silent column mismatch — and the guard that caught it

`admin/id_cards/view_id_card.php` was already converted to read `students` in
commit e3ece55. The query moved from `members` to `students`, but eight reads of
`$member['member_code']` survived — and `students` has `student_code`, not
`member_code`. Every card silently rendered `ID_` and an empty QR payload
(`<site>/member.php?code=`), because `fetch_assoc()` returns no key rather than
throwing. `php -l` was the only coverage the file had, so nothing caught it.

The defect was fixed on branch `fix/id-card-student-code` with a single alias:

```php
$member['member_code'] = $member['student_code'] ?? '';
```

That alias is deliberate, not an oversight: ADR-0028 A3 freezes the wire
vocabulary (`'member_code'`), so the translation from `student_code` happens at
the row in one auditable place instead of eight scattered renames.

**The guard:** `tests/security/test_converted_files_read_real_columns.py`
iterates every PHP file that reads `students` and asserts each key it reads is
either a real `students` column or an explicit alias built from one. It fails
without the alias and passes with it. This closes the blind spot that the
existing `test_wire_contract_frozen` (table-level only) left open: a table
rename that silently outruns its columns.

## Owner decisions 2026-11-16, and what the live database revealed

---

# Owner decisions 2026-11-16, and what the live database revealed

## Two answers, one consequence

> "we dont need a membership related things for students"
> "and also we dont need a temporary registration"

Both were verified against the live schema before being implemented, and both
were **already true of `sql/105`** — but the supporting evidence was wrong and is
now corrected:

| Column in production | Type | Where the exclusion is recorded |
|---|---|---|
| `membership_tier` | `enum('temporary','permanent')` default `permanent` | owner, 2026-11-16 |
| `member_type` | `enum('regular','special_regular','honorary')` | owner + ADR-0024 |
| `registration_type` | `enum('waiting','transfer','direct')` | owner ("no temporary registration") |
| `waiting_since` | `date` | owner (same) |

**Correction to an earlier claim.** I previously reported that
`membership_tier` "does not exist". That was true of `sql/` and false of
production: the column exists on the live database with a default of
`'permanent'`, and **no migration ever creates it**. The defect is in the
migration chain, not in the schema's intent.

`tests/security/test_students_column_accounting.py` now holds all 85 production
`members` columns as a fixture and fails if any one of them is neither kept,
renamed, removed by a stated rule, nor relocated.

## Column accounting: 85 production columns → 63 on `students`

| Fate | Count | Examples |
|---|---|---|
| Kept (1 renamed) | 55 | `student_name`, `date_of_birth`, `id_card_status`, `member_code`→`student_code` |
| Owner decision | 4 | `membership_tier`, `member_type`, `registration_type`, `waiting_since` |
| ADR rule (S6 church, M1/M3 staff, ADR-0023 age) | 19 | `baptismal_name`, `is_teacher`…`is_dept_head_8`, `age_group`, `current_section` |
| Relocated to `guardians` | 6 | `guardian_city`…`guardian_house` — the data is not lost, it is normalised |
| Removed with the church document set | 1 | `doc_spiritual_path` |

**Unaccounted: none.** That is now enforced by a test rather than an audit.

## What the live database exposed about the migration chain

The supplied dump is 96 tables and 1,035 columns. A database built from
`sql/legacy/database_schema.sql` + `012` + `013` + every numbered migration has
140 tables — but is **missing 15 that production has**, and every gap is
explained:

- **12 tables and 22 columns are created only by PHP migrations** under
  `sql/legacy/php-migrations/`: `assessments`, `subjects`, `department_tasks`,
  the four `material_*` tables, `finance_budgets`, `finance_categories`,
  `member_changes`, `shared_documents`, and the mezmur quarantine table.
- **3 tables are the groups tables retired by N-4** — their absence is correct.
- `finance_transactions` is missing **13** of its production columns and
  `finance_member_fees` **8**. `sql/003` adds foreign keys to columns the `.sql`
  chain never creates: the finance module in `sql/` is a shell.

`tests/e2e/provision_env.sh` already works around this by extracting
`CREATE TABLE` statements out of PHP files with a regex. That workaround exists
**only in the test harness** — the deployment path has no equivalent, which is
why `docs/DATABASE.md` could claim the chain was complete.

**Proposed `107`:** formalise the PHP-only DDL as SQL so a database can be
rebuilt from `sql/` alone, and add the four missing column sets. That is a
prerequisite for a trustworthy fresh clone, and it is independent of the
member→student remodel, so it can be done first.

---

# `sql/107` — formalising the PHP-only DDL (in progress)

## The authoritative source is the PHP migrations, not the dump

`provision_env.sh` already contains every fact needed, as shell workarounds:

| Workaround | Line | What it reveals |
|---|---|---|
| extract `CREATE TABLE` from `php-migrations/admin/*.php` | 93–112 | 9+ tables exist only there |
| `ALTER TABLE departments ADD sort_order` | 115 | column in the pre-versioning era |
| `ALTER TABLE members ADD membership_tier` | 120 | column from the PHP-migration era |
| `CREATE TABLE ..._archive LIKE ...` | 124 | archive tables from 003's failing tail |
| re-run `003 014 017 021 029 070 075 076` | 129 | eight migrations depend on the above |

Verified: for all 11 tables the PHP migrations define, their column lists match
production **exactly** (15=15 for `finance_transactions`, 16=16 for
`assessments`, and so on). `migration_055_mezmur_link_quarantine` is a
twelfth, but it is defined in `sql/055` itself and 055 **aborts by design** on a
fresh clone (`SIGNAL SQLSTATE '45000'` — it requires seeded hymn categories
85/116 that only exist in the live taxonomy).

## The ten migrations that cannot apply to a fresh database

Each for a different reason, and only three are real defects:

| Migration | Fails because | Verdict |
|---|---|---|
| `003` | head adds indexes/tables that 012/013 already made; tail's archive tables need `activity_logs` | ordering |
| `014` | `members.membership_tier` never created | **defect** |
| `017` | needs `subjects`/`assessments` (PHP-only) | **defect** |
| `021` | needs mezmur taxonomy seeded | data-dependent |
| `029` | needs `subjects` (PHP-only) | **defect** |
| `055` | aborts deliberately without taxonomy | by design |
| `068` | `membership_tier` | **defect** |
| `075` | FK to `subjects` (PHP-only) | **defect** |
| `076` | FK to `subjects`/`classes` | **defect** |
| `083` | telegram approval state | ordering |

## Plan

`107` will contain, all idempotent and guarded:

1. the 11 PHP-only `CREATE TABLE` statements, verbatim from the PHP source
2. `departments.sort_order`
3. `members.membership_tier` — **kept as a legacy column even though students
   will not have it**, because `014` and `068` index and alter it, and a chain
   that cannot run is worse than a chain with one unused column. `sql/105`
   deliberately does not copy it to `students`.
4. the three `*_archive` tables from 003's tail

Because `sql/107` sorts after every migration that currently needs these, it
cannot repair them in one pass — the existing numbered files claim their
history. The honest options are (a) `107` plus a re-run list in the deployment
guide, or (b) fold the repair into the next *baseline*. This is recorded rather
than guessed, and will be settled when `107` is written.
