<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — save which films the checklist has checked for one episode
//
//  Saves the exact posted set verbatim, including empty (unchecking
//  everything is a deliberate, valid choice — it falls back to nothing on
//  the cover, not back to the automatic default). Only keys that are
//  actually playing this week are kept, same "the day's real data is the
//  source of truth for what a valid key looks like" rule every other
//  checklist save handler in this project already follows.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/forecast.php';

$episode_id = (int) ($_POST['episode_id'] ?? 0);
$episode = forecast_get_episode($conn, $episode_id);
if (!$episode || (int) $episode['uid'] !== (int) $admin_user['id']) {
    header('Location: /_admin/forecast.php?error=' . urlencode('That episode was not found.'));
    exit;
}

$byDay = forecast_all_week_films($conn, $episode['week_of']);
$posted = is_array($_POST['films'] ?? null) ? $_POST['films'] : [];
forecast_save_selection($conn, $episode_id, $admin_user['id'], $byDay, $posted);

header('Location: /_admin/forecast_episode.php?id=' . $episode_id);
exit;
