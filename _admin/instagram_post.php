<?php
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/instagram.php';

$now   = time();
$flag  = dirname(__DIR__) . '/uploads/social/.posted-' . date('Y-m-d', $now);

if (file_exists($flag)) {
    header('Location: /_admin/instagram.php?error=' . urlencode('already posted today'));
    exit;
}

$films = ig_today_films($conn);
$image = ig_build_image($films, $now);
[$path, $image_url] = ig_save_image($image, $now);
$caption = ig_build_caption($films, $now);

try {
    $media_id = ig_publish($image_url, $caption);
    file_put_contents($flag, $media_id);
    header('Location: /_admin/instagram.php?posted=' . urlencode($media_id));
} catch (Throwable $ex) {
    header('Location: /_admin/instagram.php?error=' . urlencode($ex->getMessage()));
}
exit;
