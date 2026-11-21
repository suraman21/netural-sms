<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║           SCHOOL BRANDING CONFIGURATION                     ║
 * ║  This ONE file controls ALL school-specific text, logos,    ║
 * ║  names, and branding across the entire system.              ║
 * ║                                                             ║
 * ║  TO DEPLOY FOR A NEW SCHOOL:                                ║
 * ║  1. Copy this file                                          ║
 * ║  2. Change the values below                                 ║
 * ║  3. Upload your logo/seal to the theme folder               ║
 * ║  4. Run the leak detector to verify                         ║
 * ╚══════════════════════════════════════════════════════════════╝
 */


// ┌─────────────────────────────────────────────────────────────┐
// │  SECTION 1: ACTIVE THEME                                    │
// │  Points to /themes/[name]/ for CSS, logos, and assets       │
// └─────────────────────────────────────────────────────────────┘
define('ACTIVE_THEME', 'fkss');


// ┌─────────────────────────────────────────────────────────────┐
// │  SECTION 2: SCHOOL IDENTITY                                 │
// │  The core names used across dashboards, titles, exports     │
// └─────────────────────────────────────────────────────────────┘

// Full system name (shown in page titles, API responses)
define('SCHOOL_NAME', 'Arkeo School');

// Short code (shown in headers, nav bars, mobile app)
define('SCHOOL_NAME_SHORT', 'Arkeo');

// Full Amharic name (shown in landing page, reports, ID cards).
//
// ADR-0026 P1 / N-1: the owner accepted a transliteration "for now" and asked
// that it be EASY TO CHANGE. So this is an ordinary configuration value, not a
// constant buried in code: edit this one line, or set SCHOOL_NAME_AMHARIC in the
// environment, and every surface follows — landing page, ID cards, report
// headers, PWA description, copyright. Nothing else needs touching, and no
// template hardcodes Amharic text.
define('SCHOOL_NAME_AMHARIC', getenv('SCHOOL_NAME_AMHARIC') ?: 'አርኪዮ ትምህርት ቤት');

// Short Amharic name (sidebar subtitles, compact labels). Derived from the full
// name so changing one line changes both; override only if the short form is
// not simply a prefix of the full one.
define('SCHOOL_NAME_SHORT_AM', getenv('SCHOOL_NAME_SHORT_AM') ?: 'አርኪዮ');

// English translation of the school name
define('SCHOOL_TRANSLATION_EN', 'Arkeo School');

// Tagline — English and Amharic
define('SCHOOL_TAGLINE', 'School Management System');
define('SCHOOL_TAGLINE_AM', 'የትምህርት ቤት አስተዳደር ስርዓት');

// What type of school is this? (used in labels, categories, AI prompts)
define('SCHOOL_TYPE', 'School');
define('SCHOOL_TYPE_AM', 'ትምህርት ቤት');


// ┌─────────────────────────────────────────────────────────────┐
// │  SECTION 3: PARISH / CHURCH IDENTITY                        │
// │  The parent church or organization this school belongs to   │
// └─────────────────────────────────────────────────────────────┘

// Parish/church full name — Amharic and English
define('PARISH_NAME_AM', 'የቦሌ ቡልቡላ ፍ/ሕ ቅድስት ድንግል ማርያም እና መ/መ/ ቅ/ዮሐንስ ቤ/ክ');
define('PARISH_NAME_EN', 'Bole Bulbula St. Mary & St. John Holy Church');

// Denomination — the broader religious organization
define('DENOMINATION_AM', 'የኢትዮጵያ ኦርቶዶክስ ተዋሕዶ ቤተ ክርስቲያን');
define('DENOMINATION_EN', 'Ethiopian Orthodox Tewahdo Church');

// Religious invocation (shown at top of ID cards)
// Set to empty string '' if not needed
define('RELIGIOUS_INVOCATION', 'በስመአብ ወወልድ ወመንፈስ ቅዱስ አሐዱ አምላክ');


// ┌─────────────────────────────────────────────────────────────┐
// │  SECTION 4: ID CARD TEXT                                    │
// │  All text that appears on printed member ID cards           │
// └─────────────────────────────────────────────────────────────┘

