<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Film Forecast — manual-fallback panel package (background)
//
//  Launched detached by _admin/forecast_package.php. Renders the
//  redesigned intro card plus one card per unique film actually playing
//  this week (every film, independent of the Chapters checklist — see
//  forecast_flat_week_films()), no showtimes line (see
//  forecast_build_chapter_card()'s $showShowtimes), zips them with
//  zero-padded/slugged names so they sort into air order in any file
//  browser or Premiere import, and records the zip on the episode row the
//  same way bin/forecast-generate.php records generated_video.
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

$total = count($films);
foreach ($films as $i => $film) {
    $slug = ctx_slug($film['display_title'] ?? $film['title']);
    $path = $tmpDir . '/' . sprintf('%02d', $i + 1) . '-' . $slug . '.png';
    $cardImg = forecast_build_chapter_card($film, $episode, false);
    imagepng($cardImg, $path);
    imagedestroy($cardImg);
    $entries[] = $path;
    if ($total > 0) forecast_write_progress($episode_id, 'running', min(90, (int) round(($i + 1) / $total * 90)), null, 'package');
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
