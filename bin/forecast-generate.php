<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Film Forecast — background video generation
//
//  Launched detached by _admin/forecast_generate.php
//  (`nohup php ... --episode=N > log 2>&1 &`) so the admin's request
//  returns immediately instead of blocking — a real episode has taken
//  upward of 15-20 minutes end to end, almost all of it the geq-driven
//  progress bar (one of ffmpeg's slowest filter types). Owns the whole
//  lifecycle: resolves which films are in the episode and when each one's
//  chapter starts (the saved selection/chapters, or their automatic
//  defaults — see forecast_resolve_selection()/forecast_resolve_chapters()
//  in list/forecast.php), renders the intro/chapter/wrap-up segment
//  images, runs ffmpeg via forecast_generate_video()'s proc_open()
//  progress callback, writes uploads/forecast/<id>-progress.json as it
//  goes, and updates the episode row once finished.
//  _admin/forecast_progress.php only ever reads that JSON file — it never
//  touches ffmpeg itself.
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
    fwrite(STDERR, "Usage: forecast-generate.php --episode=<id>\n");
    exit(1);
}

$episode = forecast_get_episode($conn, $episode_id);
if (!$episode) {
    forecast_write_progress($episode_id, 'error', null, 'Episode not found.');
    exit(1);
}
if (empty($episode['audio_file'])) {
    forecast_write_progress($episode_id, 'error', null, 'No audio file to generate from.');
    exit(1);
}

$dir = $root . '/uploads/forecast';
$audioPath = $dir . '/' . $episode['audio_file'];
$duration = forecast_probe_duration($audioPath);
if (!$duration) {
    forecast_write_progress($episode_id, 'error', null, 'Could not determine the audio file\'s duration.');
    exit(1);
}

forecast_write_progress($episode_id, 'running', 0);

$byDay = forecast_all_week_films($conn, $episode['week_of']);
$films = forecast_resolve_selection($episode, $byDay);
$chapters = forecast_resolve_chapters($films, $episode['chapters'] ?? null, $duration);

$stamp = time();
$segmentPaths = [];
$segments = [];

$introPath = $dir . '/' . $episode_id . '-seg-intro-' . $stamp . '.png';
$introImg = forecast_build_intro_card($episode + ['duration_seconds' => $duration]);
imagepng($introImg, $introPath);
imagedestroy($introImg);
$segmentPaths[] = $introPath;
$segments[] = ['image' => $introPath, 'start' => 0];

foreach ($chapters as $i => $c) {
    $path = $dir . '/' . $episode_id . '-seg-' . $i . '-' . $stamp . '.png';
    $cardImg = forecast_build_chapter_card($c['data']);
    imagepng($cardImg, $path);
    imagedestroy($cardImg);
    $segmentPaths[] = $path;
    $segments[] = ['image' => $path, 'start' => $c['start']];
}

// A dedicated closing beat, not just the last chapter running to the
// end — only if there's actually room for one; on a very short test clip
// this quietly falls back to letting the last chapter (or the intro, if
// there are no chapters at all) run to the end instead.
$lastStart = $chapters ? end($chapters)['start'] : 0;
if ($duration - $lastStart > FORECAST_WRAPUP_SECONDS * 2) {
    $wrapupPath = $dir . '/' . $episode_id . '-seg-wrapup-' . $stamp . '.png';
    $wrapupImg = forecast_build_intro_card($episode + ['duration_seconds' => $duration]);
    imagepng($wrapupImg, $wrapupPath);
    imagedestroy($wrapupImg);
    $segmentPaths[] = $wrapupPath;
    $segments[] = ['image' => $wrapupPath, 'start' => $duration - FORECAST_WRAPUP_SECONDS];
}

$outputName = $episode_id . '-generated-' . $stamp . '.mp4';
$outputPath = $dir . '/' . $outputName;

$result = forecast_generate_video($audioPath, $segments, $outputPath, $duration, function ($percent) use ($episode_id) {
    forecast_write_progress($episode_id, 'running', $percent);
});

foreach ($segmentPaths as $p) @unlink($p);

if (!$result['ok']) {
    forecast_write_progress($episode_id, 'error', null, mb_strimwidth($result['error'], 0, 500, '…'));
    exit(1);
}

$old = $episode['generated_video'] ?? null;
$conn->prepare("UPDATE `forecast_episodes` SET generated_video = :v, duration_seconds = :d, edited = :e WHERE id = :id")
     ->execute([':v' => $outputName, ':d' => $duration, ':e' => time(), ':id' => $episode_id]);
if ($old && $old !== $outputName && file_exists($dir . '/' . $old)) unlink($dir . '/' . $old);

forecast_write_progress($episode_id, 'done', 100);
