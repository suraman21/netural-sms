<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * School Management Calendar System — PHP Helper
 * ═══════════════════════════════════════════════════════════════
 * 
 * Include this file to get:
 * 1. $calendarMode — 'ethiopian' or 'gregorian'
 * 2. wbws_format_date() — PHP date formatter respecting system setting
 * 3. wbws_calendar_scripts() — Returns <script> tags to include JS lib
 * 4. ethiopian_date_format functions (enhanced)
 * 
 * Usage in any dashboard:
 *   require_once __DIR__ . '/../backend/calendar_system.php';
 *   echo wbws_calendar_scripts();  // in <head>
 * ═══════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/ethiopian_date.php';

/**
 * The only value of a configuration row that may select Gregorian.
 *
 * ADR-0026 P2: anything other than an explicit 'gregorian' — an unset mode, a
 * NULL row, a value written by something other than the setup service — is the
 * Ethiopian default. Pure so it can be tested without a database.
 */
function wbws_calendar_mode_from_row(mixed $row): string {
    return (is_array($row) && ($row['mode'] ?? null) === 'gregorian') ? 'gregorian' : 'ethiopian';
}

/**
 * Get the system calendar mode.
 *
 * ADR-0026 P2: Ethiopian is the default for every installation. The ONE thing
 * that may change it is the row a super admin wrote at setup through
 * SchoolCalendarInitialSetup::choose() — one-time, super-admin-only, audited.
 *
 * Two older sources stay ignored on purpose, and the reason is the same for
 * both: they can be stale, and a stale value must not flip every date in the
 * UI. The legacy `dept_settings.calendar_mode` row is not read at all, and a
 * `$_SESSION['wbws_calendar_mode']` value is never trusted as input — it is
 * only written, as a display cache.
 *
 * Every failure path here degrades to Ethiopian rather than throwing: date
 * display must render even when sql/095 has not been applied yet.
 */
function wbws_get_calendar_mode($conn = null) {
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    // Callers frequently have no connection to hand (a template formatting one
    // date), so fall back to the request's own connection before deciding.
    if (!($conn instanceof mysqli)) {
        $conn = ($GLOBALS['conn'] ?? null);
    }
    $row = null;
    if ($conn instanceof mysqli) {
        try {
            $result = $conn->query('SELECT mode FROM school_calendar_configuration WHERE id=1');
            if ($result instanceof mysqli_result) {
                try { $row = $result->fetch_assoc(); } finally { $result->free(); }
            }
        } catch (\Throwable $ignored) {
            // Table absent, connection closed, server gone: the default.
            $row = null;
        }
    }

    $resolved = wbws_calendar_mode_from_row($row);
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['wbws_calendar_mode'] = $resolved;
    }
    if ($resolved === 'gregorian') {
        return 'gregorian';
    }
    return 'ethiopian';
}

/**
 * Format a date respecting the system calendar setting
 * 
 * @param mixed $input — DateTime object, date string, or timestamp
 * @param string $format — 'short', 'medium', 'long', 'full', or Ethiopian format tokens
 * @param mysqli|null $conn — DB connection (optional if session cached)
 * @return string
 */
function wbws_format_date($input, $format = 'medium', $conn = null) {
    if (empty($input)) return '—';
    
    $mode = wbws_get_calendar_mode($conn);
    
    // Parse input to DateTime
    if ($input instanceof DateTime) {
        $dt = $input;
    } else {
        try {
            $dt = new DateTime($input, new DateTimeZone('Africa/Addis_Ababa'));
        } catch (Exception $e) {
            return (string)$input;
        }
    }
    
    if ($mode === 'ethiopian') {
        return wbws_format_ethiopian($dt, $format);
    } else {
        return wbws_format_gregorian($dt, $format);
    }
}

function wbws_format_ethiopian($dt, $format) {
    $ec = gregorian_to_ethiopian($dt);
    $months_am = ['', 'መስከረም', 'ጥቅምት', 'ህዳር', 'ታህሳስ', 'ጥር', 'የካቲት',
                  'መጋቢት', 'ሚያዝያ', 'ግንቦት', 'ሰኔ', 'ሐምሌ', 'ነሐሴ', 'ጳጉሜ'];
    $months_short = ['', 'Mes', 'Tik', 'Hid', 'Tah', 'Tir', 'Yek',
                     'Meg', 'Miy', 'Gin', 'Sen', 'Ham', 'Neh', 'Pag'];
    $months_en = ['', 'Meskerem', 'Tikimt', 'Hidar', 'Tahsas', 'Tir', 'Yekatit',
                  'Megabit', 'Miyazya', 'Ginbot', 'Sene', 'Hamle', 'Nehasse', 'Pagume'];
    
    switch ($format) {
        case 'short':
            return sprintf('%02d/%02d/%d', $ec['day'], $ec['month'], $ec['year']);
        case 'medium':
            return $months_short[$ec['month']] . ' ' . $ec['day'] . ', ' . $ec['year'];
        case 'long':
            return $months_am[$ec['month']] . ' ' . $ec['day'] . ', ' . $ec['year'] . ' ዓ.ም.';
        case 'full':
            return $months_am[$ec['month']] . ' ' . $ec['day'] . ', ' . $ec['year'] . ' ዓ.ም.';
        case 'month-year':
            return $months_am[$ec['month']] . ' ' . $ec['year'];
        default:
            // Support ethiopian_date format tokens: F j, Y etc
            return ethio_date_format($dt, $format);
    }
}

function wbws_format_gregorian($dt, $format) {
    switch ($format) {
        case 'short':
            return $dt->format('d/m/Y');
        case 'medium':
            return $dt->format('M j, Y');
        case 'long':
            return $dt->format('F j, Y');
        case 'full':
            return $dt->format('l, F j, Y');
        case 'month-year':
            return $dt->format('F Y');
        default:
            return $dt->format($format);
    }
}

/**
 * Get the script tags needed for the calendar system
 * Include this in the <head> of every dashboard
 */
function wbws_calendar_scripts($conn = null) {
    static $rendered = false;
    if ($rendered) return '';
    $rendered = true;
    $mode = wbws_get_calendar_mode($conn) === 'gregorian' ? 'gregorian' : 'ethiopian';
    $version = (string)filemtime(__DIR__ . '/../js/wbws-calendar.js');
    return '<script>window.WBWS_CALENDAR_MODE=' . json_encode($mode) . ';</script>' . "\n" .
           '<script src="/admin/js/wbws-calendar.js?v=' . $version . '"></script>' . "\n";
}

/**
 * Get the Noto Sans Ethiopic font import (needed for Amharic dates)
 */
function wbws_font_import() {
    return "@import url('https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@400;600;700&display=swap');";
}