// Card title line — Amharic (e.g. "ውሉደ ብርሃን ሰንበት ት/ቤት አባል መታወቂያ ካርድ")
define('ID_CARD_TITLE_AM', SCHOOL_NAME_SHORT_AM . ' ' . SCHOOL_TYPE_AM . ' አባል መታወቂያ ካርድ');

// Card title line — English (e.g. "Wulde Birhan Sunday School Member ID Card")
define('ID_CARD_TITLE_EN', SCHOOL_TRANSLATION_EN . ' ' . SCHOOL_TYPE . ' Member ID Card');

// Card bottom line — short school name
define('ID_CARD_FOOTER_AM', SCHOOL_NAME_SHORT_AM . ' ' . SCHOOL_TYPE_AM);

// Signature labels
define('ID_CARD_SIG_HEAD_AM', 'የ' . SCHOOL_TYPE_AM . 'ቱ ሃላፊ ስምና ፊርማ');
define('ID_CARD_SIG_ADMIN_AM', 'የደብሩ አስተዳደር ስምና ፊርማ');

// Card disclaimer (bottom of back side)
define('ID_CARD_DISCLAIMER_AM', 'ማስታወሻ: ይህ መታወቂያ ካርድ እስከ የሚያበቃበት ቀን ብቻ ዋጋ አለው። ከጠፋ ለአስተዳደሩ ያሳውቁ።');

// Shared church artwork used on the front and the back of every card
define('ID_CARD_BACKGROUND', '/admin/id_cards/assets/backgrounds/id_card_bg.jpg');


// ┌─────────────────────────────────────────────────────────────┐
// │  SECTION 5: MEMBER CODE                                     │
// │  Format for member identification codes                     │
// └─────────────────────────────────────────────────────────────┘

// Prefix for member codes — EMPTY for plain 5-digit numbers (e.g. 48271)
// The registration backend generates random 5-digit codes; no prefix needed.
define('MEMBER_CODE_PREFIX', '');

// Full code format prefix with separator — empty (plain numbers)
define('MEMBER_CODE_FORMAT', '');


// ┌─────────────────────────────────────────────────────────────┐
// │  SECTION 6: DOMAIN & URLs                                   │
// │  Site domain, used in CORS, QR codes, links, cron paths     │
// └─────────────────────────────────────────────────────────────┘

// Primary domain (no trailing slash).
// ENVIRONMENT-FIRST (production cutover 2026-10): config.php loads the
// environment file (.fkss_env.php) BEFORE this file, so each deployment
// owns its domain there — production defines SITE_DOMAIN in
// /home/felegeki/.fkss_env.php. The fallback below only applies when the
// environment did not provide one, so development keeps today's value.
if (!defined('SITE_DOMAIN')) {
    define('SITE_DOMAIN', 'felegekidusan.arkeonethiopia.com');
}

// Full site URL
define('SITE_URL', 'https://' . SITE_DOMAIN);

// Admin URL
define('ADMIN_URL', SITE_URL . '/admin');

// Allowed CORS origins (for mobile API, app sessions)
define('CORS_ORIGINS', serialize([
    'https://' . SITE_DOMAIN,
    'http://' . SITE_DOMAIN,
    'https://www.' . SITE_DOMAIN,
]));

// Helper to get CORS origins as array
if (!function_exists('get_cors_origins')) {
    function get_cors_origins() {
        return unserialize(CORS_ORIGINS);
    }
}

// Hosting username (used in cron command paths, env file location)
define('HOSTING_USER', 'arkeonet');

// Path to .env file (secrets outside public_html)
define('ENV_FILE_NAME', '.fkss_env.php');


// ┌─────────────────────────────────────────────────────────────┐
// │  SECTION 7: ADMIN PANEL                                     │
// │  Dashboard branding, icons, footer text                     │
// └─────────────────────────────────────────────────────────────┘

define('ADMIN_PANEL_TITLE', SCHOOL_NAME_SHORT . ' Admin');
define('ADMIN_LOGO_ICON', '✝');
define('ADMIN_FOOTER_TEXT', SCHOOL_NAME_SHORT . ' Management System');


