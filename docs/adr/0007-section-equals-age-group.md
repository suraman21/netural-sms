# ADR-0007: Section and age group are one concept with one definition

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-09-12 | Maintainers |

> Recorded on 2026-10-22; the decision date is shown above. Evidence is cited so the decision can be checked; where the rationale is not documented, this is stated.

> **2026-11-16 — superseded:** [ADR-0022](0022-general-school-domain.md) makes a section a *grade section* and demotes the age group to a derived, legacy attribute. The A/B/C sections remain readable through `school_section_year_class_map` until the data mapping (D-5) is decided, so this record still describes how existing rows behave — it no longer describes the target model.

## Context

Sections and age groups were modelled separately, and different screens used different hard-coded lists, including a wrong label.

## Decision

`App\Services\MemberCategory` is the only source: A ህጻናት (7–13, `7_13`), B ማዕከላዊያን (14–17, `14_17`), C ወጣቶች (18+, `18_plus`). Since 2026-10-21, members under 7 are placed in A. Every screen, filter, report and normalisation derives from it.

## Consequences

- **Positive:** age ranges change in one place.
- **Negative:** legacy values (`18+`, alias spellings) must still be tolerated on read.

## Evidence

`admin/backend/services/MemberCategory.php`; `sql/041_section_age_source_of_truth.sql`; commit `9395f15`.
