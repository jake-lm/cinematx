<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — start rendering one episode's manual-fallback panel package, in
//  the background
//
//  The Premiere fallback: every panel this week (the redesigned intro plus
//  one card per film actually playing, regardless of which films are
//  checked in Chapters — the package is meant to cover the whole week, not
//  just what's timed into the real video) rendered to PNG and zipped for
//  download. GD rendering itself is fast, but ig_fetch_thumb()'s cold-cache
//  poster fetches are network-bound and a full week can be 15-30 films, so
//  this launches bin/forecast-package.php detached exactly like video
//  generation does rather than risking a slow week blocking the request.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/forecast.php';

function forecast_package_fail($episode_id, $msg) {
    header('Location: /_admin/forecast_episode.php?id=' . $episode_id . '&error=' . urlencode($msg));
    exit;
}

$episode_id = (int) ($_POST['episode_id'] ?? 0);
$episode = forecast_get_episode($conn, $episode_id);
if (!$episode || (int) $episode['uid'] !== (int) $admin_user['id']) {
    forecast_package_fail($episode_id, 'That episode was not found.');
}

$status = forecast_generation_status($episode_id, 'package');
if ($status && ($status['status'] ?? '') === 'running') {
    forecast_package_fail($episode_id, 'A package is already being generated for this episode.');
}

$dir = dirname(__DIR__) . '/uploads/forecast';
$logPath = $dir . '/' . $episode_id . '-package.log';
$scriptPath = dirname(__DIR__) . '/bin/forecast-package.php';

forecast_write_progress($episode_id, 'running', 0, null, 'package');

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
