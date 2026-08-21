<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — save today's On the Carousel checkboxes
//
//  Which films get their own spotlight page. Whatever comes back checked is
//  saved verbatim — including an all-unchecked save, which means "no
//  spotlight pages today" and is meaningfully different from never having
//  saved at all (see ig_carousel_read()'s null-vs-[] distinction). Keyed by
//  ig_film_key() rather than array position, so a save still lines up with
//  the right film even if the scrape re-runs in a different order.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/instagram.php';

$now = ig_admin_target_date();

// Only keys that are actually on today's card are kept — posted values are
// user input, and the day's real film list is the source of truth for what
// a valid key even looks like.
$valid = array_map('ig_film_key', ig_today_films($conn, $now));
$posted = is_array($_POST['carousel'] ?? null) ? $_POST['carousel'] : [];
$keys = array_values(array_intersect($posted, $valid));

ig_carousel_write($now, $keys);

header('Location: /_admin/instagram.php');
exit;
