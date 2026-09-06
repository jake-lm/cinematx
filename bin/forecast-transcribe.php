<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Film Forecast — audio transcription (background)
//
//  Launched detached by _admin/forecast_transcribe.php. Thin wrapper around
//  forecast_transcribe_audio() — resolve the audio file, send it to Whisper,
//  store the timestamped segments on the episode row so
//  forecast_match_transcript_films() (run on demand by "Suggest chapters")
//  has something to search without re-transcribing every time.
// ═══════════════════════════════════════════════════════════════════════════
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/config.php';
require $root . '/database.php';
require $root . '/list/forecast.php';

$episode_id = 0;
foreach ($argv as $arg) {
    if (preg_match('/^--episode=(\d+)$/', $arg, $m)) $episode_id = (int) $m[1];
}
if (!$episode_id) {
    fwrite(STDERR, "Usage: forecast-transcribe.php --episode=<id>\n");
    exit(1);
}

$episode = forecast_get_episode($conn, $episode_id);
if (!$episode) {
    forecast_write_progress($episode_id, 'error', null, 'Episode not found.', 'transcribe');
    exit(1);
}
if (empty($episode['audio_file'])) {
    forecast_write_progress($episode_id, 'error', null, 'No audio file to transcribe.', 'transcribe');
    exit(1);
}

forecast_write_progress($episode_id, 'running', 0, null, 'transcribe');

$dir = dirname(__DIR__) . '/uploads/forecast';
$audioPath = $dir . '/' . $episode['audio_file'];

$result = forecast_transcribe_audio($audioPath, function ($percent) use ($episode_id) {
    forecast_write_progress($episode_id, 'running', $percent, null, 'transcribe');
});

if (!$result['ok']) {
    forecast_write_progress($episode_id, 'error', null, mb_strimwidth($result['error'], 0, 500, '…'), 'transcribe');
    exit(1);
}

$conn->prepare("UPDATE `forecast_episodes` SET transcript = :v WHERE id = :id")
     ->execute([':v' => json_encode($result['segments']), ':id' => $episode_id]);

forecast_write_progress($episode_id, 'done', 100, null, 'transcribe');
