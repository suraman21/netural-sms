# ADR-0027 — `members` is not one thing: the rename is a split, not a search-and-replace

- **Status:** **Accepted — owner decision 2026-11-16.** Opened as a blocker the same day; decided the same day:
  *"no in this one a student is a student and a teacher is a teacher so update it that way"*.
- **Gates:** phase 3b (services), 3c (surfaces), 3d (the guard test). It does not gate 3a, which is merged.
- **Related:** ADR-0024 S1–S7 (the subject is a student), ADR-0023 (no age group), ADR-0025 (institution levels)

## Context — what the investigation found

The plan (tracker §12.4) said phase 3b is "the 390-file member→student rename", one mechanical change. Measured on `879bc89`, the mechanical part is real but it is not the hard part:

| Measure | Count |
|---|---|
| Code files containing `member` | 370 (excl. docs/tests) |
| Occurrences of `member` in code | 7,611 |
| The `members` table itself | 1 (with 13 FK referrers) |
| `member_id` columns | in 21 migration files |
| Files named `*member*` | 47 |
| Occurrences in `tests/` | 1,786 across 181 files |

A search-and-replace over the table name would be the easy 80%. Running it would be a mistake, and this record explains why in a way that a script cannot see.

### The finding: one table holds three different kinds of person

`members` is used for learners **and** for staff. The evidence:

1. **`members.is_teacher`, `.is_staff`, `.is_committee`, `.is_volunteer`** — role flags on the learner table (`PositionSyncService::LEGACY_FLAGS`). `admin/dashboards/school_admin.php:29` counts teachers with `SELECT SUM(is_teacher=1) FROM members`. `edu_dept.php:2248` renders a "Teacher" badge from a member row.
2. **`member_staff_positions`** links a `member_id` to `staff_positions`, and `IdentityCodeService::resolveStaffSegments()` builds a *staff* identity code from a `member_id`. A member row can be a staff member with a department and a position.
3. **`member_department_periods`** tracks per-department active/inactive periods *per member* — for Education **and** Mezmur departments.
4. **`wbws_group_members`** is a separate person table with its own `baptismal_name`, `membership_status`, `full_name`. Group members are not rows in `members`.

Teachers authenticate through `users` (role `teacher`), not through `members`. So the same human can exist as a `users` row (login), a `members` row (learner **or** staff record), and possibly a `wbws_group_members` row — with nothing enforcing that these agree.

### Why "rename the table" is wrong

If `members` becomes `students`, then `member_staff_positions` becomes `student_staff_positions`, and the product asserts that **a teacher is a student**. That is false in the data and absurd in the domain. The same happens to `member_department_periods` ("a student's Mezmur department periods") and to `is_teacher` ("this student is a teacher").

Renaming the table does not translate the concept. It relabels a table that was already overloaded, and moves the overload into the new vocabulary where it is harder to see.

## Decision

**Split by what the row is, then rename. Do not rename the overloaded table first.**

1. **A student is a learner.** `students` holds the people who enrol in a programme and attend classes. It gets `student_code`, `student_type` does **not** exist (ADR-0024: `member_type` has no student equivalent), no `age_group`, no `baptismal_name`, no `is_teacher`.
2. **Staff are not students.** The role flags, `member_staff_positions` and staff identity codes move to a **staff** entity — either `users` (which already carries `role`, and is how a teacher actually logs in) or a new `staff` table linked to `users`. Which of those is an owner decision (below), not an engineering one: the login identity and the payroll identity are not necessarily the same record.
3. **`wbws_group_members`** becomes `group_members` — the `wbws_` prefix is legacy branding, and a group member is a person with a name, not necessarily a student of the institution.
4. **Guardians stay guardians.** `member_guardians` → `student_guardians`; `guardians` is already the right word.
5. **Only after 1–4** does the mechanical rename of `member_id` → `student_id` in the *learner* referrers make sense, and only for the referrers whose rows really are learners.

## The decision — a student is a student, a teacher is a teacher

The owner, asked whether the people carrying the staff flags are also students:

