<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — start rendering one episode's animated intro clip, in the
//  background
//
//  The third piece of the Premiere fallback, alongside forecast_package.php
//  and forecast_waveform.php: a short silent video of the poster wall
//  fading in tile by tile — motion the static package PNG can't carry at
//  all. A separate action from the other two — no reason regenerating one
//  should force regenerating the others. Doesn't need audio_file (the clip
//  is a fixed length, not synced to the episode), just the same film
//  selection the package and intro card already use.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/forecast.php';

function forecast_intro_animation_fail($episode_id, $msg) {
    header('Location: /_admin/forecast_episode.php?id=' . $episode_id . '&error=' . urlencode($msg));
    exit;
}

$episode_id = (int) ($_POST['episode_id'] ?? 0);
$episode = forecast_get_episode($conn, $episode_id);
if (!$episode || (int) $episode['uid'] !== (int) $admin_user['id']) {
    forecast_intro_animation_fail($episode_id, 'That episode was not found.');
}

$status = forecast_generation_status($episode_id, 'intro');
if ($status && ($status['status'] ?? '') === 'running') {
    forecast_intro_animation_fail($episode_id, 'An intro animation is already being generated for this episode.');
}

$dir = dirname(__DIR__) . '/uploads/forecast';
$logPath = $dir . '/' . $episode_id . '-intro.log';
$scriptPath = dirname(__DIR__) . '/bin/forecast-intro-animation.php';

forecast_write_progress($episode_id, 'running', 0, null, 'intro');

$phpBinary = PHP_BINDIR . '/php';
$cmd = sprintf(
    'nohup %s %s --episode=%d > %s 2>&1 &',
    escapeshellarg($phpBinary),
    escapeshellarg($scriptPath),
    $episode_id,
    escapeshellarg($logPath)
);
exec($cmd);

header('Location: /_admin/forecast_episode.php?id=' . $episode_id);
exit;
