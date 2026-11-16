# ADR-0008: Ethiopian calendar for every user-facing date

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-10-19 (EC-only sweep); EC/GC conversion since 2026-08-16 | Maintainers |

> Recorded on 2026-10-22; the decision date is shown above. Evidence is cited so the decision can be checked; where the rationale is not documented, this is stated.

> **2026-11-16 — partially superseded:** [ADR-0022](0022-general-school-domain.md) makes calendar display a deployment property (`school_calendar_configuration.mode`). The Ethiopian calendar stops being universal; it remains the correct behaviour of the existing deployment because that deployment configures it. Which mode a new installation defaults to is still open (D-4). Reading this record as "every deployment on earth shows EC" would now be wrong; reading it as "the Felege Kidusan deployment shows EC" is correct.

## Context

Staff, parents and church records use the Ethiopian calendar. Mixed calendars caused confusion in exports and screens.

## Decision

Store Gregorian dates internally and convert at the edges. Every displayed date and every export uses the Ethiopian calendar. Monthly reports use Ethiopian months.

## Consequences

- **Positive:** matches how users think and file records.
- **Negative:** conversion code is needed everywhere; date pickers and month boundaries are custom; tests must cover Pagume and year boundaries.

## Evidence

Commits `185aa97`, `b260f0f`, `6fa65df`; `admin/backend/ethiopian_date.php`; PR template checklist.
