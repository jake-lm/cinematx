<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — add a film to the Timeline bank that isn't actually screening
//  this week
//
//  Plain POST + redirect, not a fetch() endpoint — same shape as every
//  other mutating action on this page (generate, the exports, mark-
//  posted). A reload after adding is what regenerates this new film's
//  own storyboard thumbnail too (forecast_episode.php's segmentImages
//  loop covers every extra film exactly like every selected one), so
//  there's no reason to special-case this one action as fetch-based just
//  to avoid it.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/forecast.php';

function forecast_add_extra_film_fail($episode_id, $msg) {
    header('Location: /_admin/forecast_episode.php?id=' . $episode_id . '&error=' . urlencode($msg));
    exit;
}

$episode_id = (int) ($_POST['episode_id'] ?? 0);
$episode = forecast_get_episode($conn, $episode_id);
if (!$episode || (int) $episode['uid'] !== (int) $admin_user['id']) {
    forecast_add_extra_film_fail($episode_id, 'That episode was not found.');
}

$tmdbId = (int) ($_POST['tmdb_id'] ?? 0);
if (!$tmdbId) {
    forecast_add_extra_film_fail($episode_id, 'No film was selected.');
}

$film = forecast_add_extra_film($conn, $episode_id, $admin_user['id'], $episode, $tmdbId);
if (!$film) {
    forecast_add_extra_film_fail($episode_id, 'Could not find that film on TMDB.');
}

header('Location: /_admin/forecast_episode.php?id=' . $episode_id);
exit;
