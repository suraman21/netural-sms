# REGISTRAR SYSTEM DESIGN & IMPLEMENTATION PLAN

**System:** netural-sms (School Management System)
**Date:** 2026-11-16
**Protocol:** UNIVERSAL AI SOFTWARE CHANGE, PATCH & UPDATE PROTOCOL v1.0

---

## 1. OBJECTIVE

Create a **School Registrar** role and dashboard, SEPARATE from HR, to register and manage students.

- **HR Dept** → manages STAFF (employees, positions, contracts, HR member data)
- **School Registrar** → manages STUDENTS (registration, enrollment, transfers, student records, ID cards)

The `school_registrar` role already exists in `SchoolRegistrarActor.php` but is NOT wired into any dashboard, route, or permission system. This plan completes the registrar subsystem.

---

## 2. CURRENT STATE (RESEARCH FINDINGS)

### Role: `school_registrar` exists but is disconnected

| File | `school_registrar` present? | Status |
|------|----------------------------|--------|
| `admin/backend/services/SchoolRegistrarActor.php` | ✅ Yes | Actor class with transaction-time revalidation |
| `tests/e2e/school_registrar_actor.php` | ✅ Yes | E2E test for actor |
| `tests/e2e/school_registration_receipt.php` | ✅ Yes | References SchoolRegistrarActor |
| `admin/backend/user-save.php` | ❌ No | NOT in `validRoles` |
| `admin/backend/services/FeatureGate.php` | ❌ No | NOT in `forRoleDashboard()` |
| `admin/dashboard.php` | ❌ No | NOT in switch statement |
| `admin/backend/services/AdminSessionGuard.php` | ❌ No | NOT in `KNOWN_ROLES` or `PRIVILEGED_ROLES` |
| `admin/access_control.php` ROLE_MAP | ❌ No | Not in any page ACL |
| `admin/backend/services/MemberRegistrationPolicy.php` | ❌ No | Not in role capabilities |

### Student Registration Flow

- `admin/hr_register_member.php` — handles both quick_add (school_admin) and full registration (hr_dept, super_admin)
- ROLE_MAP: `['super_admin', 'school_admin', 'hr_dept']` — registrar NOT included
- `MemberRegistrationPolicy::prepare()` — gives `quick_add=true` only to `school_admin`; `allow_upgrade` only to `super_admin` and `hr_dept`

### Students Table Schema (server-verified)

All fields needed for student registration exist: `student_code`, `full_name_am`, `student_name`, `father_name`, `mother_name`, `grandfather_name`, `gender`, `date_of_birth`, `age`, `education_level`, `current_class_id`, `status`, `phone_number`, `guardian_name`, `guardian_phone1`, `city`, `sub_city`, `woreda`, `address`, `mender`, `block_number`, `house_number`, `student_photo_path`, etc.

### Existing Dashboard Pattern

- `school_admin.php` — 1652 lines, has Member Code Settings UI, quick add member, member list
- `hr-dept.php` — 4909 lines, full member registration with uploads
- `info-dept.php` — 4491 lines, full member registration with uploads

---

## 3. DESIGN DECISIONS

### 3.1 Role Separation

| Concern | HR Dept | School Registrar |
|---------|---------|------------------|
| Primary domain | STAFF / employees | STUDENTS |
| Member registration | Full staff + student registration | Full student registration only |
| Member upgrades | Can upgrade (allow_upgrade) | Cannot upgrade |
| Quick add | No (school_admin only) | No |
| File uploads | Yes | Yes |
| Position/identity codes | Yes (staff positions) | No (student codes only) |
| Student enrollment | Can enroll | Can enroll |
| Transfers | Read-only | Full transfer management |
| Student records/archives | Read-only | Full management |

### 3.2 Registrar Capabilities (MemberRegistrationPolicy)

| Capability | Value | Reason |
|------------|-------|--------|
| `quick_add` | `false` | Quick add is school_admin-only |
| `allow_uploads` | `true` | Registrar needs document uploads |
| `allow_upgrade` | `false` | Upgrades are HR/SuperAdmin only |
| `member_type` | configurable | Registrar sets member type |
| `status` | `active`/`waiting` | Registrar can set initial status |
| `registration_type` | `waiting`/`transfer`/`direct` | All types available |
| `membership_tier` | `permanent`/`temporary` | Both tiers |

