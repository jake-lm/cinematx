<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — save a manual poster crop position, from the Chapters panel list
//
//  Twin of _admin/instagram_poster_crop.php — same params, same global,
//  poster-URL-keyed store (ig_poster_crop_write()/ig_poster_crop_bias()),
//  so a crop adjusted here also affects that poster on the Instagram
//  carousel/spotlight pages and vice versa (it's the poster's own crop,
//  not scoped to any one feature). The one real difference: Forecast
//  episodes are per-admin-owned and per-week, so the allowlist here is
//  scoped to this episode's week rather than to "today," and the request
//  has to carry which episode it's for.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/forecast.php';

header('Content-Type: application/json');

$episode_id = (int) ($_POST['episode_id'] ?? 0);
$episode = forecast_get_episode($conn, $episode_id);
if (!$episode || (int) $episode['uid'] !== (int) $admin_user['id']) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Unknown episode.']);
    exit;
}

$posterUrl = is_string($_POST['poster_url'] ?? null) ? $_POST['poster_url'] : '';
$bias      = isset($_POST['bias']) && is_numeric($_POST['bias']) ? (float) $_POST['bias'] : null;

if ($bias === null || $bias < 0 || $bias > 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid crop position.']);
    exit;
}

$byDay = forecast_all_week_films($conn, $episode['week_of']);
$validUrls = array_values(array_filter(array_map(
    fn($f) => $f['poster'] ? ig_hero_url($f['poster']) : null,
    forecast_flat_week_films($byDay)
)));

if (!in_array($posterUrl, $validUrls, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown poster.']);
    exit;
}

ig_poster_crop_write($posterUrl, $bias);

echo json_encode(['ok' => true]);
