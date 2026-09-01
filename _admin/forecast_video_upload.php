<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — upload a finished video directly onto an existing episode, from
//  the workshop page
//
//  A scoped sibling of _admin/forecast_save.php's own video_file handling —
//  this one skips week_of/guest_name entirely (the episode already has
//  them) and responds with JSON instead of redirecting, since it's driven
//  by an XMLHttpRequest from the workshop page so upload progress can be
//  tracked (see initForecastVideoUpload() in js/v7.js — plain fetch()
//  can't report upload progress, XHR can). Same audio-extraction behavior
//  as forecast_save.php's own upload path — see
//  forecast_extract_audio_from_video()'s docblock in list/forecast.php.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/forecast.php';

header('Content-Type: application/json');

function forecast_video_upload_fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$episode_id = (int) ($_POST['episode_id'] ?? 0);
$episode = forecast_get_episode($conn, $episode_id);
if (!$episode || (int) $episode['uid'] !== (int) $admin_user['id']) {
    forecast_video_upload_fail('That episode was not found.', 404);
}

try {
    $video = forecast_handle_upload('video_file', $episode_id, FORECAST_VIDEO_TYPES, $episode['video_file']);
} catch (RuntimeException $ex) {
    forecast_video_upload_fail($ex->getMessage());
}
if ($video === null) {
    forecast_video_upload_fail('No video was uploaded.');
}

$dir = dirname(__DIR__) . '/uploads/forecast';

$audio = null;
$extracted = forecast_extract_audio_from_video($dir, $video, $episode_id);
if ($extracted !== null) {
    if ($episode['audio_file'] && file_exists($dir . '/' . $episode['audio_file'])) unlink($dir . '/' . $episode['audio_file']);
    $audio = $extracted;
}

$duration = forecast_probe_duration($dir . '/' . $video);

$sets = ['video_file = :video_file', 'edited = :edited'];
$params = [':video_file' => $video, ':edited' => time(), ':id' => $episode_id, ':uid' => $admin_user['id']];
if ($audio !== null) { $sets[] = 'audio_file = :audio_file'; $params[':audio_file'] = $audio; }
if ($duration !== null) { $sets[] = 'duration_seconds = :duration'; $params[':duration'] = $duration; }

$conn->prepare('UPDATE `forecast_episodes` SET ' . implode(', ', $sets) . ' WHERE id = :id AND uid = :uid')
     ->execute($params);

echo json_encode(['ok' => true, 'video_file' => $video, 'audio_file' => $audio, 'duration_seconds' => $duration]);
