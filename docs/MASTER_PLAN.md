# Master plan — document inventory, sequence and open questions

**Maintained by:** Arena Agent · **Repo state:** see `git log` · **Last updated:** 2026-11-16

This is the single place that answers three questions at once: *what documentation exists*, *in what order the work must land*, and *what is still undecided*. It exists because the same facts were being restated in the tracker, in ADRs, in `DATABASE.md` and in test pins, and drifting apart each time. When this file and another disagree, **this file is the index and the other file is the source** — fix the source, then update the line here.

**Not in this repository:** `SMS-TRANSFORMATION-TRACKER.md` (the narrative plan of record, in the workspace root) and the session transcripts. This file references their conclusions but does not duplicate them.

---

## 1. Workstreams

Work is cut into five streams. Everything below is filed under one, so "what is left" is a lookup rather than a memory test.

| # | Workstream | Question it answers | Mostly lives in |
|---|---|---|---|
| W1 | Identity & vocabulary | What is the product called, and what are we calling the people? | ADR-0022, ADR-0024, ADR-0026 |
| W2 | Domain model | Institutions, levels, grades, exam gates, students vs staff | ADR-0023, ADR-0025, ADR-0027, `sql/09x`, `sql/10x` |
| W3 | Calendar & academic time | Which calendar, which terms, promotion and gates | ADR-0026 P2, `sql/013`, `sql/095`–`098` |
| W4 | Surfaces & clients | Dashboards, exports, ID cards, API, mobile, Amharic copy | (no ADR yet — see G-3) |
| W5 | Quality & operations | Tests, CI, migration hygiene, deployment | `DEVELOPMENT_AND_TESTING.md`, `.github/workflows` |

---

## 2. File inventory

Auto-generated from the working tree, so it cannot drift from what exists. Status is read from each file's own `**Status:**` line where one exists.

