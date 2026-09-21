<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — download one day's TikTok carousel slides as a single zip
//
//  Streams the tiktok-<date>-N.png files _admin/tiktok.php already wrote,
//  numbered in posting order so they can be selected and uploaded to TikTok
//  as-is. Read-only GET, so no CSRF token — _guard.php's admin check is the
//  whole gate, same as any other download link in this directory.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';

$date = $_GET['date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    exit('Bad date.');
}

$dir   = dirname(__DIR__) . '/uploads/social';
$files = glob($dir . '/tiktok-' . $date . '-*.png');
if (!$files) {
    http_response_code(404);
    exit('No slides for that date — open the TikTok page first so they are generated.');
}
natsort($files);

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('The PHP zip extension is not available on this server.');
}

$tmp = tempnam(sys_get_temp_dir(), 'ttzip');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Could not create the zip.');
}
$n = 1;
foreach ($files as $f) {
    $zip->addFile($f, sprintf('cinematx-%s-%02d.png', $date, $n++));
}
$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="cinematx-tiktok-' . $date . '.zip"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
unlink($tmp);
