<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — save today's arthouse lineup opt-outs
//
//  Every arthouse screening is on the card by default — this is the mirror
//  of instagram_alamo.php's opt-in: checkboxes here start all checked, and
//  unchecking one excludes it. A checkbox that's unchecked simply doesn't
//  submit, so "excluded" is computed as (today's real lineup) minus
//  (whatever came back checked), not read directly off the POST.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/instagram.php';

$now = time();

$all = array_map('ig_film_key', ig_arthouse_films($conn));
$included = is_array($_POST['included'] ?? null) ? $_POST['included'] : [];
$excluded = array_values(array_diff($all, $included));

ig_excluded_write($now, $excluded);

header('Location: /_admin/instagram.php');
exit;
