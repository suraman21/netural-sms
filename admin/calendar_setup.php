<?php
/**
 * admin/calendar_setup.php — the super admin's one-time calendar choice.
 *
 * ADR-0026 P2. Every installation defaults to the Ethiopian calendar; this page
 * is the only place that may change it, and only once, and only for a super
 * admin. It is a page rather than an API action on purpose: the write is
 * one-time and irreversible through this path, so it should be a deliberate
 * visit with a confirmation, not something a script posts to.
 *
 * The write itself lives in SchoolCalendarInitialSetup, which owns its
 * transaction, revalidates the account inside it, refuses a second choice, and
 * records the decision in school_calendar_configuration_audit.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/SchoolCalendarInitialSetup.php';

use App\Services\CalendarSetupActor;
use App\Services\SchoolCalendarInitialSetup;
use App\Services\CalendarAlreadyConfigured;
use App\Services\CalendarSetupOutcomeUnknown;

if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}
$role = (string)($_SESSION['admin_role'] ?? '');
// Reject an account that is only *acting* as super admin through impersonation:
// choosing a school-wide calendar belongs to the real owner of the account.
if ($role !== 'super_admin' || !empty($_SESSION['original_admin_role'])) {
    http_response_code(403);
    echo 'Only the Super Admin can set the school calendar.';
    exit;
}
$adminId = (int)$_SESSION['admin_id'];
header('Cache-Control: private, no-store');

$csrf = generateCsrfToken();
$error = null;
$done = null;

/**
 * The service revalidates a freshly authenticated session, so the page must hand
 * it the account's current password and authorization versions rather than a
 * role flag it happens to hold in the session.
 */
$buildActor = static function (mysqli $db, int $userId, string $role): CalendarSetupActor {
    $stmt = $db->prepare('SELECT password_hash, authorization_version FROM users WHERE id=? AND is_active=1');
    if (!$stmt) {
        throw new RuntimeException('Calendar account verification unavailable.');
    }
    try {
        if (!$stmt->bind_param('i', $userId) || !$stmt->execute()) {
            throw new RuntimeException('Calendar account verification unavailable.');
        }
        $result = $stmt->get_result();
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('Calendar account verification unavailable.');
        }
        try {
            $row = $result->fetch_assoc();
        } finally {
            $result->free();
        }
    } finally {
        $stmt->close();
    }
    if (!$row || !is_string($row['password_hash'])) {
        throw new DomainException('Calendar setup authorization no longer valid.');
    }
    return CalendarSetupActor::fromTrustedSession(
        $userId,
        $role,
        hash('sha256', $row['password_hash']),
        (string)$row['authorization_version']
    );
};

$state = ['mode' => null, 'revision' => 0];
try {
    $result = $conn->query('SELECT mode, revision FROM school_calendar_configuration WHERE id=1');
    if ($result instanceof mysqli_result) {
        try {
            $state = $result->fetch_assoc() ?: $state;
        } finally {
            $result->free();
        }
    }
} catch (Throwable $ignored) {
    // sql/095 not applied: the page still renders and says so below.
    $state = null;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!validateCsrf($token)) {
        $error = 'Your session expired. Reload the page and try again.';
    } elseif ($state === null) {
        $error = 'The calendar configuration table is not installed yet.';
    } else {
        try {
            $actor = $buildActor($conn, $adminId, $role);
            $outcome = SchoolCalendarInitialSetup::choose($conn, $actor, $_POST['mode'] ?? null);
            $done = $outcome['mode'];
            $state = ['mode' => $outcome['mode'], 'revision' => $outcome['revision']];
        } catch (CalendarAlreadyConfigured $e) {
            $error = 'The calendar has already been set. Changing it later needs an audited switch, which does not exist yet.';
        } catch (CalendarSetupOutcomeUnknown $e) {
            $error = 'The result could not be confirmed. Reload the page and check the current setting before trying again.';
        } catch (InvalidArgumentException $e) {
            $error = 'Choose either the Ethiopian or the Gregorian calendar.';
        } catch (DomainException $e) {
            $error = 'Your session is no longer authorised for this change. Sign in again.';
        } catch (Throwable $e) {
            $error = 'The calendar could not be set right now.';
        }
    }
}

