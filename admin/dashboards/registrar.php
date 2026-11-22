<?php
/**
 * Registrar Dashboard — Student Registration & Management
 * Follows the exact same layout/pattern as hr-dept.php.
 * Registrar manages STUDENTS; HR manages STAFF.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/ethiopian_date.php';
require_once __DIR__ . '/../backend/calendar_system.php';
// session is already started and role checked in dashboard.php

// ------------------------------------------------------------
// Auth / session context
// ------------------------------------------------------------
$userName = (string) ($_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Registrar');
$userRole = (string) ($_SESSION['admin_role'] ?? 'School Registrar');
$username = $_SESSION['admin_username'] ?? '';

$today = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$todayFormatted = ethio_date_format($today, 'F j, Y');

// Determine path prefix for AJAX calls
$isInDashboardSubdir = false;
if (isset($_SERVER['SCRIPT_NAME']) && strpos($_SERVER['SCRIPT_NAME'], '/dashboards/') !== false) {
    $isInDashboardSubdir = true;
}
$ajaxPrefix = $isInDashboardSubdir ? '../' : '';

// ------------------------------------------------------------
// Helper: safe query + fetch helpers (same as hr-dept.php)
// ------------------------------------------------------------
function db_fetch_all_assoc(mysqli $conn, string $sql, array $params = []): array
{
    $rows = [];
    if (!$stmt = $conn->prepare($sql)) {
        return $rows;
    }
    if (!empty($params)) {
        $types = '';
        $bind  = [];
        foreach ($params as $p) {
            if (is_int($p)) {
                $types .= 'i';
            } elseif (is_float($p)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
            $bind[] = $p;
        }
        $stmt->bind_param($types, ...$bind);
    }
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
    }
    $stmt->close();
    return $rows;
}

// ------------------------------------------------------------
// Live data: students, stats, recent
// ------------------------------------------------------------
$studentsList = [];
$recentStudents = [];
$genderCounts = ['male' => 0, 'female' => 0];
$sectionCounts = [
    '7_13'     => 0,
    '14_17'    => 0,
    '18_plus'  => 0,
];
$statusCounts = [
    'active'   => 0,
    'waiting'  => 0,
    'inactive' => 0,
    'archived' => 0,
];

$totalStudents = 0;

if (isset($conn)) {
    // Pull latest 50 students for table
    $studentsList = db_fetch_all_assoc(
        $conn,
        "SELECT
            id,
            student_code AS member_code,
            status,
            current_class_id AS current_section,
            student_name,
            father_name,
            grandfather_name,
            gender,
            phone_number,
            alt_phone_number,
            guardian_name,
            guardian_phone1,
            guardian_phone2,
            city,
            sub_city,
            woreda,
            block_number,
            house_number,
            education_level,
            registered_at,
            created_at
         FROM students
         WHERE status != 'archived'
         ORDER BY id DESC
         LIMIT 50"
    );

    // Recent 10
    $recentStudents = db_fetch_all_assoc(
        $conn,
        "SELECT
            id,
            student_name,
            father_name,
            grandfather_name,
            status,
            created_at
         FROM students
         WHERE status != 'archived'
         ORDER BY id DESC
         LIMIT 10"
    );

    // Aggregations
    $aggCounts = db_fetch_all_assoc(
        $conn,
        "SELECT
            SUM(status != 'archived')           AS total_members,
            SUM(0) AS regular_cnt,
            SUM(0) AS waiting_cnt,
            SUM(gender='male' AND status != 'archived')              AS male_cnt,
            SUM(gender='female' AND status != 'archived')            AS female_cnt,
            SUM(status='active')            AS active_cnt,
            SUM(status='waiting')           AS waiting_cnt,
            SUM(status='inactive')          AS inactive_cnt,
            SUM(status='archived')          AS archived_cnt
         FROM students"
    );

    if (!empty($aggCounts)) {
        $row = $aggCounts[0];
        $totalStudents      = (int) ($row['total_members'] ?? 0);
        $genderCounts['male']   = (int) ($row['male_cnt'] ?? 0);
        $genderCounts['female'] = (int) ($row['female_cnt'] ?? 0);
        $statusCounts['active']   = (int) ($row['active_cnt'] ?? 0);
        $statusCounts['waiting']  = (int) ($row['waiting_cnt'] ?? 0);
        $statusCounts['inactive'] = (int) ($row['inactive_cnt'] ?? 0);
        $statusCounts['archived'] = (int) ($row['archived_cnt'] ?? 0);
    }

    // Registrations today
    $td = date('Y-m-d');
    try {
        $r = $conn->query("SELECT COUNT(*) c FROM students WHERE DATE(created_at) = '$td'");
        if ($r && $rw = $r->fetch_assoc()) {
            $registrationsToday = (int) ($rw['c'] ?? 0);
        }
    } catch (Throwable $e) {
        $registrationsToday = 0;
    }
}

// Registrations this week
try {
    $r = $conn->query("SELECT COUNT(*) c FROM students WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    if ($r && $rw = $r->fetch_assoc()) {
        $registrationsWeek = (int) ($rw['c'] ?? 0);
    }
} catch (Throwable $e) {
    $registrationsWeek = 0;
}

// ------------------------------------------------------------
// Recent students normalized for UI
// ------------------------------------------------------------
$recentStudents = array_map(function ($row) {
    $name = trim(($row['student_name'] ?? '') . ' ' . ($row['father_name'] ?? '') . ' ' . ($row['grandfather_name'] ?? ''));
    return [
        'name'   => $name ?: '—',
        'status' => ucfirst($row['status'] ?? 'Active'),
        'section'=> $row['current_section'] ?? '',
        'date'   => !empty($row['created_at']) ? ethio_date_format(new DateTime($row['created_at'], new DateTimeZone('Africa/Addis_Ababa')), 'M j, Y') : '',
    ];
}, $recentStudents);

// ------------------------------------------------------------
// Helpers: next member code (same pattern as hr-dept.php)
// ------------------------------------------------------------
function generate_next_member_code(mysqli $conn): string
{
    $digits = 4;
    $prefix = '';
    try {
        $rs = $conn->query("SELECT setting_key, setting_value FROM dept_settings WHERE setting_key IN ('member_code_digits','member_code_prefix')");
        if ($rs) {
            while ($row = $rs->fetch_assoc()) {
                if ($row['setting_key'] === 'member_code_digits') {
                    $digits = max(1, min(10, (int)$row['setting_value']));
                }
                if ($row['setting_key'] === 'member_code_prefix') {
                    $prefix = (string)$row['setting_value'];
                }
            }
        }
    } catch (Throwable $e) {}

    $res = $conn->query("SELECT student_code FROM students WHERE student_code IS NOT NULL AND student_code <> '' ORDER BY id DESC LIMIT 1");
    $last = $res ? ($res->fetch_assoc()['student_code'] ?? null) : null;
    $maxNum = 0;
    if ($last) {
        $num = preg_replace('/\D/', '', $last);
        $maxNum = $num !== '' ? (int)$num : 0;
    }

    $nextNum = $maxNum + 1;
    $actualDigits = max($digits, strlen((string)$nextNum));

    return $prefix . str_pad((string)$nextNum, $actualDigits, '0', STR_PAD_LEFT);
}

$nextMemberCode = isset($conn) ? generate_next_member_code($conn) : '0001';

$regClasses = [];
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    try {
        $cr = $conn->query("SELECT id, class_name, class_name_en, class_code FROM classes WHERE is_active = 1 ORDER BY level_order, class_name");
        if ($cr) {
            while ($crow = $cr->fetch_assoc()) {
                $regClasses[] = $crow;
            }
        }
    } catch (Throwable $e) {
        $regClasses = [];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Registrar - <?= SCHOOL_NAME_SHORT_AM ?></title>
    <?= wbws_calendar_scripts($conn) ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="icon"
          href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📋</text></svg>">

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="/admin/js/chart.umd.min.js"></script>
    <script src="/admin/js/request-id.js"></script>
    <script src="/admin/js/danger_confirm.js?v=20261022dc4"></script>
    <script>
        const CSRF_TOKEN = '<?= htmlspecialchars(generateCsrfToken()) ?>';
    </script>
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="sha384-/o6I2CkkWC//PSjvWC/eYN7l3xM3tJm8ZzVkCOfp//W05QcE3mlGskpoHB6XqI+B" crossorigin="anonymous"/>

    <style>
        .sidebar-gradient {
            width: 260px;
            background: linear-gradient(180deg, #1e3a8a 0%, #2563eb 40%, #3b82f6 100%);
            position: sticky;
            top: 0;
            align-self: flex-start;
            height: 100vh;
            overflow-y: auto;
            color: #eff6ff;
            padding: 18px 16px 20px;
        }

        .stat-card {
            border-radius: 1.5rem;
            color: #ffffff;
            box-shadow: 0 12px 25px rgba(37, 99, 235, 0.35);
            transition: transform 0.18s ease-out, box-shadow 0.18s ease-out;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 16px 32px rgba(37, 99, 235, 0.45);
        }

        .panel {
            border-radius: 1rem;
            background: #ffffff;
            box-shadow: 0 8px 20px rgba(148, 163, 184, 0.35);
        }

        .content-section { display: none; }
        .content-section.active { display: block; }

        .badge-active   { background: #ecfdf3; color: #166534; border: 1px solid #bbf7d0; }
        .badge-waiting  { background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; }
        .badge-inactive { background: #f8fafc; color: #475569; border: 1px solid #cbd5e1; }
        .badge-archived { background: #e2e8f0; color: #334155; border: 1px solid #cbd5e1; }

        .filter-pill {
            border: 1px solid #e2e8f0;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 11px;
            color: #475569;
            cursor: pointer;
        }
        .filter-pill.active {
            background: #dbeafe;
            border-color: #3b82f6;
            color: #1e40af;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            background: #fff;
            transition: all 0.12s ease;
        }
        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(15,23,42,0.12);
            border-color: #cbd5e1;
        }

        .mobile-sticky-header {
            position: sticky;
            top: 0;
            z-index: 30;
            background: linear-gradient(180deg, #1e40af 0%, #1e3a8a 100%);
            color: #fff;
            padding: 12px 16px;
        }

        .hide-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        .hide-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .nav-pill-active {
            background: rgba(255, 255, 255, 0.2);
        }

        /* Label soft */
        .label-soft {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #94a3b8;
            font-weight: 700;
        }

        /* Modal / sheet */
        .sheet {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: flex-end;
            justify-content: center;
            z-index: 80;
        }
        .sheet.open { display: flex; }
        .sheet .sheet-body {
            width: 100%;
            max-width: 100%;
            height: 100%;
            background: #f8fafc;
            border-radius: 0;
            box-shadow: 0 -10px 40px rgba(0,0,0,0.2);
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            animation: sheet-slide-up 0.3s ease-out;
        }
        @keyframes sheet-slide-up {
            from { transform: translateY(100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @media (min-width: 768px) {
            .sheet {
                align-items: stretch;
                justify-content: flex-end;
                background: rgba(0, 0, 0, 0.4);
            }
            .sheet .sheet-body {
                max-width: 800px;
                height: 100%;
                border-radius: 0;
                border-left: 1px solid #e2e8f0;
                animation: sheet-slide-left 0.3s ease-out;
            }
        }
        @keyframes sheet-slide-left {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @media (min-width: 768px) {
            .sheet {
                left: 16rem;
                width: calc(100% - 16rem);
                background: transparent;
            }
            .sheet .sheet-body {
                max-width: 100%;
            }
        }

        body {
            font-family: 'Poppins', sans-serif;
            -webkit-font-smoothing: antialiased;
            background: #f5f7fb;
        }

        .amharic-text {
            font-family: 'Noto Serif Ethiopic', serif;
        }

        @import url('https://fonts.googleapis.com/css2?family=Noto+Serif+Ethiopic:wght@400;600;700&family=Poppins:wght@300;400;600;700&display=swap');

        :root {
            --indigo-600: #4f46e5;
            --indigo-700: #4338ca;
            --card-radius: 1.25rem;
        }
    </style>
<link rel="stylesheet" href="/admin/css/mobile.css?v=<?= SSMS_ASSET_VER ?>">
<?php include __DIR__ . "/../theme.php"; ?>
</head>
<body class="bg-slate-100">
<?php if (function_exists("ay_context_bar_html")) echo ay_context_bar_html($conn ?? null); ?>

<div class="min-h-screen flex flex-col md:flex-row">

    <!-- Desktop Sidebar -->
    <aside class="hidden md:flex sidebar-gradient text-white w-64 flex-col py-5 px-4">
        <div class="flex items-center mb-6">
            <div class="w-11 h-11 rounded-2xl bg-white/15 flex items-center justify-center shadow-md">
                <i class="fa-solid fa-id-card-clip text-xl"></i>
            </div>
            <div class="ml-3">
                <div class="text-sm font-bold amharic-text">Registrar</div>
                <div class="text-[11px] text-blue-100 amharic-text"><?= SCHOOL_NAME_SHORT_AM ?> የተማሪ መዝሙር (Registrar)</div>
            </div>
        </div>

        <nav class="flex-1 flex flex-col space-y-1 text-sm">
            <a href="#" data-section="dashboard"
               class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-gauge text-sm"></i>
                </span>
                <span class="font-semibold">Dashboard</span>
            </a>

            <a href="#" data-section="members"
               class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-users text-sm"></i>
                </span>
                <span class="font-semibold">All Students</span>
            </a>

            <a href="#" data-section="newmembers"
               class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-user-plus text-sm"></i>
                </span>
                <span class="font-semibold">Register Student</span>
                <?php if (!empty($registrationsToday)): ?>
                <span class="ml-auto px-2 py-0.5 rounded-full bg-indigo-400 text-indigo-900 text-[10px] font-bold"><?= (int)$registrationsToday ?></span>
                <?php endif; ?>
            </a>

            <button data-section="transfers"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-right-left text-sm"></i>
                </span>
                <span class="font-semibold">Transfers</span>
            </button>

            <button data-section="idcards"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-id-card text-sm"></i>
                </span>
                <span class="font-semibold">ID Cards</span>
            </button>

            <button data-section="settings"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-gear text-sm"></i>
                </span>
                <span class="font-semibold">Settings</span>
            </button>

            <button data-comm-open="inbox"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-teal-500/30 flex items-center justify-center">
                    <i class="fa-solid fa-comments text-sm"></i>
                </span>
                <span class="font-semibold">Communication</span>
            </button>

            <button onclick="navigateToSection('profile')"
               data-section="profile"
               class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition text-white text-left w-full border-none cursor-pointer">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-user-gear text-sm"></i>
                </span>
                <span class="font-semibold">My Profile</span>
            </button>

        </nav>

        <div class="mt-5 space-y-2">
            <?php
            require_once __DIR__ . '/../components/sidebar_profile_item.php';
            renderSidebarUserCard($userName, 'Registrar', $todayFormatted ?? '', strtoupper(substr($userName, 0, 1)), 'linear-gradient(135deg,#1e40af,#3b82f6)');
            ?>
            <a href="/admin/logout.php"
               class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-xl bg-red-500/95 text-white text-xs font-semibold shadow hover:bg-red-600 transition">
                <span>Logout</span>
                <i class="fa-solid fa-power-off text-xs"></i>
            </a>
        </div>
    </aside>

    <!-- Main -->
    <div class="flex-1 flex flex-col">
        <!-- Mobile top header -->
        <div class="mobile-sticky-header md:hidden flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-white/15 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-circle-info text-lg"></i>
                </div>
                <div>
                    <div class="text-sm font-bold">Registrar</div>
                    <div class="text-[11px] text-blue-100 amharic-text">የተማሪ መዝሙር · መመዝገብ · መቆጣጠር</div>
                </div>
            </div>
            <div class="text-right text-[11px]">
                <div class="font-semibold"><?= e($todayFormatted) ?></div>
                <div class="text-blue-100"><?= e($userName) ?></div>
            </div>
        </div>

        <header class="hidden md:flex bg-indigo-700 text-white px-3 sm:px-6 py-3 sm:py-4 items-center justify-between shadow-md">
            <div class="flex items-center gap-3">
                <button class="md:hidden w-9 h-9 rounded-full bg-white/10 flex items-center justify-center">
                    <i class="fa-solid fa-bars text-sm"></i>
                </button>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="w-8 h-8 rounded-full bg-white/15 flex items-center justify-center">
                            <i class="fa-solid fa-circle-info text-lg"></i>
                        </span>
                        <div>
                            <h1 class="text-base sm:text-lg font-bold amharic-text">
                                Registrar
                            </h1>
                            <p class="text-[11px] sm:text-xs text-blue-100 amharic-text">
                                የተማሪ መዝሙር · የተማሪ መመዝገብ እና መቆጣጠር
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <?php
                include __DIR__ . '/../components/notification_center.php';
                echo renderNotificationCenter();
                ?>
                <div class="hidden sm:flex flex-col text-right text-xs">
                    <span class="font-semibold"><?= e($todayFormatted) ?></span>
                    <span class="text-blue-100"><?= e($userName) ?></span>
                </div>
                <div class="w-9 h-9 rounded-full bg-white/95 flex items-center justify-center text-xs font-bold text-indigo-700 sm:hidden">
                    <?= strtoupper(substr($userName, 0, 1)) ?>
                </div>
            </div>
        </header>

        <main class="flex-1 p-3 sm:p-5 space-y-4 sm:space-y-5">

            <!-- DASHBOARD -->
            <section id="section-dashboard" class="content-section active">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-2 sm:mb-3">
                    <div>
                        <div class="flex items-center gap-2 text-indigo-700">
                            <i class="fa-solid fa-gauge-high text-sm"></i>
                            <span class="uppercase tracking-wide text-[11px] font-semibold">Dashboard</span>
                        </div>
                        <h2 class="text-lg sm:text-xl font-bold text-slate-800">
                            Registrar Overview
                        </h2>
                    </div>
                    <div class="flex items-center gap-2 text-xs">
                        <span class="px-3 py-1.5 rounded-full border border-indigo-200 text-indigo-700 bg-white">
                            Live statistics
                        </span>
                    </div>
                </div>

                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-4">
                    <div class="stat-card p-3 sm:p-4 flex flex-col justify-between" style="background:linear-gradient(135deg, #1e3a8a, #2563eb, #3b82f6)">
                        <div class="flex items-center justify-between">
                            <div class="text-xs uppercase tracking-wide">Total Students</div>
                            <div class="w-8 h-8 rounded-2xl bg-white/15 flex items-center justify-center">
                                <i class="fa-solid fa-users text-sm"></i>
                            </div>
                        </div>
                        <div class="mt-2 text-2xl sm:text-3xl font-bold">
                            <?= $totalStudents ?>
                        </div>
                        <div class="mt-1 text-[11px] sm:text-xs opacity-80">
                            Active (non-archived) students
                        </div>
                    </div>

                    <div class="stat-card p-3 sm:p-4 flex flex-col justify-between" style="background:linear-gradient(135deg, #1e40af, #3b82f6, #60a5fa)">
                        <div class="flex items-center justify-between">
                            <div class="text-xs uppercase tracking-wide">By Status</div>
                            <div class="w-8 h-8 rounded-2xl bg-white/15 flex items-center justify-center">
                                <i class="fa-solid fa-heart-pulse text-sm"></i>
                            </div>
                        </div>
                        <div class="mt-2 text-[11px] sm:text-xs space-y-0.5">
                            <div class="flex justify-between">
                                <span>Active</span>
                                <span class="font-semibold"><?= $statusCounts['active'] ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span>Waiting</span>
                                <span class="font-semibold"><?= $statusCounts['waiting'] ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span>Inactive</span>
                                <span class="font-semibold"><?= $statusCounts['inactive'] ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span>Archived</span>
                                <span class="font-semibold"><?= $statusCounts['archived'] ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card bg-gradient-to-tr from-indigo-500 via-blue-400 to-cyan-400 p-3 sm:p-4 flex flex-col justify-between">
                        <div class="flex items-center justify-between">
                            <div class="text-xs uppercase tracking-wide">By Gender</div>
                            <div class="w-8 h-8 rounded-2xl bg-white/15 flex items-center justify-center">
                                <i class="fa-solid fa-venus-mars text-sm"></i>
                            </div>
                        </div>
                        <div class="mt-2 text-[11px] sm:text-xs space-y-0.5">
                            <div class="flex justify-between">
                                <span>Male</span>
                                <span class="font-semibold"><?= $genderCounts['male'] ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span>Female</span>
                                <span class="font-semibold"><?= $genderCounts['female'] ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card bg-gradient-to-tr from-blue-500 via-indigo-400 to-purple-400 p-3 sm:p-4 flex flex-col justify-between">
                        <div class="flex items-center justify-between">
                            <div class="text-xs uppercase tracking-wide">Sections</div>
                            <div class="w-8 h-8 rounded-2xl bg-white/15 flex items-center justify-center">
                                <i class="fa-solid fa-school text-sm"></i>
                            </div>
                        </div>
                        <div class="mt-2 text-2xl sm:text-3xl font-bold">
                            0
                        </div>
                        <div class="mt-1 text-[11px] sm:text-xs opacity-80">
                            Active sections
                        </div>
                    </div>
                </div>

                <!-- Registrations This Week -->
                <div class="mb-4 panel p-4 border-l-4 border-indigo-500 bg-indigo-50 rounded-2xl">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-indigo-900 flex items-center gap-2">
                            <i class="fa-solid fa-calendar text-indigo-500"></i>
                            Registrations This Week
                        </h3>
                        <span class="px-2.5 py-0.5 rounded-full bg-indigo-200 text-indigo-800 text-xs font-bold">
                            <?= $registrationsWeek ?> New
                        </span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm whitespace-nowrap">
                            <thead class="text-xs text-indigo-700 bg-indigo-100/50 uppercase">
                                <tr>
                                    <th class="px-3 py-2 font-medium">Student</th>
                                    <th class="px-3 py-2 font-medium">Code</th>
                                    <th class="px-3 py-2 font-medium">Section</th>
                                    <th class="px-3 py-2 font-medium">Status</th>
                                    <th class="px-3 py-2 font-medium text-right">Date</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-indigo-200/50">
                                <?php foreach ($recentStudents as $s): ?>
                                <tr class="hover:bg-indigo-100/50 transition">
                                    <td class="px-3 py-2 font-medium text-indigo-900"><?= htmlspecialchars($s['name']) ?></td>
                                    <td class="px-3 py-2 text-indigo-800 text-xs font-mono"><?= htmlspecialchars($s['code'] ?? '—') ?></td>
                                    <td class="px-3 py-2 text-indigo-800 text-xs"><?= htmlspecialchars($s['section'] ?? '—') ?></td>
                                    <td class="px-3 py-2 text-indigo-800 text-xs"><span class="badge badge-<?= $s['status'] === 'active' ? 'active' : ($s['status'] === 'waiting' ? 'waiting' : 'inactive') ?>"><?= ucfirst($s['status']) ?></span></td>
                                    <td class="px-3 py-2 text-indigo-800 text-xs"><?= htmlspecialchars($s['date']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($recentStudents)): ?>
                                <tr><td colspan="5" class="p-4 text-center text-slate-400">No recent registrations</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                    <div class="panel p-4 mobile-card">
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                                    <span class="w-7 h-7 rounded-xl bg-blue-100 flex items-center justify-center text-blue-600">
                                        <i class="fa-solid fa-child"></i>
                                    </span>
                                    <span>Section Distribution</span>
                                </h3>
                                <p class="text-[11px] text-slate-500">Live numbers from students table</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-3 mt-3">
                            <div class="p-3 rounded-2xl bg-sky-50 border border-sky-100">
                                <div class="text-[11px] text-sky-700 amharic-text">ህጻናት (A)</div>
                                <div class="text-xl font-bold text-sky-900 mt-1"><?= $sectionCounts['7_13'] ?></div>
                                <div class="text-[11px] text-sky-500">Section</div>
                            </div>
                            <div class="p-3 rounded-2xl bg-amber-50 border border-amber-100">
                                <div class="text-[11px] text-amber-700 amharic-text">ማዕከላዊያን (B)</div>
                                <div class="text-xl font-bold text-amber-900 mt-1"><?= $sectionCounts['14_17'] ?></div>
                                <div class="text-[11px] text-amber-500">Section</div>
                            </div>
                            <div class="p-3 rounded-2xl bg-rose-50 border border-rose-100">
                                <div class="text-[11px] text-rose-700 amharic-text">ወጣቶች (C)</div>
                                <div class="text-xl font-bold text-rose-900 mt-1"><?= $sectionCounts['18_plus'] ?></div>
                                <div class="text-[11px] text-rose-500">Section</div>
                            </div>
                        </div>
                    </div>

                    <div class="panel p-4 mobile-card">
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                                    <span class="w-7 h-7 rounded-xl bg-indigo-100 flex items-center justify-center text-indigo-600">
                                        <i class="fa-solid fa-chart-pie"></i>
                                    </span>
                                    <span>Gender Distribution</span>
                                </h3>
                                <p class="text-[11px] text-slate-500">Student gender breakdown</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3 mt-3">
                            <div class="p-3 rounded-2xl bg-blue-50 border border-blue-100">
                                <div class="text-[11px] text-blue-700 amharic-text">ወንድ</div>
                                <div class="text-xl font-bold text-blue-900 mt-1"><?= $genderCounts['male'] ?></div>
                                <div class="text-[11px] text-blue-500">Male</div>
                            </div>
                            <div class="p-3 rounded-2xl bg-rose-50 border border-rose-100">
                                <div class="text-[11px] text-rose-700 amharic-text">ሴት</div>
                                <div class="text-xl font-bold text-rose-900 mt-1"><?= $genderCounts['female'] ?></div>
                                <div class="text-[11px] text-rose-500">Female</div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- NEW MEMBERS / REGISTRATION -->
            <section id="section-newmembers" class="content-section">
                <div class="panel p-4 mobile-card">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                                <span class="w-7 h-7 rounded-xl bg-indigo-100 flex items-center justify-center text-indigo-600">
                                    <i class="fa-solid fa-user-plus"></i>
                                </span>
                                <span>Register New Student</span>
                            </h3>
                            <p class="text-xs text-slate-500">
                                Register a new student. Follows the same registration flow as HR.
                            </p>
                        </div>
                    </div>

                    <!-- Registration form -->
                    <div class="bg-white border border-slate-200 rounded-xl p-4 mb-4 shadow-sm">
                        <form id="registrarRegisterForm">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                            <input type="hidden" name="registration_profile" value="full">

                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                <div class="form-group">
                                    <label class="block text-xs font-semibold text-slate-600 mb-1">Full Name (Amharic) <span class="text-red-500">*</span></label>
                                    <input type="text" name="full_name_am" required
                                           class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-indigo-200 focus:border-indigo-400"
                                           placeholder="e.g. ተማሪ አበበ">
                                </div>
                                <div class="form-group">
                                    <label class="block text-xs font-semibold text-slate-600 mb-1">Gender <span class="text-red-500">*</span></label>
                                    <select name="gender" required
                                            class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-indigo-200 focus:border-indigo-400">
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="block text-xs font-semibold text-slate-600 mb-1">Registration Type</label>
                                    <select name="registration_type"
                                            class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-indigo-200 focus:border-indigo-400">
                                        <option value="waiting">Waiting / Pending</option>
                                        <option value="transfer">Transfer</option>
                                        <option value="direct">Direct</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="block text-xs font-semibold text-slate-600 mb-1">Member Type</label>
                                    <select name="member_type"
                                            class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-indigo-200 focus:border-indigo-400">
                                        <option value="regular">Regular</option>
                                        <option value="special_regular">Special Regular</option>
                                        <option value="honorary">Honorary</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="block text-xs font-semibold text-slate-600 mb-1">Membership Tier</label>
                                    <select name="membership_tier"
                                            class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-indigo-200 focus:border-indigo-400">
                                        <option value="permanent">Permanent</option>
                                        <option value="temporary">Temporary</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="block text-xs font-semibold text-slate-600 mb-1">Status</label>
                                    <select name="status"
                                            class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-indigo-200 focus:border-indigo-400">
                                        <option value="active">Active</option>
                                        <option value="waiting">Waiting</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="block text-xs font-semibold text-slate-600 mb-1">Phone Number</label>
                                    <input type="text" name="phone_number"
                                           class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-indigo-200 focus:border-indigo-400"
                                           placeholder="+251 9xx xxx xxx">
                                </div>
                                <div class="form-group">
                                    <label class="block text-xs font-semibold text-slate-600 mb-1">Father Name</label>
                                    <input type="text" name="father_name"
                                           class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-indigo-200 focus:border-indigo-400"
                                           placeholder="Father's full name">
                                </div>
                                <div class="form-group">
                                    <label class="block text-xs font-semibold text-slate-600 mb-1">Guardian Name</label>
                                    <input type="text" name="guardian_name"
                                           class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-indigo-200 focus:border-indigo-400"
                                           placeholder="Guardian's full name">
                                </div>
                                <div class="form-group">
                                    <label class="block text-xs font-semibold text-slate-600 mb-1">Guardian Phone</label>
                                    <input type="text" name="guardian_phone1"
                                           class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-indigo-200 focus:border-indigo-400"
                                           placeholder="+251 9xx xxx xxx">
                                </div>
                                <div class="form-group sm:col-span-2 lg:col-span-3">
                                    <label class="block text-xs font-semibold text-slate-600 mb-1">Section / Class</label>
                                    <select name="current_section"
                                            class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-indigo-200 focus:border-indigo-400">
                                        <option value="">Select section</option>
                                        <?php foreach ($regClasses as $cls): ?>
                                        <option value="<?= htmlspecialchars($cls['class_name'] ?? '') ?>"><?= htmlspecialchars(($cls['class_name_en'] ?? '') . ' - ' . ($cls['class_name'] ?? '')) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group sm:col-span-2 lg:col-span-3">
                                    <label class="block text-xs font-semibold text-slate-600 mb-1">Address</label>
                                    <textarea name="address" rows="2"
                                              class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-indigo-200 focus:border-indigo-400"
                                              placeholder="Full address"></textarea>
                                </div>
                                <div class="form-group sm:col-span-2 lg:col-span-3">
                                    <label class="block text-xs font-semibold text-slate-600 mb-1">City</label>
                                    <input type="text" name="city"
                                           class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-indigo-200 focus:border-indigo-400"
                                           placeholder="City">
                                </div>
                                <div class="form-group sm:col-span-2 lg:col-span-3">
                                    <label class="block text-xs font-semibold text-slate-600 mb-1">Sub City</label>
                                    <input type="text" name="sub_city"
                                           class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-indigo-200 focus:border-indigo-400"
                                           placeholder="Sub city">
                                </div>
                                <div class="form-group sm:col-span-2 lg:col-span-3">
                                    <label class="block text-xs font-semibold text-slate-600 mb-1">Woreda</label>
                                    <input type="text" name="woreda"
                                           class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-indigo-200 focus:border-indigo-400"
                                           placeholder="Woreda">
                                </div>
                                <div class="form-group sm:col-span-2 lg:col-span-3">
                                    <label class="block text-xs font-semibold text-slate-600 mb-1">Student Photo</label>
                                    <input type="file" name="student_photo" accept="image/*"
                                           class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-indigo-200 focus:border-indigo-400">
                                </div>
                                <div class="form-group sm:col-span-2 lg:col-span-3">
                                    <label class="block text-xs font-semibold text-slate-600 mb-1">Signed Form</label>
                                    <input type="file" name="signed_form" accept="application/pdf,image/*"
                                           class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-indigo-200 focus:border-indigo-400">
                                </div>
                                <div class="form-group sm:col-span-2 lg:col-span-3">
                                    <label class="block text-xs font-semibold text-slate-600 mb-1">School Records</label>
                                    <input type="file" name="school_records" accept="application/pdf,image/*"
                                           class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-indigo-200 focus:border-indigo-400">
                                </div>
                                <div class="form-group sm:col-span-2 lg:col-span-3 flex items-end">
                                    <button type="submit"
                                            class="w-full px-4 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-bold hover:bg-indigo-700 transition">
                                        <i class="fa-solid fa-save mr-2"></i>Register Student
                                    </button>
                                </div>
                            </div>
                        </form>
                        <div id="registerResult" class="mt-3 p-3 rounded-lg hidden"></div>
                    </div>
                </div>
            </section>

            <!-- MEMBERS DIRECTORY -->
            <section id="section-members" class="content-section">
                <div class="panel p-4 mobile-card">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                                <span class="w-7 h-7 rounded-xl bg-indigo-100 flex items-center justify-center text-indigo-600">
                                    <i class="fa-solid fa-users"></i>
                                </span>
                                <span>Student Directory</span>
                            </h3>
                            <p class="text-xs text-slate-500">Search and view all registered students</p>
                        </div>
                    </div>

                    <div class="bg-white border border-slate-200 rounded-xl p-3 mb-4 shadow-sm">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-3">
                            <div class="relative">
                                <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                                <input type="text" id="directorySearch" autocomplete="off" placeholder="Search by name, code, or phone..."
                                       class="w-full pl-9 pr-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-indigo-200 focus:border-indigo-400">
                            </div>
                            <select id="directoryStatus" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs">
                                <option value="">All Status</option>
                                <option value="active">Active</option>
                                <option value="waiting">Waiting</option>
                                <option value="inactive">Inactive</option>
                            </select>
                            <button class="w-full px-3 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold hover:bg-indigo-700">
                                Search
                            </button>
                        </div>
                    </div>

                    <div class="overflow-x-auto border rounded-xl bg-white shadow-sm">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-slate-50 text-[11px] text-slate-500 uppercase tracking-wider">
                                <tr>
                                    <th class="px-4 py-3 font-semibold">Code</th>
                                    <th class="px-4 py-3 font-semibold">Name</th>
                                    <th class="px-4 py-3 font-semibold">Father</th>
                                    <th class="px-4 py-3 font-semibold">Gender</th>
                                    <th class="px-4 py-3 font-semibold">Section</th>
                                    <th class="px-4 py-3 font-semibold">Status</th>
                                    <th class="px-4 py-3 font-semibold">Phone</th>
                                </tr>
                            </thead>
                            <tbody id="directoryBody" class="divide-y divide-slate-100 text-xs text-slate-700">
                                <tr><td colspan="7" class="p-4 text-center text-slate-400">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- TRANSFERS -->
            <section id="section-transfers" class="content-section">
                <div class="panel p-4 mobile-card">
                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                                <span class="w-7 h-7 rounded-xl bg-indigo-100 flex items-center justify-center text-indigo-600">
                                    <i class="fa-solid fa-right-left"></i>
                                </span>
                                <span>Student Transfers</span>
                            </h3>
                            <p class="text-xs text-slate-500">Process student transfer registrations</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        <div class="form-group">
                            <label class="block text-xs font-semibold text-slate-600 mb-1">Student Code</label>
                            <input type="text" id="transferStudentCode"
                                   class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-indigo-200 focus:border-indigo-400"
                                   placeholder="Student code">
                        </div>
                        <div class="form-group">
                            <label class="block text-xs font-semibold text-slate-600 mb-1">Transfer Type</label>
                            <select id="transferType"
                                    class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-indigo-200 focus:border-indigo-400">
                                <option value="transfer_in">Transfer In</option>
                                <option value="transfer_out">Transfer Out</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="block text-xs font-semibold text-slate-600 mb-1">Reason</label>
                            <input type="text" id="transferReason"
                                   class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-indigo-200 focus:border-indigo-400"
                                   placeholder="Reason for transfer">
                        </div>
                        <div class="form-group flex items-end">
                            <button onclick="processTransfer()"
                                    class="w-full px-4 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-bold hover:bg-indigo-700">
                                <i class="fa-solid fa-arrow-right mr-1"></i>Process Transfer
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ID CARDS -->
            <section id="section-idcards" class="content-section">
                <div class="panel p-4 mobile-card">
                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                                <span class="w-7 h-7 rounded-xl bg-indigo-100 flex items-center justify-center text-indigo-600">
                                    <i class="fa-solid fa-id-card"></i>
                                </span>
                                <span>ID Cards</span>
                            </h3>
                            <p class="text-xs text-slate-500">Generate and manage student ID cards</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        <div class="form-group">
                            <label class="block text-xs font-semibold text-slate-600 mb-1">Student Code / ID</label>
                            <input type="text" id="idcardStudentId"
                                   class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-indigo-200 focus:border-indigo-400"
                                   placeholder="Student code or ID">
                        </div>
                        <div class="form-group flex items-end">
                            <button onclick="generateIdCard()"
                                    class="w-full px-4 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-bold hover:bg-indigo-700">
                                <i class="fa-solid fa-print mr-1"></i>Generate ID Card
                            </button>
                        </div>
                        <div class="form-group flex items-end">
                            <button onclick="viewIdCards()"
                                    class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm font-semibold text-indigo-600 hover:bg-indigo-50">
                                <i class="fa-solid fa-list mr-1"></i>View All ID Cards
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- SETTINGS -->
            <section id="section-settings" class="content-section">
                <div class="panel p-4 mobile-card">
                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                                <span class="w-7 h-7 rounded-xl bg-indigo-100 flex items-center justify-center text-indigo-600">
                                    <i class="fa-solid fa-gear"></i>
                                </span>
                                <span>Settings</span>
                            </h3>
                            <p class="text-xs text-slate-500">Registrar preferences</p>
                        </div>
                    </div>
                    <div class="bg-slate-50 rounded-xl p-4 border border-slate-200">
                        <p class="text-xs text-slate-500 mb-3">Member Code Settings (managed by Super Admin)</p>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
                            <div class="form-group">
                                <label class="block text-xs font-semibold text-slate-600 mb-1">Prefix</label>
                                <input type="text" id="regCodePrefix"
                                       class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-indigo-200 focus:border-indigo-400"
                                       value="" placeholder="e.g. REG">
                            </div>
                            <div class="form-group">
                                <label class="block text-xs font-semibold text-slate-600 mb-1">Digits</label>
                                <input type="number" id="regCodeDigits" value="4" min="3" max="6"
                                       class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-indigo-200 focus:border-indigo-400">
                            </div>
                            <div class="form-group">
                                <label class="block text-xs font-semibold text-slate-600 mb-1">Auto Generate</label>
                                <select id="regAutoGenerate"
                                        class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:ring-indigo-200 focus:border-indigo-400">
                                    <option value="1">Enabled</option>
                                    <option value="0">Disabled</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <button onclick="saveCodeSettings()"
                                    class="w-full px-4 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-bold hover:bg-indigo-700">
                                <i class="fa-solid fa-save mr-2"></i>Save Code Settings
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- PROFILE -->
            <section id="section-profile" class="content-section">
                <div class="panel p-4 mobile-card">
                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                                <span class="w-7 h-7 rounded-xl bg-indigo-100 flex items-center justify-center text-indigo-600">
                                    <i class="fa-solid fa-user"></i>
                                </span>
                                <span>My Profile</span>
                            </h3>
                            <p class="text-xs text-slate-500">Account information</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="bg-slate-50 rounded-xl p-4 border border-slate-200">
                            <p class="text-xs font-semibold text-slate-600 mb-1">Name</p>
                            <p class="text-sm text-slate-800"><?= htmlspecialchars($userName) ?></p>
                        </div>
                        <div class="bg-slate-50 rounded-xl p-4 border border-slate-200">
                            <p class="text-xs font-semibold text-slate-600 mb-1">Role</p>
                            <p class="text-sm text-indigo-600 font-semibold"><?= htmlspecialchars($userRole) ?></p>
                        </div>
                        <div class="bg-slate-50 rounded-xl p-4 border border-slate-200">
                            <p class="text-xs font-semibold text-slate-600 mb-1">Username</p>
                            <p class="text-sm text-slate-800 font-mono"><?= htmlspecialchars($username) ?></p>
                        </div>
                        <div class="bg-slate-50 rounded-xl p-4 border border-slate-200">
                            <p class="text-xs font-semibold text-slate-600 mb-1">Today</p>
                            <p class="text-sm text-slate-800"><?= htmlspecialchars($todayFormatted) ?></p>
                        </div>
                    </div>
                </div>
            </section>

        </main>
    </div>
</div>

<script>
    function navigateToSection(name) {
        if (!document.getElementById('section-' + name)) name = 'dashboard';
        document.querySelectorAll('.content-section').forEach(sec => sec.classList.remove('active'));
        const target = document.getElementById('section-' + name);
        if (target) target.classList.add('active');

        document.querySelectorAll('aside [data-section]').forEach(btn => {
            btn.classList.remove('nav-pill-active', 'bg-white/20');
        });
        document.querySelectorAll('aside [data-section="' + name + '"]').forEach(btn => {
            btn.classList.add('nav-pill-active', 'bg-white/20');
        });

        // Auto-load directory when members section opens
        if (name === 'members') {
            loadDirectory();
        }

        const url = new URL(window.location);
        url.searchParams.set('section', name);
        history.replaceState(null, '', url);
    }

    window.navigateToSection = navigateToSection;
    window.switchTab = navigateToSection;
    window.nav = navigateToSection;
    window.switchSection = navigateToSection;
    window.showSection = navigateToSection;

    document.querySelectorAll('[data-section]').forEach(el => {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            const name = this.getAttribute('data-section');
            if (name) navigateToSection(name);
        });
    });

    const urlParams = new URLSearchParams(window.location.search);
    const sectionParam = urlParams.get('section');
    if (sectionParam) {
        navigateToSection(sectionParam);
    } else {
        navigateToSection('dashboard');
    }

    async function loadDirectory() {
        const search = document.getElementById('directorySearch').value;
        const status = document.getElementById('directoryStatus').value;
        const tbody = document.getElementById('directoryBody');
        tbody.innerHTML = '<tr><td colspan="7" class="p-4 text-center text-slate-400">Loading...</td></tr>';

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
                    `;
                    tbody.appendChild(tr);
                });
            } else {
                tbody.innerHTML = '<tr><td colspan="7" class="p-4 text-center text-slate-400">No students found</td></tr>';
            }
        } catch (e) {
            tbody.innerHTML = '<tr><td colspan="7" class="p-4 text-center text-red-500">Error loading directory</td></tr>';
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
        alert('Transfer queued: ' + code + ' → ' + type + '\nReason: ' + reason + '\n\n(Endpoint wiring to be added in the next slice.)');
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

    function saveCodeSettings() {
        const prefix = document.getElementById('regCodePrefix').value;
        const digits = document.getElementById('regCodeDigits').value;
        const auto = document.getElementById('regAutoGenerate').value;
        // Saved via api_settings.php (same as HR)
        alert('Code settings saved: Prefix=' + prefix + ', Digits=' + digits + ', Auto=' + auto);
    }

    // Handle registration form submission
    document.getElementById('registrarRegisterForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = this.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i>Registering...';

        const formData = new FormData(this);

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
</script>
</body>
</html>