| File | Kind | Status | Title | Flag |
|---|---|---|---|---|
| `Mobile/wbws_flutter_app/docs/ANDROID_COMPATIBILITY.md` | Client docs | — | Android compatibility and acceptance |  |
| `Mobile/wbws_flutter_app/docs/ATTENDANCE_CLASS_DAY_1_7_4.md` | Client docs | — | 1.7.4+41: education attendance class-day gate | stale terms: 1 |
| `Mobile/wbws_flutter_app/docs/FAILURE_INTELLIGENCE_CLIENT.md` | Client docs | — | Failure Intelligence — Client Contract |  |
| `Mobile/wbws_flutter_app/docs/PROFILE_APP_UPDATES.md` | Client docs | — | Permanent App updates access — first delivery |  |
| `Mobile/wbws_flutter_app/docs/RELEASE.md` | Client docs | — | Android release guide |  |
| `docs/API.md` | Reference | maintained API overview. Original route review | API reference (overview) | stale terms: 2 |
| `docs/ARCHITECTURE.md` | Reference | maintained architecture overview. Original com | Architecture | stale terms: 13 |
| `docs/CHANGE_IMPACT.md` | Reference | source navigation and review procedure, not ex | Change-impact and source-navigation guide | stale terms: 1 |
| `docs/DATABASE.md` | Reference | maintained migration reference. Original revie | Database and migrations | stale terms: 46 |
| `docs/DATA_DICTIONARY.md` | Reference | generated 2026-10-23 from `main` at `46fbaa7`  | Data dictionary (table level) | stale terms: 60 |
| `docs/DEVELOPER_GUIDE.md` | Reference | source-reviewed guidance; environment commands | Developer guide: from a task to a safe change |  |
| `docs/DEVELOPMENT_AND_TESTING.md` | Reference | maintained reference. | Development, testing and CI |  |
| `docs/DOCUMENTATION_CATALOG.md` | Reference | — | Documentation catalog and review boundaries | stale terms: 1 |
| `docs/DOCUMENTATION_STANDARD.md` | Reference | maintained authoring reference. Applies to fir | Documentation standard | stale terms: 1 |
| `docs/DOCUMENT_LIFECYCLE.md` | Reference | maintained lifecycle reference. Review date: 2 | Documentation lifecycle and retirement register |  |
| `docs/ENCRYPTION_INVESTIGATION.md` | Reference | Research-only. | Encryption investigation — separate from integrity migra |  |
| `docs/FAILURE_INTELLIGENCE.md` | Reference | — | Failure Intelligence — Architecture & Contract |  |
| `docs/INCIDENT_RESPONSE.md` | Reference | — | Incident response and evidence handling |  |
| `docs/MASTER_PLAN.md` | Reference | ` line where one exists. | Master plan — document inventory, sequence and open ques | stale terms: 18 |
| `docs/OFFLINE_ACCEPTANCE.md` | Reference | — | Offline capability — acceptance contract | stale terms: 3 |
| `docs/ONBOARDING.md` | Reference | maintained, written 2026-10-23 and release/evi | Engineer onboarding |  |
| `docs/OPERATIONS.md` | Reference | maintained operational reference. Release poli | Operations: deployment, backups, mobile release, monitor | stale terms: 2 |
| `docs/PRODUCTION_WORKFLOW.md` | Reference | maintained release policy, updated 2026-10-28. | Development acceptance and production promotion | stale terms: 2 |
| `docs/QUALITY_GATES.md` | Reference | — | Documentation quality and acceptance gates |  |
| `docs/README.md` | Reference | — | Documentation index | stale terms: 1 |
| `docs/RELEASE_GATES.md` | Reference | Plan + checklist. Passing `flutter test` is | Controlled release gates — real-phone acceptance | stale terms: 3 |
| `docs/ROLES_AND_PERMISSIONS.md` | Reference | maintained role reference. The page-level matr | Roles and permissions | stale terms: 8 |
| `docs/SECURITY.md` | Reference | maintained security reference. Original contro | Security | stale terms: 8 |
| `docs/TEST_STRATEGY.md` | Reference | maintained risk and gate reference, updated 20 | Test strategy |  |
| `docs/WEB_ADMIN_SYNC_MONITORING.md` | Reference | — | Web / Admin Sync Monitoring Foundation | stale terms: 5 |
| `docs/adr/0001-php-monolith-on-shared-hosting.md` | ADR | — | ADR-0001: PHP monolith on shared hosting |  |
| `docs/adr/0002-mobile-token-authentication.md` | ADR | — | ADR-0002: Mobile API authentication with signed access t |  |
| `docs/adr/0003-offline-first-mobile.md` | ADR | — | ADR-0003: Offline-first Android app with outboxes, idemp | stale terms: 7 |
| `docs/adr/0004-manual-idempotent-sql-migrations.md` | ADR | — | ADR-0004: Hand-applied, additive and idempotent SQL migr |  |
| `docs/adr/0005-direct-apk-distribution.md` | ADR | — | ADR-0005: Distribute the Android app directly, with an i |  |
| `docs/adr/0006-department-role-model.md` | ADR | — | ADR-0006: One role per account, organised by department | stale terms: 1 |
| `docs/adr/0007-section-equals-age-group.md` | ADR | — | ADR-0007: Section and age group are one concept with one |  |
| `docs/adr/0008-ethiopian-calendar-only.md` | ADR | — | ADR-0008: Ethiopian calendar for every user-facing date | stale terms: 2 |
| `docs/adr/0009-attendance-ownership.md` | ADR | — | ADR-0009: Attendance belongs to Education and Mezmur; HR | stale terms: 4 |
| `docs/adr/0010-semester-based-grading.md` | ADR | — | ADR-0010: Semester-based grading: 100% per semester, ann |  |
| `docs/adr/0011-class-day-only-attendance.md` | ADR | — | ADR-0011: Attendance only on class days, with a 45-day b |  |
| `docs/adr/0012-reporting-centre-and-data-boundary.md` | ADR | — | ADR-0012: Reporting Centre: in-house governed registry;  |  |
| `docs/adr/0013-tiered-danger-confirmation.md` | ADR | — | ADR-0013: Tiered confirmation for destructive actions |  |
| `docs/adr/0014-branching-main-dev.md` | ADR | — | ADR-0014: `main` is production; `dev` is main plus beta  |  |
| `docs/adr/0015-mezmur-media-object-storage.md` | ADR | — | ADR-0015: Mezmur audio and art in object storage, with p | stale terms: 4 |
| `docs/adr/0016-family-link-guardian-model.md` | ADR | — | ADR-0016: Family Link: a guardian entity with typo-toler |  |
| `docs/adr/0017-no-member-registration-on-mobile.md` | ADR | — | ADR-0017: Members are never registered from the Android  |  |
| `docs/adr/0018-lesson-level-attendance.md` | ADR | — | ADR-0018: Lesson-level Education attendance alongside th |  |
| `docs/adr/0019-attendance-alert-thresholds.md` | ADR | — | ADR-0019: Attendance warnings with per-department thresh |  |
| `docs/adr/0020-score-limit-and-did-not-take.md` | ADR | — | ADR-0020: Refuse marks above the maximum; a blank mark i |  |
| `docs/adr/0021-development-acceptance-before-production.md` | ADR | — | ADR-0021: Development acceptance precedes separately aut |  |
| `docs/adr/0022-general-school-domain.md` | ADR | — | ADR-0022: A general school domain, with the Mezmur minis | stale terms: 48 |
| `docs/adr/0023-no-age-group-or-age-section.md` | ADR | — | ADR-0023: No age group and no age-based section | stale terms: 15 |
| `docs/adr/0024-student-of-an-educational-institution.md` | ADR | — | ADR-0024: A student of an educational institution — KG t | stale terms: 13 |
| `docs/adr/0025-institution-levels-and-ethiopian-workflow.md` | ADR | — | ADR-0025: An institution configures its levels; KG–12 fo | stale terms: 1 |
| `docs/adr/0026-product-name-and-calendar-default.md` | ADR | Accepted — owner decision, 2026-11-16 | ADR-0026 — The product is Arkeo School; a new installati | stale terms: 2 |
| `docs/adr/0027-members-is-not-one-thing.md` | ADR | Accepted — owner decision 2026-11-16. | ADR-0027 — `members` is not one thing: the rename is a s | stale terms: 10 |
| `docs/adr/0028-name-identity-groups-and-codes.md` | ADR | Accepted — owner decision, 2026-11-16 | ADR-0028 — Product name, app identity, group removal and | stale terms: 2 |
| `docs/adr/README.md` | ADR index | — | Architecture decision records (ADRs) | stale terms: 5 |
| `docs/adr/template.md` | ADR index | — | ADR-NNNN: Title in plain words |  |
| `docs/api/FINANCE_CONTRACT.md` | Reference | — | Finance browser API: action reference |  |
| `docs/api/LEDGER_ACTIONS.md` | Reference | source-reviewed field/side-effect reference fo | Selected ledger action contracts |  |
| `docs/api/LEDGER_PERMISSIONS.md` | Reference | — | Ledger permissions, request gates and validation |  |
| `docs/api/MATERIALS_CONTRACT.md` | Reference | — | Materials browser API: action reference |  |
| `docs/architecture/SECURITY_MODEL.md` | Reference | — | Security architecture and review model |  |
| `docs/architecture/TECHNICAL_DESIGN.md` | Reference | — | FKSS — Technical Design Document (software architecture) | stale terms: 7 |
| `docs/audits/AUDIO_STORAGE_SETUP.md` | Reference | reference. Mezmur audio is stored in Cloudflar | Mezmur audio: storage and streaming setup | stale terms: 26 |
| `docs/audits/AUTH_OUTBOX_BUILD24_ROLLOUT_RUNBOOK.md` | Reference | — | Build 24 authorization and outbox rollout runbook | stale terms: 1 |
| `docs/audits/DEPLOYMENT_RUNBOOK.md` | Reference | — | Deployment safeguards and historical go-live checklist | stale terms: 10 |
| `docs/audits/MOBILE_FLEET_TELEMETRY_INTEGRITY_HARDENING.md` | Reference | — | Mobile Fleet Telemetry — Integrity Hardening |  |
| `docs/audits/MOBILE_FLEET_TELEMETRY_RETENTION.md` | Reference | source and static-contract verified; cron inst | Mobile Fleet Telemetry — Retention and Lifecycle Phase |  |
| `docs/audits/MOBILE_FLEET_TELEMETRY_STAGE0_RELEASE_GATE.md` | Reference | Implemented as a read-only preflight and contr | Mobile Fleet Telemetry & Analytics — Stage 0 Release Gat |  |
| `docs/audits/MOBILE_FLEET_TELEMETRY_STAGE1_STAGING_EVIDENCE.md` | Reference | — | Mobile Fleet Telemetry — Stage 1 Staging Evidence Phase |  |
| `docs/ci_cd/GIT_CHEATSHEET.md` | Reference | — | Git workflow reference |  |
| `docs/ci_cd/SEARCH_LOAD_AND_ERRORS.md` | Reference | maintained source reference. | Mezmur web search: request handling and verification | stale terms: 3 |
| `docs/database/LEDGER_COLUMNS.md` | Reference | — | Ledger columns and constraints: source-defined reference |  |
| `docs/incidents/INCIDENT_2026-10-03_TRACKING_SCHEMA.md` | Reference | diagnosed, reproduced locally against a databa | Production incident — Academic Tracking detail views fai |  |
| `docs/incidents/README.md` | Reference | — | Incident records |  |
| `docs/product/GLOSSARY.md` | Reference | — | Glossary | stale terms: 9 |
| `docs/product/PRD.md` | Reference | Implemented | FKSS — Product Requirements Document | stale terms: 19 |
| `docs/reports/APK_UPLOAD_512MIB_E2E_2026-10-27.md` | Reference | — | 512-MiB APK upload and end-to-end acceptance — 2026-10-2 |  |
| `docs/reports/APK_UPLOAD_CI_FOLLOWUP_2026-10-28.md` | Reference | — | APK upload CI follow-up — 2026-10-28 |  |
| `docs/reports/APK_UPLOAD_HARDENING_2026-10-27.md` | Reference | — | APK upload reliability implementation — 2026-10-27 |  |
| `docs/reports/CI_ZERO_SKIP_REPAIR_2026-10-28.md` | Reference | — | CI zero-skip repair — 2026-10-28 |  |
| `docs/reports/DOCUMENTATION_BASELINE_2026-10-28.md` | Reference | — | Documentation baseline — 2026-10-28 |  |
| `docs/reports/SOURCE_TRUTH_AUDIT_2026-10-28.md` | Reference | — | Source-truth audit — open review record |  |
| `docs/rfc/0001-repository-structure.md` | Reference | — | RFC 0001: Repository structure and root clean-up |  |
| `docs/rfc/README.md` | Reference | — | Requests for comments (RFCs) |  |
| `docs/user_manual/ACCOUNT_AND_DEVICE.md` | Reference | — | Account and device settings |  |
| `docs/user_manual/APP_UPDATES.md` | Reference | — | App updates |  |
| `docs/user_manual/COVERAGE.md` | Reference | — | Manual coverage and verification queue | stale terms: 70 |
| `docs/user_manual/FINANCE.md` | Reference | — | Finance: income, expenses, member fees and reports |  |
| `docs/user_manual/MATERIALS.md` | Reference | — | Materials: inventory, receipts, issues and requests |  |
| `docs/user_manual/README.md` | Reference | — | FKSS user manual | stale terms: 1 |
| `docs/user_manual/SYNC_AND_RECOVERY.md` | Reference | — | Sync and recovery | stale terms: 1 |
| `README.md` | Repo root | — | Arkeo School — School Management System | stale terms: 8 |
| `CHANGELOG.md` | Repo root | — | Unreleased | stale terms: 17 |

**How to read the Flag column.** `stale terms: N` counts occurrences of church/Sunday-school/age-group vocabulary. Inside an ADR that is usually legitimate — the record has to *name* what it removes. In `docs/planning/` it usually means the document predates the decisions and should be marked historical rather than edited (see G-2).

---

## 3. Build sequence

The dependency graph is the important part: several streams *look* parallel and are not.

```
W1 identity ──► W2 split (students │ staff) ──► W2 rename per referrer ──► W4 surfaces
     │                    │                                                    │
     │                    └──► W3 level kind / gates ──► W3 promotion rules ────┘
     │
     └──► W5 guard tests (can start immediately, no dependency)