// ┌─────────────────────────────────────────────────────────────┐
// │  SECTION 8: DEPARTMENT NAMES                                │
// │  Amharic and English names for each department              │
// └─────────────────────────────────────────────────────────────┘

define('DEPT_INFO_NAME', 'ማብራሪያ ክፍል');
define('DEPT_INFO_NAME_EN', 'Information Department');
define('DEPT_EDU_NAME', 'ትምህርት ክፍል');
define('DEPT_EDU_NAME_EN', 'Education Department');
define('DEPT_FINANCE_NAME', 'ገንዘብ ክፍል');
define('DEPT_FINANCE_NAME_EN', 'Finance Department');
define('DEPT_MATERIAL_NAME', 'ቁሳቁስ ክፍል');
define('DEPT_MATERIAL_NAME_EN', 'Material Department');
define('DEPT_GROUPS_NAME', 'የማህበራት አስተዳደር');
define('DEPT_GROUPS_NAME_EN', 'Groups Management');


// ┌─────────────────────────────────────────────────────────────┐
// │  SECTION 9: THEME COLORS                                    │
// │  Primary, accent, and sidebar gradient colors               │
// └─────────────────────────────────────────────────────────────┘

// FKSS palette — gold + maroon, extracted from the official logo
// Gold: #F0C000 / #E0B020 · Maroon: #600000 / #500000 / #702000
define('THEME_PRIMARY', '#600000');        // Deep maroon — primary brand color
define('THEME_PRIMARY_LIGHT', '#8B2030');  // Lighter maroon for hovers
define('THEME_PRIMARY_DARK', '#400000');   // Darkest maroon
define('THEME_ACCENT', '#F0C000');         // Gold — primary accent
define('THEME_ACCENT_2', '#E0B020');       // Darker gold — secondary accent
define('THEME_SIDEBAR_TOP', '#400000');    // Sidebar gradient: dark maroon →
define('THEME_SIDEBAR_MID', '#600000');    //   mid maroon →
define('THEME_SIDEBAR_BOTTOM', '#702000'); //   warm maroon-brown


// ┌─────────────────────────────────────────────────────────────┐
// │  SECTION 10: FEATURE TOGGLES                                │
// │  Enable/disable system modules per deployment               │
// └─────────────────────────────────────────────────────────────┘
// Defaults stay compatible. Production may define boolean overrides in the
// out-of-web-root secrets/environment file before this file is loaded.

if (!defined('FEATURE_AI_CHATBOT')) define('FEATURE_AI_CHATBOT', true);
if (!defined('FEATURE_GROUPS')) define('FEATURE_GROUPS', true);
if (!defined('FEATURE_FINANCE')) define('FEATURE_FINANCE', true);
if (!defined('FEATURE_MATERIAL')) define('FEATURE_MATERIAL', true);
if (!defined('FEATURE_MEZMUR')) define('FEATURE_MEZMUR', true);
if (!defined('FEATURE_ID_CARDS')) define('FEATURE_ID_CARDS', true);
if (!defined('FEATURE_ATTENDANCE')) define('FEATURE_ATTENDANCE', true);
if (!defined('FEATURE_GRADES')) define('FEATURE_GRADES', true);
if (!defined('FEATURE_REPORTS')) define('FEATURE_REPORTS', true);
if (!defined('FEATURE_EXPORT_PDF')) define('FEATURE_EXPORT_PDF', true);
if (!defined('FEATURE_MONITOR')) define('FEATURE_MONITOR', true);
// Attendance alerting v2 (department status periods, attendance policies,
// department-scoped warnings). Ships OFF; enable per deployment once the
// Phase 1+ schema (sql/071+) has been applied.
if (!defined('FEATURE_ATTENDANCE_V2')) define('FEATURE_ATTENDANCE_V2', false);
// Review-only Telegram registration stays fail-closed until each deployment
// has its own bot token, webhook secret, allowlist, and sql/080 migration.
if (!defined('FEATURE_TELEGRAM_REGISTRATION')) define('FEATURE_TELEGRAM_REGISTRATION', false);