### 3.3 Access Control (ROLE_MAP additions)

| File | Roles | Notes |
|------|-------|-------|
| `hr_register_member.php` | + `school_registrar` | Registrar can register students |
| `api_list_members.php` | + `school_registrar` | Read member directory |
| `api_check_duplicate.php` | + `school_registrar` | Check for duplicate students |
| `members.php` | + `school_registrar` | Member list page |
| `members_check.php` | + `school_registrar` | Member validation |
| `print_member.php` | + `school_registrar` | Print student records |
| `info_manage_member.php` | + `school_registrar` | Manage student records |
| `member_file.php` | + `school_registrar` | Upload/download student files |
| `registrar.php` (new) | `['super_admin', 'school_admin', 'school_registrar']` | Registrar dashboard |

---

## 4. IMPLEMENTATION PLAN (SLICES)

Per UNIVERSAL AI SOFTWARE CHANGE PROTOCOL: one service plus fixtures per commit.

### Slice 1: Role Registration (infrastructure)
1. Add `school_registrar` to `user-save.php` `$validRoles`
2. Add `school_registrar` to `FeatureGate::forRoleDashboard()` 
3. Add `school_registrar` to `AdminSessionGuard` `KNOWN_ROLES`
4. Add `school_registrar` route to `admin/dashboard.php` switch
5. Add `school_registrar` to `access_control.php` ROLE_MAP for registrar-relevant pages
6. `php -l` on all changed files

### Slice 2: Registration Policy
1. Add `school_registrar` to `MemberRegistrationPolicy::prepare()` capabilities
2. `php -l` on changed file

### Slice 3: Registration Endpoint Access
1. Add `school_registrar` to `hr_register_member.php` ROLE_MAP (via access_control.php)
2. `php -l` on changed file

### Slice 4: Registrar Dashboard
1. Create `admin/dashboards/registrar.php` (student-focused dashboard)
2. Create `admin/js/registrar.js` (registrar-specific JS)
3. `php -l` on changed file

### Slice 5: Deployment & Verification
1. Commit and push all slices
2. Deploy to server (`git pull`)
3. Run `php -l` on server for all changed files
4. Test registrar dashboard loads without errors
5. Verify error.log has zero new entries

---

## 5. FILES TO CHANGE

### Directly affected (must change)
1. `admin/backend/user-save.php` — add `school_registrar` to validRoles
2. `admin/backend/services/FeatureGate.php` — add to `forRoleDashboard()`
3. `admin/backend/services/AdminSessionGuard.php` — add to KNOWN_ROLES
4. `admin/dashboard.php` — add route case
5. `admin/access_control.php` — add ROLE_MAP entries
6. `admin/backend/services/MemberRegistrationPolicy.php` — add registrar capabilities
7. `admin/dashboards/registrar.php` — NEW: registrar dashboard
8. `admin/js/registrar.js` — NEW: registrar JS

### Indirectly affected (may need verification)
- `admin/api_settings.php` — registrar may need to read dept_settings
- `admin/api_list_members.php` — registrar reads member directory

---

## 6. ACCEPTANCE CRITERIA

1. [ ] `school_registrar` user can be created via superadmin dashboard
2. [ ] `school_registrar` can login and access registrar dashboard
3. [ ] Registrar dashboard loads without fatal SQL error
4. [ ] Registrar can register a new student via `hr_register_member.php`
5. [ ] Registrar can search/view student directory
6. [ ] `php -l` passes on all changed PHP files
7. [ ] Zero new entries in server error.log after deployment
8. [ ] HR and Registrar remain separate (HR cannot access registrar-only features, registrar cannot access HR-only features)

---

## 7. IMMEDIATE NEXT STEPS

1. **Start Slice 1** — Register `school_registrar` role in infrastructure files
2. **Implement Slice 2** — Registration policy capabilities
3. **Implement Slice 3** — Registration endpoint access
4. **Implement Slice 4** — Registrar dashboard + JS
5. **Deploy and verify**

---

## 8. BLOCKED ITEMS

None — all research complete, ready to implement.