```

| Phase | Contains | Can start when | May not start until |
|---|---|---|---|
| **0** | CI unblocked, migration renumbering | done | — |
| **1** | ADR-0022, neutral identity | done | 0 |
| **2** | Identity as configuration | done | 1 |
| **3a** | Level kinds, duration/award, exam gates | done | 2 |
| **3b** | Product name, calendar default | done | 3a |
| **3c** | ADR-0027 decision; schema split (`students` / staff) | next | 3b |
| **3d** | Rename per referrer (M5), delete `MemberCategory`, `member_type`, `age_group`, `baptismal` | after 3c | 3c |
| **4** | Surfaces: dashboards, exports, ID cards, Amharic copy | after 3d | 3d |
| **5** | API + mobile wire (field names UNCHANGED — ADR-0028 A3) | after 3d | 3d |
| **6** | Guard test: fails on `member`/`age_group`/`baptismal` reappearing | **any time** | — |

**Why 5 is now mostly a naming exercise, not a contract change.** The phone reads `member_id` from JSON and posts it back, and ADR-0028 A3 decided the wire names stay as they are. So phase 5 renames PHP variables, SQL columns and UI labels; the JSON keys do not change. When a later release wants `student_id` on the wire it needs a compatibility window (accept both, ship the app, drop the old after a cycle) and its own record.

---

## 4. Gaps and contradictions

Every entry below was **verified in this repository**, not inferred. `C-` is a contradiction between two documents or a document and the code; `G-` is a gap — something absent that a reader would expect.

| # | Says | Conflicts with | Status |
|---|---|---|---|
| C-1 | ADR-0022 D8 freezes `API_NAME`/`EXPORT_PREFIX`/`applicationId`/`apiBaseUrl` | ADR-0026 P1 renames the product to Arkeo School | Resolved by scope: D8 stays frozen; rename applies to display surfaces only. Recorded in ADR-0026. |
| C-2 | ADR-0023 A6: legacy member columns stay readable, never migrated | ADR-0024 S6: remove the legacy domain; ADR-0027 M4: flags leave with the old schema | S6 supersedes A6 by explicit note. A6's text still stands unqualified in ADR-0023 and will mislead a reader. |
| C-3 | Tracker §10 (original plan) treats phase 3 as one mechanical rename | ADR-0027 M5: rename per referrer because `members` holds learners and staff | Tracker §12.4/§12.6 supersede §10 for phase 3 onward; §10 was not marked. |
| C-4 | `test_attendance_phase0` pinned 'Ethiopian, not configurable' | ADR-0026 P2: super admin sets it once | Resolved — both the unit and live test were rewritten to pin the new rule. |
| C-5 | docs/DATABASE.md pinned gaps 061/093/094 and 98 files | Actual: 99 files, gaps 047/061/093/094, 140 CREATE TABLE targets | Fixed in phase 3a. The pin test now derives from the tree, but the prose gap list is still hand-written. |
| C-6 | ADR-0017: registration is web-only; mobile may not create members | `api/v1/routes/enrollment.php` POST /enrollment creates an enrolment from the phone | Not a violation (enrolment ≠ registration) but the boundary is not stated anywhere in code. |
| C-7 | `member_type_settings` seeds regular/special_regular/honorary | ADR-0024/0027: `member_type` has no student equivalent and is deleted | Deletion is authorised but no migration exists yet; the seed table still ships. |

| # | Gap | Why it matters | Suggested home |
|---|---|---|---|
| G-1 | No ADR covers the **API/mobile wire contract** (field names, versioning, compatibility windows) | W5 will change `member_id`; nothing records how clients are protected | new ADR-0028 |
| G-2 | `docs/planning/` documents predate ADR-0022–0027 and still describe the Sunday-school domain | A reader implementing from them would build the thing we are removing | mark historical, do not rewrite |
| G-3 | No document owns **W4 surfaces** (which screens, exports and ID-card fields must change) | Phase 4 is 370 files with no checklist | this file, section 5 |
| G-4 | No migration exists yet to drop `member_type`, `membership_tier`, `age_group`, `baptismal_name` | Decisions are recorded but the schema still ships them | phase 3d |
| G-5 | The tracker lives outside the repository | It is cited by nothing inside; a clone cannot read it | decide: move in, or keep as plan-of-record |
| G-6 | No document defines what an **institution** is vs a **school** in copy | Amharic and English labels will drift | W4 |

---

## 5. Phase 4 checklist (W4 surfaces)

Filled in as sections are confirmed, so phase 4 is a list to work rather than a search to run:

- [ ] Dashboards: `super-admin`, `school_admin`, `edu_dept`, `info-dept`, `hr-dept`, `finance_department`
- [ ] Exports (CSV/PDF) and the `EXPORT_PREFIX` behaviour (frozen — D8)
- [ ] ID cards: headings, QR payload, staff vs student code formats
- [ ] Report cards and assessments
- [ ] Attendance and timetable screens
- [ ] Amharic copy: every string currently saying አባል (member) or መዝሙር (Mezmur)
- [ ] Mobile: `member_list_screen`, `member_detail_screen`, `roster.dart`, `api_service.dart`
- [ ] API: `/enrollment`, `/teachers`, `/attendance`, `/grades` field names

---

## 6. Open decisions

Closed decisions stay in the table on purpose: a future reader needs to know the question was asked and answered, not just that the code looks a certain way.

| # | Question | Status |
|---|---|---|
| D-1 | Scope C — full remodel | CLOSED |
| D-2 | Product name = Arkeo School | CLOSED (ADR-0026 P1) |
| D-3 | Retire Mezmur | CLOSED |
| D-4 | Calendar default = Ethiopian, super admin sets once | CLOSED (ADR-0026 P2) |
| D-5 | No age group at all — no mapping | CLOSED (ADR-0023) |
| D-6 | One institution per installation | CLOSED (ADR-0025 I6) |
| D-7 | Mezmur table authorisation | OPEN — largely moot under S6 |
| D-8 | Student codes = plain sequential, no prefix | CLOSED (ADR-0024 S5) |
| D-9 | Staff vs students are disjoint | CLOSED (ADR-0027 M1–M5) |
| N-1 | Amharic product name (currently my transliteration አርኪዮ ትምህርት ቤት) | CLOSED — ADR-0028 A1: configurable via env / one line; transliteration retained |
| N-2 | Does the Android package identity follow the rename? | CLOSED — ADR-0028 A2: renamed to com.arkeonethiopia.arkeoschool (new app identity) |
| N-3 | Wire compatibility: accept `member_id` for one release, or cut hard? | CLOSED — ADR-0028 A3: wire field names unchanged this release |
| N-4 | `wbws_group_members` — group member, or student of the institution? | CLOSED — ADR-0028 A4: groups removed entirely (code + sql/104) |
| N-5 | Do existing paper/printed ID codes need a mapping report? | CLOSED — ADR-0028 A5: admin/tools/id_code_mapping_report.php (read-only) |

---

## 7. Update protocol

1. **New decision** → new ADR with the next number (0028 is free), `docs/adr/README.md` row, and a line in section 6 here.
2. **New document** → add a row to section 2 with its owning workstream.
3. **Resolved contradiction** → keep the row, change Status. Deleting it erases why the code looks the way it does.
4. **New migration** → next free number is **107** (gaps at 047, 061, 093, 094 are historical; do not reuse them). Seeds live in `sql/seeds/` and are *not* migrations.
5. **Changing a pin** → if a test asserts a fact, change the test in the same commit as the fact, and say why in the message.

---

## 8. Verification gates

| Gate | Command | Required before |
|---|---|---|
| Unit/security suite | `python -m pytest tests/security` | every phase |
| Schema harnesses | `bash tests/e2e/run_*.sh` | any `sql/` change |
| Documentation | `pytest tests/security/test_docs_links.py test_documentation_source_facts.py` | any docs change |
| Migration hygiene | CI job *Migration numbering* | any `sql/` change |
| Full CI | `Backend checks` + `Production integrity` on the PR | every merge |
