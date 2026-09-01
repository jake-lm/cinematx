<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Film Forecast — manual-fallback panel package (background)
//
//  Launched detached by _admin/forecast_package.php. Renders the
//  redesigned intro card, then one day card plus that day's own film
//  cards (no showtimes line — see forecast_build_chapter_card()'s
//  $showShowtimes) for each of the week's 7 days in order — every film,
//  independent of the Chapters checklist. A film playing more than once
//  this week still only gets one panel, filed under its first day
//  ($byDay's own dedup), even though a later day's own day card
//  correctly lists it too. Zips everything with zero-padded/slugged
//  names so it sorts into the real main/sub-chapter order in any file
//  browser or Premiere import, and records the zip on the episode row
//  the same way bin/forecast-generate.php records generated_video.
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
    fwrite(STDERR, "Usage: forecast-package.php --episode=<id>\n");
    exit(1);
}

$episode = forecast_get_episode($conn, $episode_id);
if (!$episode) {
    forecast_write_progress($episode_id, 'error', null, 'Episode not found.', 'package');
    exit(1);
}

if (!class_exists('ZipArchive')) {
    forecast_write_progress($episode_id, 'error', null, 'The zip extension is not available on this server.', 'package');
    exit(1);
}

forecast_write_progress($episode_id, 'running', 0, null, 'package');

$dir = dirname(__DIR__) . '/uploads/forecast';
$byDay = forecast_all_week_films($conn, $episode['week_of']);
$films = forecast_flat_week_films($byDay);

$stamp = time();
$tmpDir = $dir . '/package-tmp-' . $episode_id . '-' . $stamp;
mkdir($tmpDir, 0755, true);

$entries = [];

$introImg = forecast_build_cover($episode, $conn, $films);
$introPath = $tmpDir . '/00-intro.png';
imagepng($introImg, $introPath);
imagedestroy($introImg);
$entries[] = $introPath;

// Interleaved day/film order — a day panel (the accurate, day-scoped
// list from forecast_films_for_day(), which unlike $byDay itself
// correctly includes a film on every day it actually plays) followed by
// whichever films are filed under that day in $byDay (its own
// first-occurrence dedup — see the file header). Numbered sequentially
// across the whole sequence, not per-film, so the zip's own sort order
// mirrors the real main/sub-chapter hierarchy.
$weekDays = forecast_week_days($episode['week_of']);
$total = 7 + count($films);
$done  = 0;
$n     = 1;
foreach ($weekDays as $ymd) {
    $dayImg = forecast_build_day_card($ymd, forecast_films_for_day($byDay, $ymd), $episode);
    $dayPath = $tmpDir . '/' . sprintf('%02d', $n++) . '-' . strtolower(date('l', strtotime($ymd))) . '.png';
    imagepng($dayImg, $dayPath);
    imagedestroy($dayImg);
    $entries[] = $dayPath;
    $done++;
    forecast_write_progress($episode_id, 'running', min(90, (int) round($done / $total * 90)), null, 'package');

    foreach ($byDay[$ymd] ?? [] as $film) {
        $slug = ctx_slug($film['display_title'] ?? $film['title']);
        $path = $tmpDir . '/' . sprintf('%02d', $n++) . '-' . $slug . '.png';
        $cardImg = forecast_build_chapter_card($film, $episode, false);
        imagepng($cardImg, $path);
        imagedestroy($cardImg);
        $entries[] = $path;
        $done++;
        forecast_write_progress($episode_id, 'running', min(90, (int) round($done / $total * 90)), null, 'package');
    }
}

$zipName = $episode_id . '-package-' . $stamp . '.zip';
$zipPath = $dir . '/' . $zipName;

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    forecast_write_progress($episode_id, 'error', null, 'Could not create the zip file.', 'package');
    exit(1);
}
foreach ($entries as $path) {
    $zip->addFile($path, basename($path));
}
$zip->close();

foreach ($entries as $path) @unlink($path);
@rmdir($tmpDir);

$old = $episode['package_file'] ?? null;
$conn->prepare("UPDATE `forecast_episodes` SET package_file = :v WHERE id = :id")
     ->execute([':v' => $zipName, ':id' => $episode_id]);
if ($old && $old !== $zipName && file_exists($dir . '/' . $old)) unlink($dir . '/' . $old);

forecast_write_progress($episode_id, 'done', 100, null, 'package');