$configured = is_array($state) && $state['mode'] !== null && $state['mode'] !== '';
$mode = $configured ? (string)$state['mode'] : null;
$label = ['ethiopian' => 'Ethiopian', 'gregorian' => 'Gregorian'];
$h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>School calendar setup</title>
<style>
:root{font-family:system-ui,sans-serif;color:#18233a;background:#f3f6fb}*{box-sizing:border-box}body{margin:0}
header{background:#183b57;color:white;padding:1.25rem}header a{color:white}
main{max-width:760px;margin:auto;padding:1.5rem}h1{font-size:1.6rem;margin:.5rem 0}
.note{background:#fff8e6;border:1px solid #e4c679;border-radius:.5rem;padding:1rem;line-height:1.55}
section{background:white;border:1px solid #d8e1ea;border-radius:.65rem;padding:1.1rem;margin-top:1rem}
h2{margin-top:0;font-size:1.15rem}
label.opt{display:flex;gap:.7rem;align-items:flex-start;border:1px solid #d8e1ea;border-radius:.5rem;padding:.85rem;margin:.6rem 0;cursor:pointer}
label.opt strong{display:block}label.opt span{color:#45576a;font-size:.9rem}
button{font:inherit;background:#164f76;color:white;padding:.6rem 1.1rem;border:0;border-radius:.35rem;cursor:pointer;margin-top:.8rem}
button:disabled{opacity:.5;cursor:not-allowed}
.ok{background:#e8f6ec;border:1px solid #8fc9a3;border-radius:.5rem;padding:1rem;margin-top:1rem}
.bad{background:#fdecec;border:1px solid #e39a9a;border-radius:.5rem;padding:1rem;margin-top:1rem}
dl{display:grid;grid-template-columns:auto 1fr;gap:.4rem 1rem;margin:0}dt{font-weight:600}dd{margin:0}
</style></head><body>
<header><a href="dashboard.php">← Dashboard</a><h1>School calendar</h1></header>
<main>
<div class="note">
  <strong>Ethiopian is the default.</strong> An installation that has not been set shows Ethiopian dates,
  so nothing changes unless you choose otherwise here. <strong>This is a one-time choice.</strong>
  Every future date — attendance, calendar, report cards, exports — follows it, and changing it later
  will need an audited switch that does not exist yet.
</div>

<?php if ($done !== null): ?>
  <div class="ok" role="status">Done — this installation now uses the <strong><?= $h($label[$done] ?? $done) ?></strong> calendar.</div>
<?php endif; ?>
<?php if ($error !== null): ?>
  <div class="bad" role="alert"><?= $h($error) ?></div>
<?php endif; ?>

<section>
  <h2>Current setting</h2>
  <?php if ($state === null): ?>
    <p>The calendar configuration table is not installed on this database yet, so the default
       (Ethiopian) is in use. Apply <code>sql/095_school_calendar_configuration.sql</code> first.</p>
  <?php else: ?>
    <dl>
      <dt>Calendar</dt>
      <dd><?= $configured ? $h($label[$mode] ?? $mode) : 'Ethiopian — default, not yet confirmed' ?></dd>
      <dt>Revision</dt>
      <dd><?= (int)($state['revision'] ?? 0) ?></dd>
    </dl>
  <?php endif; ?>
</section>

<?php if ($state !== null && !$configured): ?>
<section>
  <h2>Choose the calendar</h2>
  <form method="post" action="calendar_setup.php">
    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
    <label class="opt"><input type="radio" name="mode" value="ethiopian" required>
      <span><strong>Ethiopian</strong><span>The default. Ethiopian dates in every screen, with Gregorian
      kept where a form must store a real date.</span></span></label>
    <label class="opt"><input type="radio" name="mode" value="gregorian">
      <span><strong>Gregorian</strong><span>Gregorian dates in every screen. Choose this only if your
      institution works on the Gregorian year.</span></span></label>
    <button type="submit">Set the calendar</button>
  </form>
</section>
<?php elseif ($configured): ?>
<section>
  <h2>Change it later</h2>
  <p>The calendar is set. Changing it would move every existing date, so it is not offered here:
     it needs an audited switch with a recorded previous and new mode.</p>
</section>
<?php endif; ?>
</main></body></html>
