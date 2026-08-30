<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Film Forecast — transparent waveform clip (background)
//
//  Launched detached by _admin/forecast_waveform.php. Thin wrapper around
//  forecast_generate_waveform_clip() — resolve the audio/duration, render,
//  record the clip on the episode row the same way generated_video/
//  package_file already are.
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
    fwrite(STDERR, "Usage: forecast-waveform.php --episode=<id>\n");
    exit(1);
}

$episode = forecast_get_episode($conn, $episode_id);
if (!$episode) {
    forecast_write_progress($episode_id, 'error', null, 'Episode not found.', 'waveform');
    exit(1);
}
if (empty($episode['audio_file'])) {
    forecast_write_progress($episode_id, 'error', null, 'No audio file to generate from.', 'waveform');
    exit(1);
}

$dir = dirname(__DIR__) . '/uploads/forecast';
$audioPath = $dir . '/' . $episode['audio_file'];
$duration = forecast_probe_duration($audioPath);
if (!$duration) {
    forecast_write_progress($episode_id, 'error', null, 'Could not determine the audio file\'s duration.', 'waveform');
    exit(1);
}

forecast_write_progress($episode_id, 'running', 0, null, 'waveform');

$stamp = time();
$clipName = $episode_id . '-waveform-' . $stamp . '.mov';
$clipPath = $dir . '/' . $clipName;

$result = forecast_generate_waveform_clip($audioPath, $clipPath, $duration, function ($percent) use ($episode_id) {
    forecast_write_progress($episode_id, 'running', $percent, null, 'waveform');
});

if (!$result['ok']) {
    forecast_write_progress($episode_id, 'error', null, mb_strimwidth($result['error'], 0, 500, '…'), 'waveform');
    exit(1);
}

$old = $episode['waveform_file'] ?? null;
$conn->prepare("UPDATE `forecast_episodes` SET waveform_file = :v WHERE id = :id")
     ->execute([':v' => $clipName, ':id' => $episode_id]);
if ($old && $old !== $clipName && file_exists($dir . '/' . $old)) unlink($dir . '/' . $old);

forecast_write_progress($episode_id, 'done', 100, null, 'waveform');
