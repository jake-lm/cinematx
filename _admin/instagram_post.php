<?php
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/instagram.php';

$now   = time();
$flag  = dirname(__DIR__) . '/uploads/social/.posted-' . date('Y-m-d', $now);

// DEV OVERRIDE (2026-07-30): double-post guard disabled for live testing.
// Re-enable before this goes near a real posting schedule.
// if (file_exists($flag)) {
//     header('Location: /_admin/instagram.php?error=' . urlencode('already posted today'));
//     exit;
// }

$films   = ig_today_films($conn);
$compose = ig_compose_read($now);
$images  = ig_build_images($films, $now, $compose);
$pages   = ig_save_images($images, $now);
$caption = ig_caption($films, $now);

try {
    $media_id = ig_publish($pages, $caption);
    file_put_contents($flag, $media_id);
    header('Location: /_admin/instagram.php?posted=' . urlencode($media_id));
} catch (Throwable $ex) {
    header('Location: /_admin/instagram.php?error=' . urlencode($ex->getMessage()));
}
exit;
