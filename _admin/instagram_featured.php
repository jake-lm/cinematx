<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — save today's Featured checkboxes
//
//  Cosmetic only for now: a badge on the list card, a pill on the spotlight
//  page, and priority for spotlight slots when there isn't room for every
//  film — nothing else on the site knows about this yet. Keyed by
//  ig_film_key() rather than array position, so a save still lines up with
//  the right film even if the scrape re-runs in a different order.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/instagram.php';

$now = time();

// Only keys that are actually on today's card are kept — posted values are
// user input, and the day's real film list is the source of truth for what
// a valid key even looks like.
$valid = array_map('ig_film_key', ig_today_films($conn));
$posted = is_array($_POST['featured'] ?? null) ? $_POST['featured'] : [];
$keys = array_values(array_intersect($posted, $valid));

ig_featured_write($now, $keys);

header('Location: /_admin/instagram.php');
exit;
