<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — save chapter marker positions for one episode
//
//  Mirrors forecast_selection_save.php's shape exactly, one level down:
//  where that handler validates posted film keys against "what's actually
//  playing this week," this one validates posted chapters against "what's
//  actually in the current selection" — a chapter can only ever exist for
//  a film that's checked, never a stray one left over from before the
//  selection changed. See forecast_save_chapters() in list/forecast.php.
//
//  $_POST['chapters'] is a JSON string (built client-side from the
//  timeline's marker positions), not a nested form array — the timeline
//  is a JS drag interaction, not a plain HTML form, so there's no benefit
//  to the usual chapters[0][film]=...&chapters[0][start]=... encoding.
//
//  Called by fetch() from the timeline's "Save chapters" button
//  (js/v7.js), not a page navigation, so this answers with JSON rather
//  than a redirect — same shape instagram_poster_crop.php already uses
//  for its own fetch()-driven save.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/forecast.php';

header('Content-Type: application/json');

$episode_id = (int) ($_POST['episode_id'] ?? 0);
$episode = forecast_get_episode($conn, $episode_id);
if (!$episode || (int) $episode['uid'] !== (int) $admin_user['id']) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'That episode was not found.']);
    exit;
}

$byDay = forecast_all_week_films($conn, $episode['week_of']);
$films = forecast_resolve_selection($episode, $byDay);
$selectedKeys = array_map('ig_film_key', $films);

$posted = json_decode($_POST['chapters'] ?? '[]', true);
if (!is_array($posted)) $posted = [];

$saved = forecast_save_chapters($conn, $episode_id, $admin_user['id'], $selectedKeys, $posted, (float) ($episode['duration_seconds'] ?? 0));

echo json_encode(['ok' => true, 'chapters' => $saved]);
