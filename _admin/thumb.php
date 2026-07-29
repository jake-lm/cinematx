<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Poster thumbnails for the admin panel
//
//  Posters are uploaded at whatever size the programmer had — the local set
//  runs to 2.7MB apiece — and the dashboard draws them at 90px. Sending the
//  originals meant ~11MB to render four thumbnails.
//
//  Cached on first request and served from disk after that. basename() before
//  anything touches the filesystem, for the same reason motw/stream.php and
//  _admin/delete.php do it: these names come from a database column and
//  nothing else guarantees they stay inside /motw.
//
//    /_admin/thumb.php?f=<poster>&w=240
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';

$name = basename((string)($_GET['f'] ?? ''));
$w    = max(48, min(480, (int)($_GET['w'] ?? 240)));

if ($name === '' || $name === '.' || $name === '..') { http_response_code(400); exit; }

$src = dirname(__DIR__) . '/motw/' . $name . '.png';
if (!is_file($src)) { http_response_code(404); exit; }

$dir = dirname(__DIR__) . '/uploads/thumbs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
$cache = $dir . '/' . $w . '-' . md5($name) . '.jpg';

// Rebuild if the poster has been replaced since the thumbnail was cut.
if (!is_file($cache) || filemtime($cache) < filemtime($src)) {
    // Decoded by content, not by extension. upload.php names every poster
    // .png regardless of what was actually uploaded, so a good part of the
    // library is JPEG data behind a .png name and imagecreatefrompng() fails
    // on all of it.
    $raw = @file_get_contents($src);
    $img = $raw ? @imagecreatefromstring($raw) : false;
    if (!$img) { http_response_code(415); exit; }

    $sw = imagesx($img);
    $sh = imagesy($img);
    $h  = max(1, (int) round($sh * ($w / $sw)));

    $out = imagecreatetruecolor($w, $h);
    imagecopyresampled($out, $img, 0, 0, 0, 0, $w, $h, $sw, $sh);
    imagejpeg($out, $cache, 82);
    imagedestroy($img);
    imagedestroy($out);
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($cache));
header('Cache-Control: private, max-age=86400');
readfile($cache);
