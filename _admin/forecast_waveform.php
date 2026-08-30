<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — start rendering one episode's transparent waveform clip, in the
//  background
//
//  The other half of the Premiere fallback (see forecast_package.php): a
//  ProRes 4444 alpha clip carrying only the two gold waveform bands, meant
//  to sit on a track above a hand-placed sequence of the package's panel
//  stills. A separate action from the package itself — the user wants to
//  be able to regenerate one without the other. ProRes encoding of a
//  multi-minute clip is real encode time, so this launches
//  bin/forecast-waveform.php detached exactly like video generation does.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/forecast.php';

function forecast_waveform_fail($episode_id, $msg) {
    header('Location: /_admin/forecast_episode.php?id=' . $episode_id . '&error=' . urlencode($msg));
    exit;
}

$episode_id = (int) ($_POST['episode_id'] ?? 0);
$episode = forecast_get_episode($conn, $episode_id);
if (!$episode || (int) $episode['uid'] !== (int) $admin_user['id']) {
    forecast_waveform_fail($episode_id, 'That episode was not found.');
}
if (empty($episode['audio_file'])) {
    forecast_waveform_fail($episode_id, 'Upload an audio file before generating a waveform clip.');
}

$status = forecast_generation_status($episode_id, 'waveform');
if ($status && ($status['status'] ?? '') === 'running') {
    forecast_waveform_fail($episode_id, 'A waveform clip is already being generated for this episode.');
}

$dir = dirname(__DIR__) . '/uploads/forecast';
$logPath = $dir . '/' . $episode_id . '-waveform.log';
$scriptPath = dirname(__DIR__) . '/bin/forecast-waveform.php';

forecast_write_progress($episode_id, 'running', 0, null, 'waveform');

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
