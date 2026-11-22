<?php
/**
 * Registrar Dashboard — Student Registration & Management
 * =========================================================
 * The School Registrar manages STUDENT registration, enrollment,
 * transfers, and student records. Separate from HR which manages STAFF.
 *
 * Registrar capabilities:
 *   - Full student registration (with document uploads)
 *   - Student search and directory
 *   - Class enrollment management
 *   - Transfer registration
 *   - Student record management
 *   - ID card / QR management
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/ethiopian_date.php';
require_once __DIR__ . '/../backend/calendar_system.php';
require_once __DIR__ . '/../backend/services/MemberCategory.php';

// session is already started and role checked in dashboard.php
$userName = (string) ($_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Registrar');
$userRole = (string) ($_SESSION['admin_role'] ?? 'School Registrar');
$username = $_SESSION['admin_username'] ?? '';

$today = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$todayFormatted = ethio_date_format($today, 'F j, Y');
$greeting = ((int)$today->format('H') < 12) ? 'Good Morning' : (((int)$today->format('H') < 17) ? 'Good Afternoon' : 'Good Evening');
$initials = strtoupper(substr($fullName = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Registrar', 0, 1));
$csrfToken = generateCsrfToken();

// ── Student Stats ──
$stats = [
    'total' => 0,
    'active' => 0,
    'waiting' => 0,
    'inactive' => 0,
    'archived' => 0,
    'male' => 0,
    'female' => 0,
    'sections' => 0,
    'registrations_today' => 0,
    'registrations_week' => 0,
];

if (isset($conn)) {
    // Core student counts
    $r = $conn->query("SELECT COUNT(*) t, SUM(status='active') a, SUM(status='waiting') w,
                              SUM(status='inactive') i, SUM(status='archived') ar,
                              SUM(gender='male') m, SUM(gender='female') f
                         FROM students WHERE status != 'archived'");
    if ($r && $row = $r->fetch_assoc()) {
        $stats['total'] = (int)($row['t'] ?? 0);
        $stats['active'] = (int)($row['a'] ?? 0);
        $stats['waiting'] = (int)($row['w'] ?? 0);
        $stats['inactive'] = (int)($row['i'] ?? 0);
        $stats['archived'] = (int)($row['ar'] ?? 0);
        $stats['male'] = (int)($row['m'] ?? 0);
        $stats['female'] = (int)($row['f'] ?? 0);
    }

    // Sections
    $r = $conn->query("SELECT COUNT(DISTINCT current_class_id) c FROM students WHERE status != 'archived' AND current_class_id IS NOT NULL");
    if ($r && $row = $r->fetch_assoc()) {
        $stats['sections'] = (int)($row['c'] ?? 0);
    }

    // Registrations today
    $td = date('Y-m-d');
    $r = $conn->query("SELECT COUNT(*) c FROM students WHERE DATE(created_at) = '$td'");
    if ($r && $row = $r->fetch_assoc()) {
        $stats['registrations_today'] = (int)($row['c'] ?? 0);
    }

    // Registrations this week
    $r = $conn->query("SELECT COUNT(*) c FROM students WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    if ($r && $row = $r->fetch_assoc()) {
        $stats['registrations_week'] = (int)($row['c'] ?? 0);
    }
}

// ── Recent Registrations ──
$recentRegs = [];
if (isset($conn)) {
    try {
        $r = $conn->query("SELECT id, student_code, student_name, father_name, gender, status, current_class_id, registered_at, created_at
                             FROM students
                             WHERE status != 'archived'
                             ORDER BY created_at DESC
                             LIMIT 10");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $recentRegs[] = $row;
            }
        }
    } catch (Throwable $e) {
        error_log('Registrar recent registrations: ' . $e->getMessage());
    }
}

// ── Section Distribution ──
$sectionDist = [];
if (isset($conn)) {
    try {
        $r = $conn->query("SELECT COALESCE(current_class_id, 'Unassigned') s, COUNT(*) c
                             FROM students
                             WHERE status != 'archived'
                             GROUP BY current_class_id
                             ORDER BY c DESC
                             LIMIT 10");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $sectionDist[] = $row;
            }
        }
    } catch (Throwable $e) {
        error_log('Registrar section distribution: ' . $e->getMessage());
    }
}

// ── Gender Distribution ──
$genderDist = [];
if (isset($conn)) {
    try {
        $r = $conn->query("SELECT gender g, COUNT(*) c FROM students WHERE status != 'archived' GROUP BY gender");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $genderDist[] = $row;
            }
        }
    } catch (Throwable $e) {
        error_log('Registrar gender distribution: ' . $e->getMessage());
    }
}

// ── Classes for enrollment dropdown ──
$classes = [];
if (isset($conn)) {
    try {
        $r = $conn->query("SELECT id, class_name, class_name_en, class_code FROM classes WHERE is_active = 1 ORDER BY level_order, class_name");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $classes[] = $row;
            }
        }
    } catch (Throwable $e) {
        error_log('Registrar classes: ' . $e->getMessage());
    }
}

// ── Registration types & member types ──
$registrationTypes = ['waiting' => 'Waiting / Pending', 'transfer' => 'Transfer', 'direct' => 'Direct'];
$memberTypes = ['regular' => 'Regular', 'special_regular' => 'Special Regular', 'honorary' => 'Honorary'];
$membershipTiers = ['permanent' => 'Permanent', 'temporary' => 'Temporary'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrar Dashboard - <?= SCHOOL_NAME_SHORT ?? 'School' ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📋</text></svg>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="sha384-/o6I2CkkWC//PSjvWC/eYN7l3xM3tJm8ZzVkCOfp//205QcE3mlGskpoHB6XqI+B" crossorigin="anonymous"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Noto+Serif+Ethiopic:wght@400;600;700&family=Poppins:wght@300;400;600;700&display=swap');
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', system-ui, -apple-ui, sans-serif; }
        body { background: #f8fafc; min-height: 100vh; }
        .amharic { font-family: 'Noto Serif Ethiopic', serif; }
        .sidebar { width: 260px; background: #1e293b; color: #fff; position: fixed; height: 100vh; z-index: 50; }
        .sidebar a { color: #cbd5e1; text-decoration: none; padding: 1rem; display: block; transition: background 0.2s; }
        .sidebar a:hover { background: #334155; }
        .sidebar a.active { background: #0ea5e9; color: #fff; }
        .main { margin-left: 260px; padding: 2rem; }
        .header { background: #fff; border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-card { background: #fff; border-radius: 12px; padding: 1.25rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .stat-value { font-size: 2rem; font-weight: 700; color: #1e293b; }
        .stat-label { font-size: 0.875rem; color: #64748b; margin-top: 0.25rem; }
        .section-card { background: #fff; border-radius: 12px; padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 1.5rem; }
        .btn { padding: 0.5rem 1rem; border-radius: 6px; border: none; cursor: pointer; font-weight: 500; transition: all 0.2s; }
        .btn-primary { background: #0ea5e9; color: #fff; }
        .btn-primary:hover { background: #0284c7; }
        .btn-success { background: #10b981; color: #fff; }
        .btn-success:hover { background: #059669; }
        .btn-outline { background: transparent; border: 1px solid #0ea5e9; color: #0ea5e9; }
        .btn-outline:hover { background: #0ea5e9; color: #fff; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 0.75rem; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f8fafc; font-weight: 600; color: #475569; font-size: 0.875rem; }
        tr:hover { background: #f8fafc; }
        .badge { display: inline-block; padding: 0.25rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; }
        .badge-active { background: #dcfce7; color: #166534; }
        .badge-waiting { background: #fef3c7; color: #92400e; }
        .badge-inactive { background: #fee2e2; color: #991b1b; }
        .badge-archived { background: #e2e8f0; color: #475569; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }
        .form-group { display: flex; flex-direction: column; gap: 0.5rem; }
        .form-group label { font-size: 0.875rem; font-weight: 600; color: #374151; }
        .form-group input, .form-group select, .form-group textarea { padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.875rem; }
        .form-group textarea { min-height: 80px; resize: vertical; }
        .hidden { display: none; }
        .text-amharic { font-family: 'Noto Serif Ethiopic', serif; }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="p-4 border-b border-slate-700">
            <h2 class="text-lg font-bold flex items-center gap-2">
                <i class="fa-solid fa-landmark text-blue-400"></i> Registrar
            </h2>
            <p class="text-xs text-slate-400 mt-1 amharic">የትምህርት መዝሙር</p>
        </div>
        <nav class="p-2">
            <a href="#" class="active"><i class="fa-solid fa-chart-pie mr-2"></i>Dashboard</a>
            <a href="#" onclick="showSection('register')"><i class="fa-solid fa-user-plus mr-2"></i>Register Student</a>
            <a href="#" onclick="showSection('directory')"><i class="fa-solid fa-users mr-2"></i>Student Directory</a>
            <a href="#" onclick="showSection('enrollment')"><i class="fa-solid fa-school mr-2"></i>Enrollment</a>
            <a href="#" onclick="showSection('transfers')"><i class="fa-solid fa-right-left mr-2"></i>Transfers</a>
            <a href="#" onclick="showSection('records')"><i class="fa-solid fa-folder-open mr-2"></i>Student Records</a>
            <a href="#" onclick="showSection('idcards')"><i class="fa-solid fa-id-card mr-2"></i>ID Cards</a>
        </nav>
        <div class="p-4 border-t border-slate-700 mt-auto">
            <div class="text-xs text-slate-400"><?= htmlspecialchars($userName) ?></div>
            <div class="text-xs text-blue-400 font-semibold amharic"><?= htmlspecialchars($userRole) ?></div>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main">
        <!-- Header -->
        <div class="header flex justify-between items-start">
            <div>
                <h1 class="text-2xl font-bold text-slate-800"><?= htmlspecialchars($greeting) ?>, Registrar</h1>
                <p class="text-slate-600 text-sm mt-0.5 amharic"><?= htmlspecialchars($todayFormatted) ?> • <?= htmlspecialchars($userRole) ?></p>
            </div>
            <div class="flex gap-2">
                <button class="btn btn-outline" onclick="location.reload()"><i class="fa-solid fa-rotate mr-1"></i>Refresh</button>
                <a href="/admin/logout.php" class="btn btn-outline" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;"><i class="fa-solid fa-sign-out mr-1"></i>Logout</a>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= $stats['total'] ?></div>
                <div class="stat-label">Total Students</div>
            </div>
            <div class="stat-card">
                <div class="stat-value badge badge-active"><?= $stats['active'] ?></div>
                <div class="stat-label">Active</div>
            </div>
            <div class="stat-card">
                <div class="stat-value badge badge-waiting"><?= $stats['waiting'] ?></div>
                <div class="stat-label">Waiting / Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-value badge badge-inactive"><?= $stats['inactive'] ?></div>
                <div class="stat-label">Inactive</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $stats['sections'] ?></div>
                <div class="stat-label">Sections</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $stats['registrations_today'] ?></div>
                <div class="stat-label">Registered Today</div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="section-card">
            <h3 class="text-lg font-bold text-slate-800 mb-4 flex items-center gap-2">
                <i class="fa-solid fa-bolt text-blue-500"></i> Quick Actions
            </h3>
            <div class="flex flex-wrap gap-3">
                <button class="btn btn-primary" onclick="showSection('register')">
                    <i class="fa-solid fa-user-plus mr-2"></i>Register New Student
                </button>
                <button class="btn btn-outline" onclick="showSection('directory')">
                    <i class="fa-solid fa-search mr-2"></i>Search Students
                </button>
                <button class="btn btn-outline" onclick="showSection('enrollment')">
                    <i class="fa-solid fa-school mr-2"></i>Manage Enrollment
                </button>
                <button class="btn btn-outline" onclick="showSection('transfers')">
                    <i class="fa-solid fa-right-left mr-2"></i>Process Transfer
                </button>
            </div>
        </div>

        <!-- Registrations Overview -->
        <div class="section-card">
            <h3 class="text-lg font-bold text-slate-800 mb-4 flex items-center gap-2">
                <i class="fa-solid fa-clock-rotate-left text-purple-500"></i> Recent Registrations
            </h3>
            <div class="overflow-x-auto">
                <table>
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Student Name</th>
                            <th>Father Name</th>
                            <th>Gender</th>
                            <th>Section</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody id="recentRegsBody">
                        <?php foreach ($recentRegs as $reg): ?>
                        <tr>
                            <td><span class="font-mono text-xs bg-slate-100 px-1 rounded"><?= htmlspecialchars($reg['student_code'] ?? '—') ?></span></td>
                            <td class="font-medium"><?= htmlspecialchars($reg['student_name'] ?? '') ?></td>
                            <td><?= htmlspecialchars($reg['father_name'] ?? '') ?></td>
                            <td><?= htmlspecialchars($reg['gender'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($reg['current_class_id'] ?? '—') ?></td>
                            <td><span class="badge badge-<?= $reg['status'] === 'active' ? 'active' : ($reg['status'] === 'waiting' ? 'waiting' : 'inactive') ?>"><?= ucfirst($reg['status'] ?? '—') ?></span></td>
                            <td class="text-xs text-slate-500"><?= date('M j, Y', strtotime($reg['created_at'] ?? '')) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentRegs)): ?>
                        <tr><td colspan="7" class="text-center text-slate-400 py-4">No recent registrations</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Charts / Distribution -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="section-card">
                <h4 class="font-bold text-slate-700 mb-3">Section Distribution</h4>
                <div id="sectionDistChart">
                    <?php foreach ($sectionDist as $sd): ?>
                    <div class="flex justify-between items-center mb-2">
                        <span><?= htmlspecialchars($sd['s'] ?? 'Unknown') ?></span>
                        <span class="font-mono text-xs"><?= (int)($sd['c'] ?? 0) ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($sectionDist)): ?>
                    <p class="text-slate-400 text-sm">No section data</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="section-card">
                <h4 class="font-bold text-slate-700 mb-3">Gender Distribution</h4>
                <div id="genderDistChart">
                    <?php foreach ($genderDist as $gd): ?>
                    <div class="flex justify-between items-center mb-2">
                        <span><?= htmlspecialchars($gd['g'] ?? '—') ?></span>
                        <span class="font-mono text-xs"><?= (int)($gd['c'] ?? 0) ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($genderDist)): ?>
                    <p class="text-slate-400 text-sm">No gender data</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ========================================= -->
        <!-- REGISTRATION SECTION (hidden by default)  -->
        <!-- ========================================= -->
        <div id="section-register" class="hidden section-card">
            <h3 class="text-lg font-bold text-slate-800 mb-4 flex items-center gap-2">
                <i class="fa-solid fa-user-plus text-blue-500"></i> Register New Student
            </h3>
            <form id="registerForm" class="form-grid">
                <div class="form-group">
                    <label>Full Name (Amharic) <span class="text-red-500">*</span></label>
                    <input type="text" name="full_name_am" required placeholder="e.g. ተማሪ አበበ">
                </div>
                <div class="form-group">
                    <label>Gender <span class="text-red-500">*</span></label>
                    <select name="gender" required>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Registration Type</label>
                    <select name="registration_type">
                        <option value="waiting">Waiting / Pending</option>
                        <option value="transfer">Transfer</option>
                        <option value="direct">Direct</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Member Type</label>
                    <select name="member_type">
                        <option value="regular">Regular</option>
                        <option value="special_regular">Special Regular</option>
                        <option value="honorary">Honorary</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Membership Tier</label>
                    <select name="membership_tier">
                        <option value="permanent">Permanent</option>
                        <option value="temporary">Temporary</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="active">Active</option>
                        <option value="waiting">Waiting</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text" name="phone_number" placeholder="+251 9xx xxx xxx">
                </div>
                <div class="form-group">
                    <label>Section / Class</label>
                    <select name="current_section">
                        <option value="">Select section</option>
                        <?php foreach ($classes as $cls): ?>
                        <option value="<?= htmlspecialchars($cls['class_name'] ?? '') ?>"><?= htmlspecialchars(($cls['class_name_en'] ?? '') . ' - ' . ($cls['class_name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Father Name</label>
                    <input type="text" name="father_name" placeholder="Father's full name">
                </div>
                <div class="form-group">
                    <label>Guardian Name</label>
                    <input type="text" name="guardian_name" placeholder="Guardian's full name">
                </div>
                <div class="form-group">
                    <label>Guardian Phone</label>
                    <input type="text" name="guardian_phone1" placeholder="+251 9xx xxx xxx">
                </div>
                <div class="form-group">
                    <label>City</label>
                    <input type="text" name="city" placeholder="City">
                </div>
                <div class="form-group">
                    <label>Sub City</label>
                    <input type="text" name="sub_city" placeholder="Sub city">
                </div>
                <div class="form-group">
                    <label>Woreda</label>
                    <input type="text" name="woreda" placeholder="Woreda">
                </div>
                <div class="form-group col-span-2">
                    <label>Address</label>
                    <textarea name="address" placeholder="Full address"></textarea>
                </div>
                <div class="form-group col-span-2">
                    <label>Student Photo</label>
                    <input type="file" name="student_photo" accept="image/*">
                </div>
                <div class="form-group col-span-2">
                    <label>Signed Form</label>
                    <input type="file" name="signed_form" accept="application/pdf,image/*">
                </div>
                <div class="form-group col-span-2">
                    <label>School Records</label>
                    <input type="file" name="school_records" accept="application/pdf,image/*">
                </div>
                <div class="form-group col-span-2">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <button type="submit" class="btn btn-success">
                        <i class="fa-solid fa-save mr-2"></i>Register Student
                    </button>
                    <button type="button" class="btn btn-outline" onclick="resetForm()">
                        <i class="fa-solid fa-eraser mr-2"></i>Reset
                    </button>
                </div>
            </form>
            <div id="registerResult" class="mt-3 p-3 rounded-lg hidden"></div>
        </div>

        <!-- ========================================= -->
        <!-- STUDENT DIRECTORY SECTION                 -->
        <!-- ========================================= -->
        <div id="section-directory" class="hidden section-card">
            <h3 class="text-lg font-bold text-slate-800 mb-4 flex items-center gap-2">
                <i class="fa-solid fa-users text-green-500"></i> Student Directory
            </h3>
            <div class="flex gap-2 mb-3">
                <input type="text" id="directorySearch" placeholder="Search by name, code, or phone..." class="flex-1 px-3 py-2 border border-d1d5db rounded-lg">
                <select id="directoryStatus" class="px-3 py-2 border border-d1d5db rounded-lg">
                    <option value="">All Status</option>
                    <option value="active">Active</option>
                    <option value="waiting">Waiting</option>
                    <option value="inactive">Inactive</option>
                </select>
                <button class="btn btn-primary" onclick="loadDirectory()"><i class="fa-solid fa-search mr-1"></i>Search</button>
            </div>
            <div class="overflow-x-auto">
                <table id="directoryTable">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Father</th>
                            <th>Gender</th>
                            <th>Section</th>
                            <th>Status</th>
                            <th>Phone</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="directoryBody"></tbody>
                </table>
            </div>
        </div>

        <!-- ========================================= -->
        <!-- ENROLLMENT SECTION                        -->
        <!-- ========================================= -->
        <div id="section-enrollment" class="hidden section-card">
            <h3 class="text-lg font-bold text-slate-800 mb-4 flex items-center gap-2">
                <i class="fa-solid fa-school text-amber-500"></i> Class Enrollment
            </h3>
            <p class="text-slate-600 text-sm mb-4">Assign students to classes and manage enrollment records.</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="form-group">
                    <label>Student Code / ID</label>
                    <input type="text" id="enrollStudentId" placeholder="Student code or ID">
                </div>
                <div class="form-group">
                    <label>Class</label>
                    <select id="enrollClassId">
                        <option value="">Select class</option>
                        <?php foreach ($classes as $cls): ?>
                        <option value="<?= (int)($cls['id'] ?? 0) ?>"><?= htmlspecialchars(($cls['class_name_en'] ?? '') . ' - ' . ($cls['class_name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <button class="btn btn-success" onclick="enrollStudent()"><i class="fa-solid fa-plus mr-1"></i>Enroll</button>
                </div>
            </div>
        </div>

        <!-- ========================================= -->
        <!-- TRANSFERS SECTION                         -->
        <!-- ========================================= -->
        <div id="section-transfers" class="hidden section-card">
            <h3 class="text-lg font-bold text-slate-800 mb-4 flex items-center gap-2">
                <i class="fa-solid fa-right-left text-rose-500"></i> Student Transfers
            </h3>
            <p class="text-slate-600 text-sm mb-4">Process student transfer registrations.</p>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="form-group">
                    <label>Student Code</label>
                    <input type="text" id="transferStudentCode" placeholder="Student code">
                </div>
                <div class="form-group">
                    <label>Transfer Type</label>
                    <select id="transferType">
                        <option value="transfer_in">Transfer In</option>
                        <option value="transfer_out">Transfer Out</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Reason</label>
                    <input type="text" id="transferReason" placeholder="Reason for transfer">
                </div>
                <div class="form-group">
                    <button class="btn btn-success" onclick="processTransfer()"><i class="fa-solid fa-arrow-right mr-1"></i>Process</button>
                </div>
            </div>
        </div>

        <!-- ========================================= -->
        <!-- RECORDS SECTION                           -->
        <!-- ========================================= -->
        <div id="section-records" class="hidden section-card">
            <h3 class="text-lg font-bold text-slate-800 mb-4 flex items-center gap-2">
                <i class="fa-solid fa-folder-open text-indigo-500"></i> Student Records
            </h3>
            <p class="text-slate-600 text-sm mb-4">View and manage student records, archives, and restoration.</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="form-group">
                    <label>Student Code / ID</label>
                    <input type="text" id="recordStudentId" placeholder="Student code or ID">
                </div>
                <div class="form-group">
                    <button class="btn btn-primary" onclick="viewStudentRecord()"><i class="fa-solid fa-eye mr-1"></i>View Record</button>
                </div>
                <div class="form-group">
                    <button class="btn btn-outline" onclick="archiveStudent()"><i class="fa-solid fa-archive mr-1"></i>Archive</button>
                </div>
            </div>
        </div>

        <!-- ========================================= -->
        <!-- ID CARDS SECTION                          -->
        <!-- ========================================= -->
        <div id="section-idcards" class="hidden section-card">
            <h3 class="text-lg font-bold text-slate-800 mb-4 flex items-center gap-2">
                <i class="fa-solid fa-id-card text-emerald-500"></i> ID Cards
            </h3>
            <p class="text-slate-600 text-sm mb-4">Generate and manage student ID cards with QR codes.</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="form-group">
                    <label>Student Code / ID</label>
                    <input type="text" id="idcardStudentId" placeholder="Student code or ID">
                </div>
                <div class="form-group">
                    <button class="btn btn-success" onclick="generateIdCard()"><i class="fa-solid fa-print mr-1"></i>Generate ID Card</button>
                </div>
                <div class="form-group">
                    <button class="btn btn-primary" onclick="viewIdCards()"><i class="fa-solid fa-list mr-1"></i>View All ID Cards</button>
                </div>
            </div>
        </div>

        <script>
            const CSRF_TOKEN = '<?= htmlspecialchars($csrfToken) ?>';

            function showSection(sectionId) {
                // Hide all sections
                document.querySelectorAll('[id^="section-"]').forEach(el => el.classList.add('hidden'));
                // Show selected section
                const section = document.getElementById('section-' + sectionId);
                if (section) section.classList.remove('hidden');
            }

            // Show dashboard by default
            showSection('dashboard');

            function resetForm() {
                document.getElementById('registerForm').reset();
                document.getElementById('registerResult').classList.add('hidden');
            }

            async function loadDirectory() {
                const search = document.getElementById('directorySearch').value;
                const status = document.getElementById('directoryStatus').value;
                const tbody = document.getElementById('directoryBody');
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-slate-400 py-4">Loading...</td></tr>';

                try {
                    const params = new URLSearchParams({ q: search, limit: 50 });
                    if (status) params.append('status', status);
                    const response = await fetch('/admin/api_list_members.php?' + params.toString(), {
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-CSRF-Token': CSRF_TOKEN }
                    });
                    const data = await response.json();
                    tbody.innerHTML = '';
                    if (data.status === 'success' && data.members) {
                        data.members.forEach(m => {
                            const tr = document.createElement('tr');
                            tr.innerHTML = `
                                <td><span class="font-mono text-xs bg-slate-100 px-1 rounded">${m.member_code || '—'}</span></td>
                                <td class="font-medium">${m.student_name || ''}</td>
                                <td>${m.father_name || ''}</td>
                                <td>${m.gender || '—'}</td>
                                <td>${m.current_class_id || '—'}</td>
                                <td><span class="badge badge-${m.status === 'active' ? 'active' : (m.status === 'waiting' ? 'waiting' : 'inactive')}">${m.status || '—'}</span></td>
                                <td>${m.phone_number || '—'}</td>
                                <td>
                                    <button class="btn btn-outline btn-sm" onclick="viewStudent(${m.id})"><i class="fa-solid fa-eye"></i></button>
                                </td>
                            `;
                            tbody.appendChild(tr);
                        });
                    } else {
                        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-slate-400 py-4">No students found</td></tr>';
                    }
                } catch (e) {
                    tbody.innerHTML = '<tr><td colspan="8" class="text-center text-red-500 py-4">Error loading directory</td></tr>';
                }
            }

            async function viewStudent(id) {
                try {
                    const response = await fetch('/admin/api/v1/routes/members.php?id=' + id, {
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json' }
                    });
                    const data = await response.json();
                    if (data.status === 'success') {
                        alert('Student: ' + (data.member.student_name || '') + '\nCode: ' + (data.member.member_code || '') + '\nSection: ' + (data.member.current_class_id || '—'));
                    }
                } catch (e) {
                    alert('Error loading student details');
                }
            }

            async function enrollStudent() {
                const studentId = document.getElementById('enrollStudentId').value;
                const classId = document.getElementById('enrollClassId').value;
                if (!studentId || !classId) {
                    alert('Please enter student ID and select a class');
                    return;
                }
                try {
                    const fd = new FormData();
                    fd.append('member_id', studentId);
                    fd.append('class_id', classId);
                    fd.append('csrf_token', CSRF_TOKEN);
                    const response = await fetch('/admin/api/enroll_student.php', {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json' }
                    });
                    const data = await response.json();
                    alert(data.message || 'Enrollment processed');
                    if (data.status === 'success') location.reload();
                } catch (e) {
                    alert('Error enrolling student');
                }
            }

            async function processTransfer() {
                const code = document.getElementById('transferStudentCode').value;
                const type = document.getElementById('transferType').value;
                const reason = document.getElementById('transferReason').value;
                if (!code || !reason) {
                    alert('Please enter student code and reason');
                    return;
                }
                try {
                    const fd = new FormData();
                    fd.append('student_code', code);
                    fd.append('transfer_type', type);
                    fd.append('reason', reason);
                    fd.append('csrf_token', CSRF_TOKEN);
                    const response = await fetch('/admin/api/process_transfer.php', {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json' }
                    });
                    const data = await response.json();
                    alert(data.message || 'Transfer processed');
                } catch (e) {
                    alert('Error processing transfer');
                }
            }

            function viewStudentRecord() {
                const id = document.getElementById('recordStudentId').value;
                if (!id) {
                    alert('Please enter student ID');
                    return;
                }
                window.location.href = '/admin/info_manage_member.php?id=' + encodeURIComponent(id);
            }

            function archiveStudent() {
                const id = document.getElementById('recordStudentId').value;
                if (!id) {
                    alert('Please enter student ID');
                    return;
                }
                if (confirm('Archive this student record?')) {
                    window.location.href = '/admin/info_archive_member.php?id=' + encodeURIComponent(id);
                }
            }

            function generateIdCard() {
                const id = document.getElementById('idcardStudentId').value;
                if (!id) {
                    alert('Please enter student ID');
                    return;
                }
                window.open('/admin/id_cards/generate_id_card.php?member_id=' + encodeURIComponent(id), '_blank');
            }

            function viewIdCards() {
                window.location.href = '/admin/id_cards/view_id_card.php';
            }

            // Handle registration form submission
            document.getElementById('registerForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                const btn = this.querySelector('button[type="submit"]');
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i>Registering...';

                const formData = new FormData(this);
                formData.append('csrf_token', CSRF_TOKEN);

                try {
                    const response = await fetch('/admin/hr_register_member.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json' }
                    });
                    const data = await response.json();
                    const resultDiv = document.getElementById('registerResult');
                    resultDiv.classList.remove('hidden');
                    resultDiv.className = 'mt-3 p-3 rounded-lg ' + (data.status === 'success' ? 'bg-green-50 border border-green-200 text-green-800' : 'bg-red-50 border border-red-200 text-red-800');
                    resultDiv.innerHTML = '<strong>' + (data.status === 'success' ? 'Success!' : 'Error!') + '</strong> ' + (data.message || '');

                    if (data.status === 'success') {
                        this.reset();
                        setTimeout(() => { resultDiv.classList.add('hidden'); }, 5000);
                    }
                } catch (err) {
                    const resultDiv = document.getElementById('registerResult');
                    resultDiv.classList.remove('hidden');
                    resultDiv.className = 'mt-3 p-3 rounded-lg bg-red-50 border border-red-200 text-red-800';
                    resultDiv.innerHTML = '<strong>Error!</strong> ' + err.message;
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-save mr-2"></i>Register Student';
                }
            });

            // Load directory on page load if section is shown
            document.addEventListener('DOMContentLoaded', function() {
                // Directory will load when user navigates to it
            });
        </script>
    </main>
</body>
</html>
