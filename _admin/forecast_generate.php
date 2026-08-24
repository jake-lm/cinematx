<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — start generating one episode's Reel video, in the background
//
//  Skipped entirely when a video was uploaded directly — that file posts
//  as-is (see forecast_post.php). A real episode takes upward of 15-20
//  minutes end to end (ffmpeg, mostly the geq-driven progress bar), far
//  too long for the admin's own request to block on, so this only ever
//  launches bin/forecast-generate.php detached and returns immediately —
//  that script owns the actual ffmpeg run, writing
//  uploads/forecast/<id>-progress.json as it goes.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/forecast.php';

function forecast_generate_fail($episode_id, $msg) {
    header('Location: /_admin/forecast_episode.php?id=' . $episode_id . '&error=' . urlencode($msg));
    exit;
}

$episode_id = (int) ($_POST['episode_id'] ?? 0);
$episode = forecast_get_episode($conn, $episode_id);
if (!$episode || (int) $episode['uid'] !== (int) $admin_user['id']) {
    forecast_generate_fail($episode_id, 'That episode was not found.');
}
if (empty($episode['audio_file'])) {
    forecast_generate_fail($episode_id, 'Upload an audio file before generating a video.');
}

// The progress file's own status is the lock — refuse a second concurrent
// run for the same episode rather than spawning a duplicate ffmpeg.
$status = forecast_generation_status($episode_id);
if ($status && ($status['status'] ?? '') === 'running') {
    forecast_generate_fail($episode_id, 'A generation is already running for this episode.');
}

$dir = dirname(__DIR__) . '/uploads/forecast';
$logPath = $dir . '/' . $episode_id . '-generate.log';
$scriptPath = dirname(__DIR__) . '/bin/forecast-generate.php';

// Written here, not left to bin/forecast-generate.php's own first update,
// so the very next request — even one landing before the background
// process has actually started up — already sees "running" and can't slip
// past the lock check above into a duplicate launch.
forecast_write_progress($episode_id, 'running', 0);

// Not PHP_BINARY — under mod_php (this server's SAPI) it's empty, since
// there's no standalone PHP executable, only the apache2 binary with PHP
// loaded in. PHP_BINDIR is the install directory, stable across every
// SAPI, and every PHP install puts a `php` symlink there.
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
