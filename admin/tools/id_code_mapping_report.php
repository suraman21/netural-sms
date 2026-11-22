<?php
/**
 * admin/tools/id_code_mapping_report.php — WHO holds WHICH identity code.
 *
 * Owner decision N-5 (2026-11-16): "yes" — printed ID codes need a mapping
 * report before the member→student change touches them.
 *
 * Why this exists. Identity codes are printed on physical ID cards and embedded
 * in QR payloads. ADR-0022 D8 froze them, ADR-0027 M5 re-points tables without
 * renumbering, and ADR-0024 S5 says new student codes are plain sequential with
 * no prefix. All three are compatible only if nobody's EXISTING code silently
 * changes. This tool proves what is on paper: it lists every holder, their
 * current code, and the code the new scheme would assign — without writing
 * anything.
 *
 * READ-ONLY BY DESIGN. There is no --execute and no UPDATE anywhere in this
 * file. A renumbering is a physical-card problem, not a software one, and it is
 * the owner's decision per installation. This produces the sheet they sign off.
 *
 * Usage (CLI only, from the deployment root):
 *   php admin/tools/id_code_mapping_report.php                    # to stdout
 *   php admin/tools/id_code_mapping_report.php --csv=sheet.csv    # to a file
 *   php admin/tools/id_code_mapping_report.php --only-changed      # exceptions
 *
 * Exit codes: 0 report produced, 1 usage or database failure.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/../../config.php';

$args = array_slice($argv, 1);
$csvPath = null;
$onlyChanged = false;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--csv=')) {
        $csvPath = substr($arg, 6);
    } elseif ($arg === '--only-changed') {
        $onlyChanged = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php " . basename(__FILE__) . " [--csv=path] [--only-changed]\n");
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(1);
    }
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    fwrite(STDERR, "FATAL: database connection failed.\n");
    exit(1);
}
$conn->set_charset('utf8mb4');

/**
 * The code the new scheme would assign: plain sequential, no prefix (ADR-0024
 * S5). This is a PROJECTION for the report only — it is never written, and the
 * sequence is derived from the current row order so the sheet is reproducible.
 */
$sql = "SELECT id, student_code, legacy_student_code, student_name, father_name,
               status, registered_at
        FROM students
        ORDER BY id ASC";
$result = $conn->query($sql);
if (!$result) {
    // A deployment that has not run every migration still gets a usable report.
    $result = $conn->query(
        "SELECT id, student_code, NULL AS legacy_student_code, student_name,
                father_name, status, registered_at
         FROM students ORDER BY id ASC"
    );
}
if (!$result) {
    fwrite(STDERR, "FATAL: the students table could not be read.\n");
    exit(1);
}

$rows = [];
$sequence = 0;
while ($row = $result->fetch_assoc()) {
    $sequence++;
    $current = trim((string)($row['member_code'] ?? ''));
    $projected = sprintf('%06d', $sequence);
    $changed = $current !== '' && $current !== $projected;
    $rows[] = [
        'id' => (int)$row['id'],
        'name' => trim(((string)$row['student_name'] ?? '') . ' ' . ((string)$row['father_name'] ?? '')),
        'status' => (string)($row['status'] ?? ''),
        'current_code' => $current,
        'legacy_code' => trim((string)($row['legacy_member_code'] ?? '')),
        'projected_code' => $projected,
        'changes' => $changed ? 'YES' : '',
        'registered_at' => (string)($row['registered_at'] ?? ''),
    ];
}
$result->free();
$conn->close();

if ($onlyChanged) {
    $rows = array_values(array_filter($rows, static fn(array $r): bool => $r['changes'] === 'YES'));
}

$headers = ['id', 'name', 'status', 'current_code', 'legacy_code', 'projected_code', 'changes', 'registered_at'];

$fh = $csvPath !== null ? fopen($csvPath, 'w') : STDOUT;
if ($fh === false) {
    fwrite(STDERR, "FATAL: cannot write {$csvPath}\n");
    exit(1);
}

$changedCount = 0;
foreach ($rows as $r) {
    if ($r['changes'] === 'YES') {
        $changedCount++;
    }
}

if ($csvPath === null) {
    // Human-readable preamble, then the same data the CSV would hold.
    fwrite(STDOUT, "Identity code mapping report — READ ONLY, nothing was changed.\n");
    fwrite(STDOUT, "Holders: " . count($rows) . "  Codes that would change: {$changedCount}\n");
    fwrite(STDOUT, "A blank current_code means the holder has no code yet (new student).\n\n");
    fwrite(STDOUT, sprintf("%-8s %-28s %-12s %-14s %-14s %-8s\n",
        'ID', 'NAME', 'STATUS', 'CURRENT', 'PROJECTED', 'CHANGE'));
    fwrite(STDOUT, str_repeat('-', 90) . "\n");
    foreach ($rows as $r) {
        fwrite(STDOUT, sprintf("%-8d %-28.28s %-12.12s %-14.14s %-14.14s %-8s\n",
            $r['id'], $r['name'], $r['status'], $r['current_code'] ?: '—',
            $r['projected_code'], $r['changes']));
    }
    fwrite(STDOUT, "\nNothing was written. To act on this, the owner decides per installation;\n");
    fwrite(STDOUT, "renumbering changes physical cards, so it is not automatic.\n");
} else {
    fputcsv($fh, $headers);
    foreach ($rows as $r) {
        fputcsv($fh, array_values($r));
    }
    if ($fh !== STDOUT) {
        fclose($fh);
    }
    fwrite(STDOUT, "Wrote {$csvPath}: " . count($rows) . " rows, {$changedCount} changing. Nothing was modified.\n");
}

exit(0);