> "no in this one a student is a student and a teacher is a teacher so update it that way"

So the two are **disjoint**. This settles it as option A, and it settles something the codebase had left implicit for years.

**M1 — `students` is the learner table and only the learner table.** No `is_teacher`, `is_staff`, `is_committee`, `is_volunteer`. No `member_type`, no `membership_tier`. No `age_group`, no `baptismal_name` (ADR-0023, ADR-0024 S6). Those columns do not move to `students` under a different name — they leave the learner model.

**M2 — staff are staff, and they live on `users`.** A teacher already authenticates through `users` (role `teacher`), so `users` is the staff identity and carries the role. What must move off `members` and onto the staff identity is the *people* data currently read from a member row for a teacher: name, photo, phone, department, position, staff identity code. `member_staff_positions` becomes `user_staff_positions` keyed by `user_id`; `IdentityCodeService::resolveStaffSegments()` stops taking a `member_id`.

**M3 — a person is never both.** No row in `students` has a corresponding staff role, and no staff account is enrolled in a programme. This is a product rule, not a validation trick: if a school ever needs a student who also teaches, that is a **new decision**, not a data fix. The consequence is that the ambiguous `member_department_periods` (Education *and* Mezmur, per member) must be split by which side owns the row: learner attendance periods belong to `students`, staff department periods belong to the staff identity.

**M4 — no flag-based migration of existing combined rows.** This installation is a fresh clone with no live member data (ADR-0024 S6), so there is nothing to split: rows leave with the old schema. Had there been data, M3 would have required a per-row human decision, which is precisely why M3 is stated as a rule rather than a script.

**M5 — the rename happens per referrer, not per file.** The 13 FK referrers to `members` are re-pointed one at a time according to which entity owns the row: the ten learner referrers (`class_enrollments`, `attendance_*`, `assessments`, `assessment_followups`, `school_registration_receipts`, …) point at `students`; the two staff referrers (`member_staff_positions`, and staff identity codes) point at the staff identity. `member_department_periods` is split per M3. A blanket `sed` is explicitly rejected by this record.

## Consequences

- **Positive:** the split removes an overload that has been quietly confusing the model for years. It also kills `member_type`/`membership_tier` properly (they are membership tiers, meaningless for a student) instead of renaming them into nonsense like `student_type='honorary'`.
- **Because the owner chose the clean split,** the risk that dominated this record is gone: there is no person entity to invent, no "student who also teaches" to model, and no per-row human decision to make on existing data. What remains is volume, not ambiguity.
- **Negative / risks:**
  - It is **more** work than the plan said, in a different order. The schema split has to land before the rename, so phase 3b cannot be one mechanical commit.
  - Every query that joins `members` for a non-learner reason has to be re-pointed deliberately, one at a time. That is 370 files of attention, not 370 files of `sed`.
  - Staff identity codes (`IdentityCodeService`) are printed on ID cards and embedded in QR payloads. Repointing them is the ADR-0022 D8 "frozen identifier" problem again, in a sharper form.
- **What does *not* change:** ADR-0024's student vocabulary, ADR-0023's no-age-group rule, and the plain-sequential student codes (S5) all stand. This record narrows *how* they are reached, not *what* they say.

## Evidence

- `sql/legacy/database_schema.sql:42` — the `members` table and its `member_type` enum.
- `sql/020_identity_v2.sql`, `admin/backend/services/PositionSyncService.php:29` — `is_teacher`/`is_staff`/`is_committee`/`is_volunteer` on `members`.
- `sql/071_department_status_and_policy.sql:6` — `member_department_periods` described as per-member, per-department (Education/Mezmur).
- `sql/013_application_schema_completion.sql` — `wbws_group_members` as an independent person table.
- `admin/backend/services/IdentityCodeService.php:190` — staff identity segments resolved from a `member_id`.
- `api/v1/routes/teachers.php:29` — teachers are `users.role='teacher'`, a different table.
- Counts measured with `git ls-files` + regex on `879bc89`; method reproducible (`/tmp/inv*.py` in the agent transcript).
