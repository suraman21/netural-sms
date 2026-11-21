# ADR-0028 — Product name, app identity, group removal and identity codes: the five open questions answered

- **Status:** Accepted — owner decision, 2026-11-16
- **Closes:** the five open questions `N-1`–`N-5` recorded in `docs/MASTER_PLAN.md` §6
- **Related:** ADR-0022 (frozen compatibility identifiers), ADR-0024 S5 (sequential student codes), ADR-0026 (Arkeo School, calendar), ADR-0027 (student ≠ staff)

## Context

ADR-0026 named the product and ADR-0027 settled who the people are, which left five questions that had been raised and deferred. The owner answered all five in one message:

> "1, dont worry use ur translattion for now but be sure its easly configerable
> 2, for now just follow the rename
> 3, i dont get it what ypu mean but but it can work like todays
> 4, remove group related things or use a student instade
> 5, yes"

## Decisions

### A1 — The Amharic name is configuration, not code (`N-1`)

`SCHOOL_NAME_AMHARIC` and `SCHOOL_NAME_SHORT_AM` read the environment first and fall back to the transliteration **አርኪዮ ትምህርት ቤት**. Changing one line in `school_config.php` (or one environment variable) changes the landing page, ID cards, report headers, PWA description and copyright, because every surface already reads the constant and no template hardcodes Amharic text.

The name itself stays **unconfirmed**: it is a transliteration of a product name, not a translation. This record makes it cheap to change, which is what was asked; it does not make it right.

### A2 — The Android package identity follows the rename (`N-2`)

`com.arkeonethiopia.fkss` becomes `com.arkeonethiopia.arkeoschool` everywhere: `applicationId`, `namespace`, the Kotlin source tree, the manifest, proguard rules, the dex gate in CI, the branch-guard assertion, `AppReleaseManager`'s package map, APK verification scripts and eight test modules. `FkssApplication` becomes `ArkeoApplication`.

**The consequence, stated plainly because it is a release fact and not a code detail:** Android treats a changed `applicationId` as a **different application**. The new build installs *alongside* the old one instead of updating it. Every user must install once and remove the old app; there is no in-place upgrade, and the update prompt will not fire for the old package. If that is unacceptable for a deployment with many installed clients, this decision needs reversing before the first release under the new identity — and ADR-0022 D8 (which freezes the package identifier) is the record that said so.

### A3 — The API keeps working the way it does today (`N-3`)

> "i dont get it what ypu mean but but it can work like todays"

The question was whether the mobile contract (`member_id` in the JSON the phone posts, e.g. `api/v1/routes/enrollment.php`) should change with the rename. Answer: it works as it does now. Practically that means **the wire field names do not change in this release**; the server continues to accept and return `member_id`, whatever the PHP variables and SQL columns are called internally.

This is the low-risk reading and it also happens to be the correct one: the field name is a client contract, and changing it breaks installed phones with no benefit to the user. If a later release wants `student_id` on the wire, it needs a compatibility window — accept both, ship the app, drop the old one after a release cycle — and that is its own record.

### A4 — The group subsystem is removed (`N-4`)

> "remove group related things or use a student instade"

Groups/associations were a Sunday-school construct: a parish-level association with its own person table (`wbws_group_members`, carrying its own `baptismal_name`), its own leaders, and an `is_under_sunday_school` flag separating "our" groups from the parish's.

**Removed, not converted.** A group member is *not* a student: there is no grade, no programme, no enrolment date and no academic year on a group row. Turning one into a student would mean inventing all four — fabricating enrolment records for people who may never have been enrolled in anything. The owner offered "or use a student instead"; the honest answer is that the data to do so does not exist, and ADR-0024 S6 already authorises removal over preservation.

Deleted: `admin/groups.php`, `admin/dashboards/groups.php`, `admin/backend/groups_api.php` (1,101 lines), the `backend/api/groups.php` shim, three tables (`sql/104`, guarded and idempotent, dropped in FK order), the access-control entries, the two `BrowserPageRoutes` routes, the `groups` feature gate and its `FEATURE_GROUPS` default, the hr-dept nav link, the super-admin and system-health file-integrity entries, the super-admin group-orphan integrity checks, the AI overview group block, and the dead `groups.delete_group` confirmation phrase.

Tests were **updated to pin the absence**, not deleted: a future groups API must bring back the DC-4 preview-and-transaction contract, and the feature gate must not quietly regain a capability nothing backs.

### A5 — Identity codes get a mapping report (`N-5`)

`admin/tools/id_code_mapping_report.php` — CLI only, **read-only by design, with no `--execute` and no write verb anywhere in the file**. It lists every holder, their current code, their legacy code, and the code the new scheme would assign, as a table or a CSV (`--csv=`, `--only-changed`).

It exists because three records are only compatible if nobody's existing code silently changes: ADR-0022 D8 froze the codes, ADR-0027 M5 re-points tables without renumbering, and ADR-0024 S5 gives *new* student codes a plain sequential format. The report is the artefact an owner signs before any card is reprinted — renumbering is a physical workflow problem, not a software one.

## Consequences

- **Positive:** all five questions are closed, `docs/MASTER_PLAN.md` §6 no longer carries an open product question, and the codebase no longer ships a subsystem that contradicts its own domain.
- **Negative / risks:**
  - **A2 is the sharp one.** A new application identity means a parallel install for every user, and the old app cannot be updated in place. This is reversible only before the first release.
  - A1's Amharic name is still the agent's transliteration; it is now cheap to correct, not correct.
  - A3 defers a rename rather than resolving it: `member_id` will look inconsistent with `students` in the database for as long as the wire contract lasts.
  - The group tables are dropped with no data export. That is consistent with S6 (fresh clone, no live data) and would be wrong in an installation that still serves group data.

## Evidence

- `sql/104_remove_groups.sql` — guarded, idempotent, FK-ordered; applied twice against a real MariaDB 11.8 with the three tables present; a second apply is a no-op.
- `admin/tools/id_code_mapping_report.php` — run against a fixture database: 3 holders, 2 flagged as changing, CSV and `--only-changed` both correct, nothing written.
- `Mobile/wbws_flutter_app/android/app/build.gradle` — `applicationId` and `namespace`.
- `api/v1/routes/enrollment.php` — the `member_id` wire field A3 keeps.
- `tests/security/test_id_code_mapping_report.py` — pins read-only behaviour and the absence of an execute mode.
