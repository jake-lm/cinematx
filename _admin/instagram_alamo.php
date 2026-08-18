<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — save today's Alamo Drafthouse opt-ins
//
//  Alamo is excluded from the card by default (IG_VENUES) — this is what
//  lets a day's admin fold specific Alamo screenings back in. One checkbox
//  per film (ig_alamo_key(), title only), not per location/showtime — see
//  ig_alamo_films() for why.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/instagram.php';

$now = ig_admin_target_date();

$valid = array_map('ig_alamo_key', ig_alamo_films($conn, $now));
$posted = is_array($_POST['alamo'] ?? null) ? $_POST['alamo'] : [];
$keys = array_values(array_intersect($posted, $valid));

ig_alamo_write($now, $keys);

header('Location: /_admin/instagram.php');
exit;