// Destructive synthetic-data tooling is off in production unless a deployment
// explicitly enables it in the out-of-web-root environment file.
if (!defined('ENABLE_TEST_DATA_TOOLS')) define('ENABLE_TEST_DATA_TOOLS', false);


// ┌─────────────────────────────────────────────────────────────┐
// │  SECTION 11: FILE PATHS                                     │
// │  Logo, seal, and asset paths (relative to theme folder)     │
// └─────────────────────────────────────────────────────────────┘

// Theme base path
define('THEME_BASE_PATH', '/themes/' . ACTIVE_THEME);

// Logo and seal (inside theme folder)
define('SCHOOL_LOGO_PATH', THEME_BASE_PATH . '/assets/logos/school_logo.png');
define('SCHOOL_SEAL_PATH', THEME_BASE_PATH . '/assets/seals/school_seal.png');

// Legacy paths (admin ID card system still references these)
define('SCHOOL_LOGO_PATH_LEGACY', '/admin/id_cards/assets/logos/school_logo.png');
define('SCHOOL_SEAL_PATH_LEGACY', '/admin/id_cards/assets/seals/school_seal.png');


// ┌─────────────────────────────────────────────────────────────┐
// │  SECTION 12: EXPORT & FILE NAMING                           │
// │  Prefix for downloads, backups, cache keys                  │
// └─────────────────────────────────────────────────────────────┘

// Prefix for export filenames (CSV, PDF, DOCX, XLSX downloads)
define('EXPORT_PREFIX', strtolower(SCHOOL_NAME_SHORT));

// Backup SQL header comment
define('BACKUP_HEADER', SCHOOL_NAME_SHORT . ' Automated Backup');

// PWA cache name
define('PWA_CACHE_NAME', strtolower(SCHOOL_NAME_SHORT) . '-v5');

// PWA cache prefix
define('PWA_CACHE_PREFIX', strtolower(SCHOOL_NAME_SHORT) . '_');


// ┌─────────────────────────────────────────────────────────────┐
// │  SECTION 13: PWA / MOBILE APP                               │
// │  Progressive Web App manifest values                        │
// └─────────────────────────────────────────────────────────────┘

define('PWA_NAME', SCHOOL_NAME_SHORT . ' — ' . SCHOOL_NAME_SHORT_AM . ' ' . SCHOOL_TYPE);
define('PWA_SHORT_NAME', SCHOOL_NAME_SHORT);
define('PWA_DESCRIPTION', SCHOOL_NAME_SHORT_AM . ' የ' . SCHOOL_TYPE_AM . ' ' . SCHOOL_TAGLINE);


// ┌─────────────────────────────────────────────────────────────┐
// │  SECTION 14: LANDING PAGE                                   │
// │  Public-facing homepage text                                │
// └─────────────────────────────────────────────────────────────┘

// Mission statement
define('LANDING_MISSION_EN', 'To nurture young souls in the teachings of the ' . DENOMINATION_EN . ', fostering spiritual growth, moral values, and a deep connection with our faith and heritage.');
define('LANDING_MISSION_AM', 'የኛ ተልእኮ ልጆችን በ' . DENOMINATION_AM . ' ትምህርቶች እያሳደግን መንፈሳዊ እድገትን፣ የሞራል እሴቶችን እና ከእምነታችን እና ከቅርሶቻችን ጋር ጥልቅ ግንኙነት መፍጠር ነው።');

// Copyright
define('COPYRIGHT_YEAR', '2026');
define('COPYRIGHT_TEXT', SCHOOL_NAME_AMHARIC . ' - ' . SCHOOL_TRANSLATION_EN . ' ' . SCHOOL_TYPE);

