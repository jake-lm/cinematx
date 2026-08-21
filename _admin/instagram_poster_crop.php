<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — save a manual spotlight-hero crop position
//
//  Called by fetch() from the "Adjust crop" panel in the Instagram admin
//  page (js/v7.js), not a page navigation, so this answers with JSON rather
//  than a redirect. Keyed by the poster's own hero URL (ig_poster_crop_write()
//  hashes it) rather than by date or film — the preference belongs to the
//  poster image and should carry forward the next time that film screens,
//  not just today.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/instagram.php';

header('Content-Type: application/json');

$posterUrl = is_string($_POST['poster_url'] ?? null) ? $_POST['poster_url'] : '';
$bias      = isset($_POST['bias']) && is_numeric($_POST['bias']) ? (float) $_POST['bias'] : null;

if ($bias === null || $bias < 0 || $bias > 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid crop position.']);
    exit;
}

// Only a hero URL that's actually on today's (or, past the prep-mode
// cutover, tomorrow's) card is a valid key — same "the day's real film list
// is the source of truth" rule instagram_carousel.php and
// instagram_alamo.php already follow. The crop itself still isn't
// date-scoped once saved — this is only about which posters are valid to
// adjust from whichever day the admin page is currently showing.
$validUrls = array_values(array_filter(array_map(
    fn($f) => $f['poster'] ? ig_hero_url($f['poster']) : null,
    ig_today_films($conn, ig_admin_target_date())
)));

if (!in_array($posterUrl, $validUrls, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown poster.']);
    exit;
}

ig_poster_crop_write($posterUrl, $bias);

echo json_encode(['ok' => true]);
