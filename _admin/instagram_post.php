<?php
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/instagram.php';

// The admin page hides this button during prep mode (past the 10pm cutover,
// while the page is showing tomorrow's draft — see ig_admin_target_date()),
// but that's only a UI nicety; a direct POST here still needs its own guard
// so tomorrow's not-yet-current draft can never go out early.
if (ig_admin_target_date() !== strtotime('today')) {
    header('Location: /_admin/instagram.php?error=' . urlencode('still preparing tomorrow\'s post — publishing is disabled until then'));
    exit;
}

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
