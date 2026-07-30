<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — save or reset today's Instagram caption override
//
//  Every reader of the caption (admin preview, dashboard peek, the cron job)
//  goes through ig_caption() in list/instagram.php, so whatever gets saved
//  here is what actually posts — an edit made in the morning is what the
//  cron job uses that evening, not a separate draft that never gets read.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/instagram.php';

$now   = time();
$file  = dirname(__DIR__) . '/uploads/social/caption-' . date('Y-m-d', $now) . '.txt';
$action = $_POST['action'] ?? '';

if ($action === 'reset') {
    if (file_exists($file)) unlink($file);
} else {
    $caption = trim((string) ($_POST['caption'] ?? ''));
    if ($caption !== '' && mb_strlen($caption) <= IG_CAPTION_MAX) {
        $dir = dirname($file);
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        file_put_contents($file, $caption);
    }
}

header('Location: /_admin/instagram.php');
exit;
