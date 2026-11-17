# ADR-0026 — The product is Arkeo School; a new installation starts on the Ethiopian calendar

- **Status:** Accepted — owner decision, 2026-11-16
- **Closes:** ADR-0022 **D-2** (product name) and **D-4** (calendar default)
- **Related:** ADR-0022 (general school domain, frozen compatibility identifiers), ADR-0024 (student of an educational institution), ADR-0025 (institution-configured levels, Ethiopian school workflow)

## Context

Two owner decisions were left open in ADR-0022 and both block work that has already been built and is waiting on them.

**D-2 — what is the product called?** Everything user-facing has to show *something*. Since ADR-0022 the code carries neutral placeholders (`SCHOOL_NAME = 'School'`, `API_NAME = 'SSMS API'`) so that no church identity is baked in, but a placeholder is not a name: the admin panel, the ID card heading, the PWA install prompt and the Android app label all read *something* the owner has not chosen.

**D-4 — which calendar does a new installation default to?** The attendance alerting work of 2026-10-19 forced the Ethiopian calendar system-wide, and `wbws_get_calendar_mode()` was deliberately hardened to return `'ethiopian'` unconditionally — it ignores both the legacy `dept_settings.calendar_mode` row and any session override, because a stale row must never flip every date in the UI. A one-time setup service (`SchoolCalendarInitialSetup::choose()`) was written and tested but never wired to an HTTP endpoint, so in practice nobody could change the calendar at all.

The owner's instruction (2026-11-16):

> "of for now make the product name Arkeo School"
> "the calander default will be ethiopian, but the school super admin can set it up when it starts to ec or gc"

## Decision

### P1 — The product is **Arkeo School**

`Arkeo School` is the product name for this development line. It is a placeholder in the sense that it can change later ("for now"), but it is a real name, not a neutral default: it replaces `School` / `SSMS` wherever the product names **itself**.

- The name describes the **product**, never the institution. A deployment still names its own school through `school_config.php` (`SCHOOL_NAME`, `SCHOOL_NAME_AMHARIC`, `SCHOOL_TAGLINE`) — ADR-0022 keeps identity configuration-driven, and this record does not make any installation call itself "Arkeo".
- Amharic: **አርኪዮ ትምህርት ቤት** (transliteration of *Arkeo School*). The owner has not supplied an Amharic name; a transliteration is the least inventive option and is marked for confirmation rather than treated as settled.

### P2 — The calendar defaults to Ethiopian, and a super admin chooses at setup

1. **Default is Ethiopian.** An installation that has not been configured shows Ethiopian dates. This preserves the 2026-10-19 behaviour: nothing silently becomes Gregorian.
2. **The super admin sets it once, at setup**, choosing either Ethiopian or Gregorian. The existing `SchoolCalendarInitialSetup::choose()` already implements this: `super_admin` only, revalidated inside the transaction, one-time (`mode IS NULL AND revision = 0`), audited to `school_calendar_configuration_audit`, with an explicit "outcome unknown" path rather than a silent retry.
3. **No other path changes the mode.** Not a session value, not the legacy `dept_settings` row, not an ordinary write endpoint. The read path consults the configured row and falls back to Ethiopian on *anything* else — unset, unavailable, or table absent.

## Consequences

- **Positive:** D-2 and D-4 leave the deferred table, so phase 3 stops carrying two open product questions. The calendar setup that was built and verified in October finally has a caller, and it is the only caller.
- **Negative / risks:**
  - Renaming the product invites renaming the *compatibility* identifiers (API name, export prefix, PWA cache, `applicationId`, `apiBaseUrl`). ADR-0022 **D8 freezes those**, and this record does not lift the freeze. A package-level Android rename creates a different app identity and would ship as a new install, not an update — that is a release decision, not a branding one.
  - One-time setup means a deployment that picks the wrong calendar cannot correct it through the same path. `school_calendar_configuration` already carries `previous_mode`/`new_mode` for a future audited switch; until that exists, a correction is a manual, audited database change.
  - A transliterated Amharic name may not be what an Ethiopian institution would call this product.

## Amendments to earlier records

- ADR-0022 D-2 and D-4: **closed by this record**. D-8 (`API_NAME`, `EXPORT_PREFIX`, PWA cache names, `applicationId`, `apiBaseUrl`, table prefixes, on-disk paths stay frozen) is **reaffirmed, not lifted**.
- No other ADR changes. ADR-0023/0024/0025 are untouched.

## Evidence

- `branding_defaults.php` — the fail-soft defaults a deployment inherits; carries the product's own name and the neutral church-free fallbacks.
- `school_config.php` — this installation's identity; the file a deployment edits to name its own school.
- `admin/backend/calendar_system.php` — `wbws_get_calendar_mode()`, the single read path for date display.
- `admin/backend/services/SchoolCalendarInitialSetup.php` — the one-time, super-admin-only, audited setup; `mode IS NULL AND revision = 0` guard.
- `sql/095_school_calendar_configuration.sql` — `mode ENUM('ethiopian','gregorian') DEFAULT NULL`, bootstrap row at `revision = 0`, audit table with `previous_mode`/`new_mode`.
- `tests/security/test_attendance_phase0.py::test_calendar_mode_is_forced_ethiopian` — the October invariant this record refines: "forced" becomes "default, with one audited override".
