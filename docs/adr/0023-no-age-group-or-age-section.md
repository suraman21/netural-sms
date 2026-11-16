# ADR-0023: No age group and no age-based section

| Status | Date | Deciders |
|---|---|---|
| Accepted — owner decision, resolving [ADR-0022](0022-general-school-domain.md) deferred decision **D-5** | 2026-11-16 | Product owner |

> **2026-11-16 — narrowed by [ADR-0024](0024-student-of-an-educational-institution.md):** the subject is a *student* of an educational institution, not a member of a congregation. This record's A1–A4 stand unchanged; its **A6** (legacy values readable, never migrated) is superseded — there is no legacy data to keep, and the church columns are removed rather than preserved.

> Written before the code change, like ADR-0022. It records a decision and its consequences; the implementation is phase 3 of the remodel and has not started.

## Context

The Sunday-school model has no class hierarchy to speak of: a member belongs to a *section*, a section *is* an age band, and the band is derived from the member's date of birth. That is one concept with three names ([ADR-0007](0007-section-equals-age-group.md)), and it is load-bearing:

| Fact | Where it lives |
|---|---|
| Three categories A / B / C = ህጻናት / ማዕከላዊያን / ወጣቶች, keyed to `7_13` / `14_17` / `18_plus` | `admin/backend/services/MemberCategory.php`, 25 files, 139 references |
| The section *name* is derived from the age code, and the age code from the name | `MemberCategory::groupForAge`, `sectionAm`, `ageGroupForSectionAm`, `sqlSectionExpr` |
| Imports compute a member's group from their age | `admin/api_import_members.php` (`groupForAge($age)`) |
| Attendance rollups and reports group by the derived section | `AttendanceMonthRollupService`, `MemberReportService`, `ReportQueryService` |
| The member's identity code is prefixed with the category letter | `MemberCodeFormat` / `IdentityCodeService`; `IdentityMigrationService` uses `MemberCategory::letterFor(age_group)` |
| Columns | `members.age_group`, `classes.age_group`, `members.current_section`, `classes.section` — 70 files reference `age_group`, 68 reference `current_section` |

[ADR-0022](0022-general-school-domain.md) D3 replaced the concept with a grade section but left one door open: it described `members.age_group` as "a legacy *input* that is mapped once and then read through the mapped section", and it deferred the mapping itself as **D-5** ("by age, by current class, or by a review queue?").

The owner has now closed that door: **there is no age group and no age-based section in this product.** Age is not an input to the model at all.

## Options considered

1. **Map age → grade once (what D-5 implied).** Keep `age_group` as a compatibility input, derive a grade from the age band at migration time, then stop using it. Rejected by the owner: it keeps age as a modelling input and institutionalises the assumption that a school groups learners by age.
2. **Keep A/B/C as named "levels" independent of age.** Rename the three categories and keep them as stream labels. Rejected: the categories *are* age bands with age ranges, and their Amharic names describe life stages. Renaming them would leave the age semantics inside the data while claiming otherwise.
3. **No age concept at all (chosen).** A member belongs to a class, which belongs to a grade (and optionally a named section within that grade). Age is a date of birth, nothing more.

## Decision

**A1 — There is no age group.** No table column, service, filter, report, export, screen or API field models an age band. `members.age_group` and `classes.age_group` are retired concepts.

**A2 — There is no age-based section.** A section is a division of a *grade* — `school_grade_sections` (`grade_id` + free-text `name`, per [ADR-0022](0022-general-school-domain.md) D3). What a section is named is a deployment decision (a letter, a stream, a shift); it is never an age band, and nothing derives it from a birth date.

**A3 — Age is a date of birth, not a classification.** A member's `date_of_birth` (and the derived `age` used for display) remains data about a person. **No rule may assign a member to a class, section, grade or category from it.** Imports that currently compute a group from age (`api_import_members.php`) must instead take an explicit grade, or leave the member unassigned for review.

**A4 — `MemberCategory` is deleted, not refactored.** It is the definition of the concept being removed. Its callers move to grade/section identity. Nothing keeps emitting the old strings: a compatibility shim emitting `ህጻናት` / `ማዕከላዊያን` / `ወጣቶች` would preserve precisely the taxonomy this record deletes.

**A5 — Existing print and identity artefacts are not retroactively invalidated.** Member codes already printed on ID cards keep their prefix. The prefix scheme for *new* codes must be re-decided (D-8), because the current one is `MemberCategory::letterFor(age_group)` and that function is going away. Renumbering the existing population remains governed by ADR-0022 **R3**: only through the reviewed CLI tool, with a dry run and owner sign-off.

**A6 — Legacy data is read-only, never migrated.** Per ADR-0022 **R4**, `age_group`, `current_section` and `classes.section` values stay in place and readable for historical reports and audit answers, but no new code writes them, no screen offers them, and no migration rewrites them. Nothing maps them onto grades. If a deployment wants its existing members in grades, that is a *data entry* act by its own staff, not a schema transformation.

## Consequences

- **Positive:** the model becomes the one a school actually uses — enrolment in a grade, not a life-stage bucket. The last structural church concept leaves the schema path. It also removes a whole class of derived-state bugs: today a member's section can disagree with their birth date, and different code paths resolve the disagreement differently.
- **Negative / risks:**
  - **Existing members have no grade.** After phase 3 they are enrolled in a class (their current one, if they have one) but their grade is unknown until someone enters it. There is no automatic answer: assigning grades from age is exactly what A3 forbids.
  - **Member codes for new members have no scheme** until D-8 is answered. Codes continue to be issued with the old prefix in the interim, so the two schemes will coexist.
  - **Reports that group by section must group by grade**, and a report run across the cut-over will not line up with one run before it.
  - **Imports change shape**: a spreadsheet that only carries age can no longer be imported without a grade column or a review step.
- **Follow-up work:** D-8 (code prefix scheme); an import contract that takes grade explicitly; report/attendance grouping by grade; removal of `MemberCategory` and its 25 callers; a UI that shows grade where it showed age band.

## Evidence

- `admin/backend/services/MemberCategory.php` — the three categories, their age ranges, `groupForAge`, `sectionAm`, `sqlSectionExpr`, `letterFor`.
- `admin/api_import_members.php` lines 282–287 — group derived from age, or from a section cell.
- `admin/backend/services/IdentityMigrationService.php` lines 41–70 — `member_code` prefix from `letterFor((string)$row['age_group'])`.
- `sql/012_runtime_schema_baseline.sql`, `sql/019_member_types_and_under6_removal.sql` — the columns and the retired `under6` enum value.
- Counts: 70 files reference `age_group`, 68 reference `current_section`, 25 reference `MemberCategory`.
- Supersedes the "mapped once" clause of [ADR-0022](0022-general-school-domain.md) D3 and resolves its D-5.
