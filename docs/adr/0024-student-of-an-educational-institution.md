# ADR-0024: A student of an educational institution — KG to Grade 12 and courses

| Status | Date | Deciders |
|---|---|---|
| Accepted — owner decision | 2026-11-16 | Product owner |

> Third record in the transformation sequence: [ADR-0022](0022-general-school-domain.md) (the domain is a school, not a ministry) → [ADR-0023](0023-no-age-group-or-age-section.md) (no age grouping) → this one (who is enrolled, and in what). Written before the code change, like its predecessors.

## Context

ADR-0022 and ADR-0023 removed the ministry and the age band but left the subject itself intact: the system still models a **member** — someone belonging to a congregation — being placed in a **section**. The owner has now stated the product plainly:

> "we are changing ssms (sunday school management system) to sms (school management system) … this school management system will handle starting from KG up to 12 and course center or colleges like a 3 month course or 2 year degree, and there will be no member, agegroup, cuz this is a normal educational institution management system instead of a spiritual sunday school management system so there will be a student not a member"
> — owner, 2026-11-16

So the subject is a **student** of an **educational institution** with two shapes of programme:

| Shape | Example | Structure |
|---|---|---|
| Laddered schooling | KG 1–3, Grade 1–12 | A stage per year; a cohort advances together |
| Fixed-duration course | 3-month "Basic Computer Skills"; 2-year "Diploma in Accounting" | An intake that starts and finishes; stages may be years, weeks, or none |

The existing `school_education_levels` → `school_grades` → `school_grade_sections` hierarchy (sql/096) already carries the laddered shape and is **age-free and ministry-free**. It is empty — no seed rows — so it is a blank slate rather than a legacy to migrate. What it lacks is the duration/award vocabulary a course or degree needs.

Scale of the word being removed, measured on this tree:

| Surface | Count |
|---|---|
| Files referencing `member` in code (excluding docs, tests, vendor) | **390** |
| Files referencing the `members` table | 222 |
| Distinct `member_*` columns in migrations | 19 |
| Files that already say `student` (the code was never consistent) | 177 |

## Decision

**S1 — The subject is a student, not a member.** The entity is `students`. `members`, `member_code`, `member_type`, `MemberDirectoryService`, `MemberCategory` and the rest of the `member*` vocabulary are retired from the domain model. This is a rename of the *concept*, not only of a table: permission names, API fields, exports, ID cards, mobile screens and the Amharic copy all follow.

**S2 — An institution offers programmes; a student is enrolled in a stage of one.** `school_education_levels` becomes the **programme** (KG, Primary, Secondary, a named college programme, a short course), `school_grades` the **stage** within it (KG 2, Grade 5, Year 2 of a diploma), and `school_grade_sections` an optional division of a stage. A programme that is a single block — a 3-month course — may have no stages and enrol directly.

**S3 — A programme carries its own duration and award.** Added to the programme: `kind` (`school` | `course` | `college`), `duration_months` (nullable — null means it runs the academic calendar), and `award` (nullable — `Certificate`, `Diploma`, `Degree`, or a deployment's own word). This is what makes "3-month course" and "2-year degree" expressible without inventing a second hierarchy.

**S4 — There is no member, no age group and no age-based section.** Restates ADR-0023 as a property of the new entity: a student has a date of birth, and nothing derives a programme, stage, section or code from it.

**S5 — Student codes are plain sequential numbers.** Owner's choice. `MEMBER_CODE_PREFIX` / `MEMBER_CODE_FORMAT` are empty today and stay empty: a new student's code is a number with no letter prefix, so it never encodes an age band, a grade or a programme and never goes stale on promotion. This supersedes ADR-0023 A5's interim and resolves **D-8**.

**S6 — The legacy domain is removed, not preserved.** This repository is a fresh clone being transformed; there is no member data to carry across, and no compatibility shim is required for it. `members`, `MemberCategory`, the church columns (`baptismal_name`, `christian_name`, `age_group`, `current_section`, `member_type`) and the Mezmur surface are removed rather than kept readable. This supersedes ADR-0022 **R1** and **R4**, and ADR-0023 **A6**, which were written on the assumption that a live deployment's data had to survive.

**S7 — Removal is still sequenced and reviewable.** "Remove everything" is not permission to break the build: each removal slice lands with the suite green, and the production repository is untouched.

## The assumption this record carries

**S6 assumes no existing installation needs its member data migrated into the new model.** If the live Felege Kidusan deployment must keep serving its current members through this transformation, S6 is wrong and the opposite applies — data is preserved and mapped. This is the single largest risk in the plan and it is the owner's call, not an engineering one. It is recorded here rather than assumed silently.

## Consequences

- **Positive:** the model finally matches the institution. One hierarchy serves KG, secondary school and a two-year diploma; a three-month course is a programme with a duration instead of a special case. The last ministry word leaves the domain.
- **Negative / risks:**
  - A 390-file rename is the largest mechanical change in the project's history and cannot be done safely in one commit. It touches permissions, exports, ID cards, QR payloads and every mobile screen.
  - Anything that referenced `member_code`, `member_type` or `age_group` must be re-decided, not merely renamed — `member_type` (regular/special/honorary) has no stated equivalent.
  - Every existing test that asserts a member-shaped payload becomes a test of the student-shaped payload; the suite will be red between slices unless each slice is internally consistent.
- **Follow-up work:** the rename slices; enrolment into a programme stage; a timetable and grading model that works for a 3-month course as well as a school year; and the report/export language.

## Evidence

- `sql/096_school_academic_structure.sql` — the programme/stage/section hierarchy, empty of seed data.
- `sql/legacy/database_schema.sql` and `sql/012_runtime_schema_baseline.sql` — the `members` table with `baptismal_name`, `christian_name`, `age_group`, `current_section`, `member_type`, `member_code`.
- `admin/backend/services/MemberCategory.php` — the age-derived category model retired by ADR-0023.
- Counts above measured with `git grep` on `ddE4f68`-descended `main` (`776b694`).
- Supersedes: ADR-0022 R1/R4, ADR-0023 A6. Resolves ADR-0022 D-8.
