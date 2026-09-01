<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Film Forecast — animated intro clip (background)
//
//  Launched detached by _admin/forecast_intro_animation.php. Thin wrapper
//  around forecast_generate_intro_animation() — resolve the film
//  selection the same way the package/live intro already do, render,
//  record the clip on the episode row the same way generated_video/
//  package_file/waveform_file already are.
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
    fwrite(STDERR, "Usage: forecast-intro-animation.php --episode=<id>\n");
    exit(1);
}

$episode = forecast_get_episode($conn, $episode_id);
if (!$episode) {
    forecast_write_progress($episode_id, 'error', null, 'Episode not found.', 'intro');
    exit(1);
}

forecast_write_progress($episode_id, 'running', 0, null, 'intro');

$byDay = forecast_all_week_films($conn, $episode['week_of']);
$films = forecast_resolve_selection($episode, $byDay);

$dir = dirname(__DIR__) . '/uploads/forecast';
$stamp = time();
$clipName = $episode_id . '-intro-' . $stamp . '.mp4';
$clipPath = $dir . '/' . $clipName;

$result = forecast_generate_intro_animation($episode, $conn, $films, $clipPath, function ($percent) use ($episode_id) {
    forecast_write_progress($episode_id, 'running', $percent, null, 'intro');
});

if (!$result['ok']) {
    forecast_write_progress($episode_id, 'error', null, mb_strimwidth($result['error'], 0, 500, '…'), 'intro');
    exit(1);
}

$old = $episode['intro_animation_file'] ?? null;
$conn->prepare("UPDATE `forecast_episodes` SET intro_animation_file = :v WHERE id = :id")
     ->execute([':v' => $clipName, ':id' => $episode_id]);
if ($old && $old !== $clipName && file_exists($dir . '/' . $old)) unlink($dir . '/' . $old);

forecast_write_progress($episode_id, 'done', 100, null, 'intro');
