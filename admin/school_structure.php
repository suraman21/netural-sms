<?php
/** Standalone hierarchy setup prototype; not connected to existing enrollment. */
require_once __DIR__ . '/config.php';
if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
    header('Location: index.php'); exit;
}
if (!empty($_SESSION['original_admin_role']) || !in_array($_SESSION['admin_role'] ?? '', ['super_admin', 'school_admin', 'edu_dept'], true)) {
    http_response_code(403); echo 'Access denied.'; exit;
}
header('Cache-Control: private, no-store');
$csrf = generateCsrfToken();
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
<title>School structure setup</title>
<style>
:root{font-family:system-ui,sans-serif;color:#18233a;background:#f3f6fb}*{box-sizing:border-box}body{margin:0}header{background:#183b57;color:white;padding:1.25rem}header a{color:white}main{max-width:1000px;margin:auto;padding:1.5rem}h1{font-size:1.6rem;margin:.5rem 0}.note{background:#fff8e6;border:1px solid #e4c679;border-radius:.5rem;padding:1rem;line-height:1.5}.grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-top:1rem}@media(max-width:720px){.grid{grid-template-columns:1fr}}section{background:white;border:1px solid #d8e1ea;border-radius:.65rem;padding:1.1rem}h2{margin-top:0;font-size:1.15rem}label{display:block;font-weight:600;margin:.8rem 0 .3rem}input,select,button{font:inherit;padding:.55rem;border:1px solid #aebed0;border-radius:.35rem;max-width:100%}input,select{width:100%}button{background:#164f76;color:white;cursor:pointer;margin-top:1rem}button:disabled{opacity:.5;cursor:not-allowed}ul{padding-left:1.2rem}li{margin:.35rem 0}#notice{min-height:1.5em;margin:1rem 0;font-weight:600}small{color:#45576a}
</style></head><body>
<header><a href="dashboard.php">← Dashboard</a><h1>School structure setup</h1></header>
<main><div class="note"><strong>Setup preview:</strong> Levels, grades and sections created here are not yet connected to classes, student enrollment, attendance, grades or reports. This page does not edit or delete items.</div>
<p id="notice" role="status" aria-live="polite">Loading school structure…</p>
<div class="grid"><section><h2>Current structure</h2><div id="structure"></div><button id="refresh" type="button">Reload and review</button></section>
<section><h2>Add an item</h2><form id="create-form">
<label for="kind">Item type</label><select id="kind"><option value="level">Level</option><option value="grade">Grade</option><option value="section">Section</option></select>
<div id="parent-wrap" hidden><label for="parent">Parent</label><select id="parent"></select></div>
<label for="name">Name</label><input id="name" required maxlength="150" autocomplete="off">
<label for="sort-order">Display order</label><input id="sort-order" type="number" min="-2147483648" max="2147483647" step="1" value="0" required>
<small>Lower values appear first. Names must be unique within the same parent.</small><br>
<button id="submit" type="submit" disabled>Add item</button></form></section></div></main>
<script src="js/school_structure.js"></script></body></html>
