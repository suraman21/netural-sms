# UNIVERSAL AI SOFTWARE CHANGE — Audited Transformation Plan (UPDATED)

**System:** netural-sms (School Management System)
**Server:** /home/arkeonet/sms.arkeonethiopia.com/ (synced to d1760bf3)
**Date:** 2026-11-16
**Protocol:** UNIVERSAL AI SOFTWARE CHANGE, PATCH & UPDATE PROTOCOL v1.0

---

## 1. REQUEST

Complete the remaining work after the member_code → student_code migration:

1. **Investigate HR and Info dept login failure** — school admin, super admin, and edu dept login are WORKING; only HR and Info dept login are broken
2. **Fix API `member_code` key bug** — API returns empty `member_code` because SQL selects `student_code` without aliasing as `member_code`; members.php line 49 has `member_code LIKE` in PII search WHERE (column doesn't exist)
3. **Fix JS `.member_code` UI reads** — 28 occurrences across 14 files need to read correct key
4. **Fix mobile dart `s['member_code']` reads** — 29 occurrences across 12 files (only needed if API key changes)
5. **Produce this audited transformation plan**

---

## 2. CURRENT PROBLEM

### A. HR and Info Dept Login (PRIMARY TARGET — UPDATED)
- School admin, super admin, and edu dept login are **working**
- **HR dept and Info dept login are NOT working**
- Need to investigate: what error occurs, where in the auth path it fails
- Auth path: login.php → session set → dashboard.php → FeatureGate::forRoleDashboard($role) → route to hr-dept.php / info-dept.php

### B. API `member_code` Key Bug (BREAKS MOBILE APP)
| File | Line | Issue |
|------|------|-------|
| `api/v1/routes/members.php` | 49 | `member_code LIKE` in PII search WHERE — column `member_code` does not exist on `students` → **fatal SQL error** |
| `api/v1/routes/members.php` | 75 | GET /members SELECT lists `student_code` but does **NOT** alias as `member_code` → API response `member_code: ''` |
| `api/v1/routes/members.php` | 130 | GET /members/{id} SELECT lists `student_code` but does **NOT** alias as `member_code` → API response `member_code: ''` |
| `api/v1/core/acl.php` | 220 | `apiMemberSafeRow()` returns `'member_code' => $row['member_code'] ?? ''` — always undefined |
| `api/v1/core/acl.php` | 265 | `apiRosterStudentRow()` returns `'member_code' => $row['member_code'] ?? ''` — fed by EnrollmentService::fetchRoster() fallback query at line 897 uses `m.member_code` without alias |

### C. JS `.member_code` UI Reads (28 occurrences, 14 files)
Files: `academic_tracking.js` (9), `family_link.js` (3), `academic_intelligence.js` (3), `report_card.js` (2), `archive-members.js`, `assessment_followups.js` (2 each), `attendance_monthly.js` (2), `attendance_warnings.js`, `education_analytics_hub.js`, `id-card-directory.js`, `manage-members.js`, `new-members.js` (2), `all-members.js`, `advanced_analytics.js`

### D. Mobile Dart `s['member_code']` Reads (29 occurrences, 12 files)
Only needed if API key changes from `member_code` to `student_code`. If we fix API to alias `student_code AS member_code`, **no dart changes needed**.

---

## 3. ROOT CAUSE ANALYSIS

### A. HR and Info Dept Login — UNKNOWN (INVESTIGATION NEEDED)
Possible causes:
1. Dashboard file (hr-dept.php / info-dept.php) has a fatal error on load
2. FeatureGate blocks the role (but forRoleDashboard returns null for both → not the cause)
3. AdminSessionGuard revalidation fails for these roles
4. Session variables not set correctly for these roles

**Need to:** trace the exact error, check error.log, test dashboard files directly

### B. API `member_code` Key Bug — ROOT CAUSE ESTABLISHED
After renaming `member_code` → `student_code` on `students` table, API SELECT queries were not updated to alias `student_code AS member_code`.

### C. JS `.member_code` Reads — ROOT CAUSE ESTABLISHED
JavaScript reads `.member_code` from data objects, but backend now uses `student_code` column.

### D. Mobile Dart `s['member_code']` Reads — ROOT CAUSE ESTABLISHED
Same root cause as C.

---

## 4. IMPACT ANALYSIS

### DIRECTLY AFFECTED (must change)
- `api/v1/routes/members.php` — lines 49, 75, 130
- `api/v1/core/acl.php` — lines 220, 265
- `admin/backend/services/EnrollmentService.php` — line 897
- `admin/dashboards/hr-dept.php` — login failure investigation
- `admin/dashboards/info-dept.php` — login failure investigation

### INDIRECTLY AFFECTED
- All 14 JS files reading `.member_code`
- All 12 mobile dart files reading `s['member_code']`

---

## 5. RECOMMENDED SOLUTION

**Strategy: Keep API backward-compatible (API returns `member_code` key)** — avoids changing 29 mobile dart occurrences and 28 JS occurrences.

### Slice 1: Fix HR and Info dept login (PRIORITY)
Investigate root cause of login failure for hr_dept and info_dept roles.

### Slice 2: Fix API `member_code` key (backend only)
| # | File | Change |
|---|------|--------|
| 1 | `api/v1/routes/members.php:49` | `member_code LIKE` → `student_code LIKE` |
| 2 | `api/v1/routes/members.php:75` | Add `student_code AS member_code` to SELECT |
| 3 | `api/v1/routes/members.php:130` | Add `student_code AS member_code` to SELECT |
| 4 | `admin/backend/services/EnrollmentService.php:897` | Fallback: `m.member_code` → `m.student_code AS member_code` |

### Slice 3: Fix JS `.member_code` reads (only where data source doesn't provide `member_code`)

### Slice 4: Mobile dart files (only if API key changes — not needed with current strategy)

---

## 6. IMPLEMENTATION PLAN

**Phase 1 — Understand & Verify** ✓ COMPLETED (catalogued all files)
**Phase 2 — Trace Current Behavior** — IN PROGRESS (HR/Info login investigation)
**Phase 3 — Analyze Impact** ✓ COMPLETED
**Phase 4 — Plan** ✓ THIS DOCUMENT
**Phase 5 — Implement** PENDING
**Phase 6 — Test** PENDING
**Phase 7 — Audit** PENDING
**Phase 8 — Verify & Report** PENDING

---

## 7. ACCEPTANCE CRITERIA

1. [ ] HR dept dashboard loads without fatal error after login
2. [ ] Info dept dashboard loads without fatal error after login
3. [ ] API GET /members returns `member_code` key with actual student code value
4. [ ] API GET /members/{id} returns `member_code` key with actual student code value
5. [ ] PII search (GET /members?search=) works without SQL error
6. [ ] JS `.member_code` reads display correct codes across all dashboards
7. [ ] Mobile app member code display works
8. [ ] `php -l` passes on all changed PHP files
9. [ ] Zero new entries in server error.log after deployment

---

## 8. IMMEDIATE NEXT STEPS

1. **Investigate HR and Info dept login** — check error.log, test dashboard files, trace exact failure point
2. **Implement Slice 2** — Fix API `member_code` key
3. **Implement Slice 3** — Fix JS `.member_code` reads

---

## 9. BLOCKED ITEMS

| Item | Blocker | Required |
|------|---------|----------|
| HR/Info login root cause | Need to see actual error | Server error.log access or direct testing |
| Mobile dart changes (if needed) | Depends on API key decision | Decision on API contract |