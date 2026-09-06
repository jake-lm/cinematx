<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — start transcribing one episode's audio, in the background
//
//  Whisper's round trip for a ~10 minute file is real network + processing
//  time, so this launches bin/forecast-transcribe.php detached exactly like
//  every other Forecast export. Its own progress-file kind ('transcribe')
//  so it can't block or clobber the video/package/waveform/intro jobs, or
//  vice versa.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/forecast.php';

function forecast_transcribe_fail($episode_id, $msg) {
    header('Location: /_admin/forecast_episode.php?id=' . $episode_id . '&error=' . urlencode($msg));
    exit;
}

$episode_id = (int) ($_POST['episode_id'] ?? 0);
$episode = forecast_get_episode($conn, $episode_id);
if (!$episode || (int) $episode['uid'] !== (int) $admin_user['id']) {
    forecast_transcribe_fail($episode_id, 'That episode was not found.');
}
if (empty($episode['audio_file'])) {
    forecast_transcribe_fail($episode_id, 'Upload an audio file before transcribing.');
}
if (!defined('OPENAI_API_KEY') || !OPENAI_API_KEY) {
    forecast_transcribe_fail($episode_id, 'OPENAI_API_KEY is not configured.');
}

$status = forecast_generation_status($episode_id, 'transcribe');
if ($status && ($status['status'] ?? '') === 'running') {
    forecast_transcribe_fail($episode_id, 'This episode is already being transcribed.');
}

$dir = dirname(__DIR__) . '/uploads/forecast';
$logPath = $dir . '/' . $episode_id . '-transcribe.log';
$scriptPath = dirname(__DIR__) . '/bin/forecast-transcribe.php';

forecast_write_progress($episode_id, 'running', 0, null, 'transcribe');

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
