<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — publish one Film Forecast episode as a Reel
//
//  Guarded by posted_media_id the same way instagram_post.php guards on
//  .posted-<date> — an already-published episode can't go out twice
//  through this button. Posts video_file (a direct upload) if present,
//  otherwise generated_video (the ffmpeg-assembled one); refuses if
//  neither exists yet.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/forecast.php';

function forecast_post_fail($msg) {
    header('Location: /_admin/forecast.php?error=' . urlencode($msg));
    exit;
}

$episode_id = (int) ($_POST['episode_id'] ?? 0);
$episode = forecast_get_episode($conn, $episode_id);
if (!$episode || (int) $episode['uid'] !== (int) $admin_user['id']) {
    forecast_post_fail('That episode was not found.');
}
if (!empty($episode['posted_media_id'])) {
    forecast_post_fail('This episode has already been posted.');
}

$filename = $episode['video_file'] ?: $episode['generated_video'];
if (!$filename) {
    forecast_post_fail('No video is ready to post yet — upload a video, or upload audio and generate one.');
}

$path = dirname(__DIR__) . '/uploads/forecast/' . $filename;
$caption = forecast_caption($episode);

try {
    $media_id = ig_publish_reel('/uploads/forecast/' . $filename, $path, $caption);
    $conn->prepare("UPDATE `forecast_episodes` SET posted_media_id = :mid, posted_at = :at WHERE id = :id AND uid = :uid")
         ->execute([':mid' => $media_id, ':at' => time(), ':id' => $episode_id, ':uid' => $admin_user['id']]);
    header('Location: /_admin/forecast.php?posted=' . urlencode($media_id));
} catch (Throwable $ex) {
    header('Location: /_admin/forecast.php?error=' . urlencode($ex->getMessage()));
}
exit;
