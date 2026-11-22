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
