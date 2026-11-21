# ADR-0025: An institution configures its levels; KG–12 follows the Ethiopian workflow

| Status | Date | Deciders |
|---|---|---|
| Accepted — owner decision | 2026-11-16 | Product owner |

> Fourth record in the transformation sequence: [ADR-0022](0022-general-school-domain.md) (no ministry) → [ADR-0023](0023-no-age-group-or-age-section.md) (no age grouping) → [ADR-0024](0024-student-of-an-educational-institution.md) (a student of an institution) → this one (what an institution *is*, and how each of its levels runs).

## Context

ADR-0024 established a programme → stage → section hierarchy serving KG–12 and fixed-duration courses. The owner has now said what an installation actually looks like:

> "an institution can configure its own type like kg + elementary + high school + college … the college will teach the courses and degree and diploma and the kg up to high school will follow a professional normal Ethiopian education workflow"
> — owner, 2026-11-16

Two facts follow, and they are different in kind:

1. **Levels compose.** One installation is not "a KG school" or "a college"; it is fitted out with whichever levels that institution operates — all four, or only two. A KG-only kindergarten and a full KG→college campus run the same code.
2. **The levels do not run the same way.** KG–12 is a ladder: a cohort advances a grade each year, sits national exams at fixed points, and the year is divided by semesters. A college runs awards: a student enrols in a programme of fixed duration and leaves with a certificate, a diploma or a degree. Modelling both as "classes in an academic year" would force one of them to lie.

What exists today, and whether it carries the load:

| Piece | State | Verdict |
|---|---|---|
| `school_education_levels` → `school_grades` → `school_grade_sections` | sql/096, age-free, **no seed rows** | Carries the hierarchy; needs level *kind* and duration |
| `academic_years` / `academic_terms` (`term_number`, `is_current`) | sql/013 | Carries semesters for the ladder; a 3-month course does not fit three terms |
| `class_enrollments.promoted_from` | sql/013 | **Promotion is already modelled** — enrol into next year's class, record the predecessor |
| `classes.level_order` | sql/012 | Carries ordering for report sorting |
| National exam gates | absent | Not modelled at all |
| Institution's own level configuration | absent | Levels are global tables with no per-institution declaration |

## Decision

**I1 — An institution declares its levels; nothing is assumed present.** The set of `school_education_levels` rows *is* the institution's configuration. A deployment with one row is a one-level institution; a deployment with four is a campus. No code may assume a particular level exists — not "Grade 1 must exist", not "there is always a secondary school". Every teacher, enrolment, report and timetable path must work with whatever the institution configured, including a single level.

**I2 — Each level declares what kind it is.** `school_education_levels.kind` takes `kg` | `primary` | `middle` | `secondary` | `college` | `course`. The kind is not decoration: it selects the workflow (I3 vs I4) and the vocabulary (grade vs year vs stage).

**I3 — KG–12 follows the Ethiopian school workflow.** For `kg` / `primary` / `middle` / `secondary`:

- **Naming**: KG 1–3, Grade 1–12, in the Ethiopian convention (Primary 1–6, Middle 7–8, Secondary 9–12).
- **Year**: an academic year, divided into **semesters** (`academic_terms`), with the Ethiopian calendar as the institution's display calendar (`school_calendar_configuration.mode`, ADR-0022 D4; what a *new* installation defaults to remains open).
- **Progression**: a student advances one stage per year. `class_enrollments.promoted_from` already records the predecessor, so promotion is an annual act over a cohort, not a per-student guess.
- **National gates**: grade 6 (regional), grade 8, and grade 12 (ESSLCE) are exam points, not automatic promotions. They are modelled as **gates** a student passes, not as grades they are assigned.
- **No age**: restates ADR-0023. A grade is not an age band, and nothing derives a placement from a birth date.

**I4 — A college runs programmes and awards.** For `college` and `course` levels:

- A programme carries `duration_months` and `award` (Certificate | Diploma | Degree), per ADR-0024 S3.
- Progression is by **completion of the programme**, not by annual grade advancement. A two-year diploma has Year 1 and Year 2 as stages; a three-month course may have no stages and enrol directly.
- **No semesters are required.** A short course that runs for twelve weeks must not be forced into a three-term year. Where a college chooses to use semesters, the same `academic_terms` rows serve.

**I5 — The workflow is chosen by the level, never by the code path.** A report, an enrolment screen or a promotion routine asks the level what kind it is and behaves accordingly. There is no "if this is the Sunday school" branch and no institution-wide mode flag that flips the whole system between school and college behaviour.

**I6 — One institution per installation.** Resolves ADR-0022 **D-6**: an installation serves one institution, configured with one or more levels. Multi-institution tenancy is not a goal, and no schema key is introduced for it. If that changes, it is a new record, not an assumption added quietly here.

## Consequences

- **Positive:** the same code serves a kindergarten, a full KG→12 campus and a college without forks. The college stops being a special case bolted onto a school timetable. And because the level set is data, an institution that later opens a college adds rows rather than waiting for a release.
- **Negative / risks:**
  - Every screen that assumed a school-shaped year (three terms, one promotion per student per year) must consult the level kind. That is more conditional logic, and it must be tested per kind, not just for the school case.
  - A college in an installation that also runs KG–12 shares `academic_years`; a 2-year diploma spans two *academic years*, so "enrolled class for this year" is not sufficient identity for a college student. The enrolment model needs a programme-enrolment concept distinct from the annual class enrolment.
  - National exam gates are new data with no precedent in this codebase; getting them wrong (offering promotion where an exam is required) is a correctness risk, not a cosmetic one.
- **Follow-up work:** level kind in the schema; the standard Ethiopian ladder as an **opt-in seed** (`sql/seeds/ethiopian_ladder.sql`), never as a migration default; programme enrolment for colleges; exam gates; and a test per level kind.

## Amendment, 2026-11-16 — the ladder is a seed, not a default

The first draft of `sql/103` seeded the Ethiopian ladder when the installation was empty, on the theory that a fresh clone wants a usable school. CI rejected it: `tests/security/test_school_structure_read.py` asserts that an installation with no academic structure returns `{"levels": [], "grades": [], "sections": []}`, and that test is right for a reason that outlives the test — a migration that invents a curriculum contradicts I1 directly. An institution that runs only a college would be handed a KG–12 ladder it must then delete, and "the migration created it" is an odd provenance for configuration the institution is supposed to own.

So `sql/103` is now structure only, and the ladder moved to `sql/seeds/ethiopian_ladder.sql`, which is explicitly **not a migration** and is applied deliberately by the institution or its installer. Its guard is the whole table rather than each row, which buys two properties: re-running it is idempotent, and running it against an already-configured institution — including a college-only one, or one that deliberately deleted the ladder — does nothing, so a seed can never resurrect a level an institution removed. `tests/e2e/run_institution_levels_schema.sh` now proves the empty install stays empty, the ladder loads only on request, the seed is idempotent, and the college-only institution survives both a migration re-apply and a seed run.

## Evidence

- `sql/096_school_academic_structure.sql` — the level/grade/section hierarchy, no seed rows.
- `sql/013_application_schema_completion.sql` — `academic_terms` (`term_number`, `is_current`), `class_enrollments.promoted_from`.
- `sql/012_runtime_schema_baseline.sql` — `classes.level_order`.
- Counts and absence of national-exam modelling verified with `git grep` on `main` at `776b694`.
- Resolves ADR-0022 D-6; implements ADR-0024 S2–S3 for the college case.