// ── Contact block ─────────────────────────────────────────────
// Every line of the "Get in Touch" panel. An EMPTY value hides that
// row completely, so a deployment that configures nothing prints no
// contact details rather than a placeholder or a previous occupant's.
//
// These were hardcoded in index.php until 2026-11-16, and the values
// there were wrong for this deployment: the email address named a
// different school (WBWS / Wulide Birhan) and the phone was still the
// "+251 XXX XXX XXX" placeholder. They are therefore EMPTY here on
// purpose — fill in this deployment's real details to show the rows.
define('LANDING_CONTACT_ADDRESS', '');
define('LANDING_CONTACT_PHONE', '');
define('LANDING_CONTACT_PHONE_HOURS', '');
define('LANDING_CONTACT_EMAIL', '');
define('LANDING_CONTACT_EMAIL_NOTE', '');
define('LANDING_CONTACT_MAP_URL', '');
define('LANDING_OFFICE_HOURS_1', '');
define('LANDING_OFFICE_HOURS_2', '');

// The hardcoded Sunday/Wednesday timetable in index.php is church
// deployment content. When the schedule table is empty, the page shows
// these defaults only if this is true; a general-school deployment sets
// it to false and the section is omitted instead.
define('LANDING_FALLBACK_SCHEDULE_ENABLED', true);


// ┌─────────────────────────────────────────────────────────────┐
// │  SECTION 15: MONITOR / UPTIME                               │
// │  Error monitor and uptime check branding                    │
// └─────────────────────────────────────────────────────────────┘

define('MONITOR_PROJECT_NAME', SCHOOL_NAME_SHORT . ' ' . SCHOOL_TYPE);
define('MONITOR_PAGE_TITLE', SCHOOL_NAME_SHORT . ' Monitor');


// ┌─────────────────────────────────────────────────────────────┐
// │  SECTION 16: DEVELOPER CREDIT                               │
// │  Set DEVELOPER_SHOW_CREDIT to false to hide                 │
// └─────────────────────────────────────────────────────────────┘

define('DEVELOPER_NAME', 'Arkeon Agency');
define('DEVELOPER_SHOW_CREDIT', true);


// ┌─────────────────────────────────────────────────────────────┐
// │  SECTION 17: AI ASSISTANT CONTEXT                           │
// │  Context given to AI chatbot about this school              │
// └─────────────────────────────────────────────────────────────┘

define('AI_SCHOOL_CONTEXT', 'This is an Ethiopian ' . SCHOOL_TYPE . ' (' . SCHOOL_TYPE_AM . ') belonging to ' . DENOMINATION_EN . '.');


// ┌─────────────────────────────────────────────────────────────┐
// │  SECTION 18: API                                            │
// │  REST API identification                                    │
// └─────────────────────────────────────────────────────────────┘

define('API_NAME', SCHOOL_NAME);
define('API_MEMBER_PREFIX', MEMBER_CODE_PREFIX);


// ┌─────────────────────────────────────────────────────────────┐
// │  SECTION 19: GROUPS MODULE LABELS                           │
// │  Category labels used in groups management                  │
// └─────────────────────────────────────────────────────────────┘

define('GROUP_CAT_SS', SCHOOL_TYPE);
define('GROUP_CAT_SS_AM', SCHOOL_TYPE_AM);
define('GROUP_CAT_PARISH', 'Parish');
define('GROUP_CAT_PARISH_AM', 'ደብር');
define('GROUP_UNDER_SS_LABEL', 'Under ' . SCHOOL_TYPE);
define('GROUP_UNDER_SS_LABEL_AM', 'በ' . SCHOOL_TYPE_AM . ' ስር');


// ┌─────────────────────────────────────────────────────────────┐
// │  LEAK DETECTION KEYWORDS                                    │
// │  The leak detector searches for these strings to verify     │
// │  no old school data remains after deployment.               │
// │  UPDATE THESE when deploying to a new school!               │
// └─────────────────────────────────────────────────────────────┘

define('LEAK_DETECT_KEYWORDS', serialize([
    'WBWS',
    'wbws',
    'Wulde Birhan',
    'Wulud Birhan',
    'Wlude Brhan',
    'ውሉደ ብርሃን',
    'ውሉደ',
    'Children of Light',
    'Gelana Gura',
    'ገላን ጉራ',
    'ፈለገግዮን',
    'ቅዱስ ገብርኤል',
    'wbws.pro.et',
    'wbwsprvr',
    'Wolaita Bethel',
]));
