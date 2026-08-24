<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — assemble one episode's Reel video from its cover + audio
//
//  Skipped entirely when a video was uploaded directly — that file posts
//  as-is (see forecast_post.php). Synchronous: the admin's request waits
//  for ffmpeg to finish. Acceptable for an occasional, admin-only action —
//  there's no background job queue in this codebase to build on, and
//  max_execution_time is already unlimited on this box.
//
//  Re-running this overwrites generated_video every time; it never touches
//  a directly-uploaded video_file.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/forecast.php';

function forecast_generate_fail($msg) {
    header('Location: /_admin/forecast.php?error=' . urlencode($msg));
    exit;
}

$episode_id = (int) ($_POST['episode_id'] ?? 0);
$episode = forecast_get_episode($conn, $episode_id);
if (!$episode || (int) $episode['uid'] !== (int) $admin_user['id']) {
    forecast_generate_fail('That episode was not found.');
}
if (empty($episode['audio_file'])) {
    forecast_generate_fail('Upload an audio file before generating a video.');
}

$dir = dirname(__DIR__) . '/uploads/forecast';
$audioPath = $dir . '/' . $episode['audio_file'];

$duration = forecast_probe_duration($audioPath);
if (!$duration) {
    forecast_generate_fail('Could not read the audio file\'s duration.');
}

$coverPath = $dir . '/' . $episode_id . '-cover-' . time() . '.png';
$cover = forecast_build_cover($episode + ['duration_seconds' => $duration], $conn);
imagepng($cover, $coverPath);
imagedestroy($cover);

$outputName = $episode_id . '-generated-' . time() . '.mp4';
$outputPath = $dir . '/' . $outputName;

$result = forecast_generate_video($audioPath, $coverPath, $outputPath, $duration);

// The cover is only an intermediate for ffmpeg to composite over — nothing
// else ever reads it once the video exists.
unlink($coverPath);

if (!$result['ok']) {
    forecast_generate_fail('Video generation failed: ' . mb_strimwidth($result['error'], 0, 300, '…'));
}

$old = $episode['generated_video'] ?? null;
$conn->prepare("UPDATE `forecast_episodes` SET generated_video = :video, duration_seconds = :duration, edited = :edited WHERE id = :id AND uid = :uid")
     ->execute([
         ':video' => $outputName, ':duration' => $duration, ':edited' => time(),
         ':id' => $episode_id, ':uid' => $admin_user['id'],
     ]);
if ($old && $old !== $outputName && file_exists($dir . '/' . $old)) unlink($dir . '/' . $old);

header('Location: /_admin/forecast.php');
exit;
