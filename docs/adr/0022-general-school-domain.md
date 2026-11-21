# ADR-0022: A general school domain, with the Mezmur ministry retired

| Status | Date | Deciders |
|---|---|---|
| Accepted — owner scope decision (option C), with implementation phases reviewed separately | 2026-11-16 | Product owner |

> **2026-11-16 — D-5 resolved, and D3 narrowed:** the owner has decided there is no age group and no age-based section in this product. [ADR-0023](0023-no-age-group-or-age-section.md) is the record: age is a date of birth and never a classification, `MemberCategory` is deleted rather than shimmed, and D3's "legacy input mapped once" path is closed — legacy rows stay readable but nothing maps them to grades.

> Unlike ADRs 0001–0020, which were written from a finished codebase, this record was written **before** the work. Each clause therefore names the phase that implements it and the evidence that exists today, so a future reader can check whether the claim became true. Clauses that depend on an unanswered owner decision are listed under [Deferred decisions](#deferred-decisions) and are **not** decided here.

## Context

FKSS is a Sunday school management system built for one congregation, and the ministry is not decoration — it is in the data model, the identifiers and the release surface:

| Church-specific concept | Where it is load-bearing today |
|---|---|
| Section = age group (A/B/C ↔ 7–13, 14–17, 18+) | `members.age_group` and `classes.age_group` are `VARCHAR(20)`; `MemberCategory` appears in 37 tracked files; the letter prefixes are printed on ID cards and embedded in QR payloads |
| Baptismal identity | `members.baptismal_name`; 55 tracked files mention baptism |
| Parish, denomination, religious invocation | `PARISH_NAME_EN`, `DENOMINATION_EN`, `RELIGIOUS_INVOCATION` in `school_config.php`; printed on report cards, ID cards and the landing page; 21 tracked files mention parish |
| Ethiopian calendar as the only calendar | [ADR-0008](0008-ethiopian-calendar-only.md); conversion at every display and export edge |
| Mezmur ministry | 318 tracked files; **16** migrations whose filename names Mezmur (`021`–`025`, `030`–`036`, `038`–`040`, `055`); 24 Dart files under a `mezmur` path, 17 of them screens; 6 `tests/security/test_mezmur_*.py` modules |

At the same time a school-agnostic backbone already exists and is inert: the ACM identity/office model (44 commits), Telegram-assisted registration (92 commits, feature-flagged off), and the November school layer — `sql/095`–`099` creating 8 tables (`school_calendar_configuration` + audit, `school_education_levels`, `school_grades`, `school_grade_sections` + audit, `school_section_year_class_map`, `school_registration_receipts`), ten `School*` service classes in `admin/backend/services/`, two endpoints and one prototype page. None of it is reachable from the running product: `admin/access_control.php` has no `ROLE_MAP` entry for `school_structure*` or `school_calendar*`, no dashboard links to the page, and `school_registrar` is not an assignable role.

So the codebase can already express a general school; the product still is not one. This record decides which of the two the product becomes.

Constraints that shape every option:

- **One shared-hosting deployment**, PHP + MariaDB, no shell, no Composer, no cron ([ADR-0001](0001-php-monolith-on-shared-hosting.md)); acceptance and promotion are separate owner decisions ([ADR-0021](0021-development-acceptance-before-production.md)).
- **Migrations are hand-applied, additive and unledgered** ([ADR-0004](0004-manual-idempotent-sql-migrations.md)); there is no verified snapshot of the production schema (`docs/DATABASE.md`). Any statement that "drops the church tables" is therefore a data-destruction act, not a refactor.
- **The Android client is offline-first** ([ADR-0003](0003-offline-first-mobile.md)); unsent local work must never be discarded to make a migration simpler.
- **Part of the client contract is frozen**: `applicationId`, `apiBaseUrl`, `applicationId`-bound signing, `API_NAME`, `EXPORT_PREFIX`, PWA cache names and the `fk*` table prefixes. Renaming them without shipping a release invalidates installed apps and cached assets ([ADR-0005](0005-direct-apk-distribution.md), branch guard in `BRANCHING.md`).

## Options considered

1. **Display-only rebrand.** Neutralise the visible strings, keep the model. Cheapest by an order of magnitude, and reversible. Rejected: the church model survives in the parts that are hardest to change later — `age_group` enums, `baptismal_name`, A/B/C identity codes on physical ID cards, and Sunday-based attendance semantics. A school that must enter "18+" for an adult learner has not been given a school system, and the A/B/C codes would keep encoding a ministry taxonomy in every export and QR payload.
2. **Flag-gated hide.** Put Mezmur and the church fields behind flags, ship one binary. Rejected: 318 files and 16 migrations of ministry remain load-bearing and must keep passing tests, review and migration ordering; the mobile app still ships the 17 Mezmur screens. A flag defers a decision rather than making one, and the cost of carrying both domains forever exceeds the cost of removing one.
3. **Full remodel (chosen).** Replace the domain model with the general-school one, retire the ministry, and keep compatibility only where the frozen client contract requires it.

## Decision

**D1 — The product is a general school management system.** No church-specific concept is normative in the domain model, the schema, the API surface, the exports or the identity documents.

**D2 — The Mezmur ministry is retired.** It is not part of the product: no route, screen, service, storage contract or test remains at the end of phase 7. Removal is sequenced and reversible per phase; it is not a flag that can be switched back on.

**D3 — A section is a grade section, not an age group.** `school_grade_sections` (grade × section letter, per academic year, mapped to the legacy `classes` row through `school_section_year_class_map`) becomes the identity of a section. `members.current_section` and `members.age_group` become legacy *inputs* that are mapped once and then read through the mapped section. **This supersedes [ADR-0007](0007-section-equals-age-group.md)**: "section" and "age group" are no longer the same concept.

**D4 — Calendar display is a deployment property, not a constant.** `school_calendar_configuration.mode` (`ethiopian` | `gregorian`, with a revision guard for writes) governs display and export. Dates continue to be stored as Gregorian dates. **This partially supersedes [ADR-0008](0008-ethiopian-calendar-only.md)**: the Ethiopian calendar stops being universal, and remains the correct behaviour for the existing deployment only because that deployment configures it. Which mode a *new* installation starts in is deferred (D-4 below).

**D5 — Attendance belongs to Education only.** With Mezmur retired there is no second attendance owner, so attendance ownership is singular: Education (class sheets, lesson-level sheets, warnings) and nobody else. **This supersedes the Mezmur half of [ADR-0009](0009-attendance-ownership.md)**; its HR half (HR takes none; HR tables remain a read-only archive) stands unchanged.

**D6 — Mezmur media has no storage contract.** No object-storage bucket, no presigned upload flow, no signed-URL playback remains part of the product. **This supersedes [ADR-0015](0015-mezmur-media-object-storage.md)** entirely.

**D7 — Church display identity is configuration, not a constant.** Parish name, denomination and religious invocation are optional deployment configuration; a deployment that configures none prints none, and no code path assumes they are set. This is phase 2 and is display-and-export only: it changes no stored value.

**D8 — Compatibility identifiers stay frozen.** Branding changes never rename `API_NAME`, `EXPORT_PREFIX`, PWA cache names, `applicationId`, `apiBaseUrl`, the `fk*`/`ssms*` table prefixes or any on-disk path the client or a running deployment depends on. New names appear in the user-facing surface only until a release can carry the rename safely.

**D9 — Registration remains web-only.** [ADR-0017](0017-no-member-registration-on-mobile.md) is reaffirmed, not superseded: the new `school_registrar` seat, the school registration receipt and the student-registration input all live on the web, behind the existing ACM authentication and the existing duplicate checks. Mobile may edit guardian data; it may not create members.

**D10 — This record removes code, not data.** See the retirement policy below. No clause of this ADR authorises deleting a table, a row, a stored file or a client's unsent queue.

## Retirement policy for church-specific data

**R1 — Tables are kept when the code is removed.** The 16 Mezmur migrations and the church columns stay applied and in place. Retiring a domain means *stop reading and writing it*, not *erase its history*: with hand-applied migrations, no ledger and no verified production schema, an unledgered `DROP` is unrecoverable. Dropping is a separate act that needs its own owner authorisation and a verified backup (D-7).

**R2 — Unsent mobile work is drained or quarantined, never discarded.** The client's local schema carries the `pending_mezmur` and `pending_hymn_ops` outboxes alongside ~20 hymn tables. Before those tables are removed, any queued rows are reported to the user, settled or quarantined, and only then may a **versioned** local-database migration drop them. Project rule, from ADR-0003 and the release checklist: logout and cleanup must not clear pending or uncertain work.

**R3 — Identity codes are not renumbered without the reviewed tool.** The A-/B-/C- prefixes are printed on physical ID cards and embedded in QR payloads. Any renumbering runs through `admin/tools/migrate_identity_codes.php`, with a dry run on synthetic data, a documented rollback and owner sign-off on the churn (phase 4, D-8).

**R4 — Legacy values stay readable indefinitely.** A/B/C rows, `age_group` values and `current_section` text continue to be read and exported for existing records, through a mapping layer, so that historical reports, ID cards and audit answers remain verifiable. No phase may require a historical row to be rewritten in order to render.

**R5 — Church-specific behaviour is switched off before it is deleted.** Phase 7 order: flag off → confirm no reachable route → drain outboxes → remove code and tests → keep tables. Each step is separately reviewable, and no step may ship in the same release as the model swap (phase 3) or the identity-code migration (phase 4).

## Deferred decisions

These are owner decisions, not engineering choices. They are deliberately **not** decided by this record, and the phases that depend on them do not start until they are answered.

| # | Question | Blocks |
|---|---|---|
| D-2 | What is the product called? (name, tagline, Amharic name, app label, PWA name) | Phase 2 |
| D-4 | Which calendar mode does a **new** installation default to? (the existing deployment stays Ethiopian) | Phase 6 |
| D-5 | How are existing A/B/C and `age_group` rows mapped onto grades and sections — by age, by current class, or by a review queue? | Phase 3 |
| D-6 | Is single-school tenancy a permanent product constraint, or must the schema carry a school/organisation key? | Any multi-school claim |
| D-7 | Is dropping the retired church and Mezmur tables authorised, and under what backup evidence? | Phase 7 |
| D-8 | What identity-code scheme replaces A-/B-/C- — grade-based, neutral, or none? | Phase 4 |

## Consequences

- **Positive:** one domain model instead of two; the church concepts leave the schema, the identifiers and the exports; the dormant school layer gains a decision record it can be implemented against; the removal is sequenced so that any phase can be reverted without data loss.
- **Negative / risks:** phases 3, 4 and 7 carry real regression risk (enrollment/attendance/report-card reads, printed ID codes, and a mobile local-database migration respectively). Removing Mezmur removes a feature that current users use; the desktop/web surface keeps working until phase 7, and nothing is deleted before the drain in R2. Documentation debt grows while the product is mid-move: the PRD, glossary and manual keep describing the church product until phase 8.
- **Follow-up work:** phases 0–8 of the remodel plan; the six deferred decisions above; and a documentation pass in phase 8 that rewrites README/PRD/GLOSSARY/onboarding against the new domain rather than patching them.

## Evidence

- Phase 0 (already complete): the Backend checks gate was red on `dde4f68`; it is repaired and locally verified. A red gate cannot accept any of the phases above under ADR-0021.
- Dormant school layer: `sql/095`–`099`; `admin/backend/services/School*.php`; `admin/school_structure.php`, `admin/api_school_structure*.php`; absence of `ROLE_MAP` entries verified in `admin/access_control.php`.
- Mezmur footprint: `ls sql/*mezmur*.sql` (16 files); `find Mobile/wbws_flutter_app/lib -path '*mezmur*' -name '*.dart'` (24 files); `tests/security/test_mezmur_*.py` (6 modules); `git grep -Iil mezmur` (318 files).
- Church identity: `school_config.php` (`SCHOOL_NAME_AMHARIC`, `PARISH_NAME_EN`, `DENOMINATION_EN`, `RELIGIOUS_INVOCATION`); `admin/backend/services/MemberCategory.php`; `members.age_group` and `classes.age_group` in `sql/legacy/database_schema.sql` and later `ALTER`s.
- Frozen client contract: `BRANCHING.md` branch guard; `applicationId` in `Mobile/wbws_flutter_app/android/app/build.gradle`; `API_NAME` and `EXPORT_PREFIX` in `admin/config.php`/`school_config.php`; delivery model in [ADR-0005](0005-direct-apk-distribution.md).
- Constraints: [ADR-0001](0001-php-monolith-on-shared-hosting.md), [ADR-0003](0003-offline-first-mobile.md), [ADR-0004](0004-manual-idempotent-sql-migrations.md), [ADR-0005](0005-direct-apk-distribution.md), [ADR-0021](0021-development-acceptance-before-production.md), `docs/DATABASE.md`.
