<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — create or update a Film Forecast episode
//
//  Mirrors _admin/event_save.php's shape (create/update in one handler,
//  scoped to the signed-in admin, files handled in the same request as the
//  rest of the fields). Guest photo, audio, and a directly-uploaded video
//  are all optional independently — an episode can be filled in over
//  several visits before anything is generated or posted.
//
//  duration_seconds is read via ffprobe right after an audio or video file
//  lands, never typed in by hand, so it can't drift from the real file —
//  see forecast_probe_duration() in list/forecast.php. A directly-uploaded
//  video also has its audio track pulled out to become audio_file (see
//  forecast_extract_audio_from_video()) — the video is treated as the
//  real final cut, so the podcast RSS feed's audio enclosure should match
//  it, not some earlier, possibly since-diverged audio upload.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/forecast.php';

function forecast_fail($msg) {
    header('Location: /_admin/forecast.php?error=' . urlencode($msg));
    exit;
}

const FORECAST_IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
const FORECAST_AUDIO_TYPES = ['audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav'];
const FORECAST_VIDEO_TYPES = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov'];

/**
 * Validates and moves an upload from $field into uploads/forecast/, named
 * "<episode id>-<field>.<ext>". Returns the new filename, null if no file
 * was submitted (not an error), or calls forecast_fail() on anything wrong.
 */
function forecast_handle_upload($field, $episode_id, array $allowedTypes, $existing) {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) forecast_fail(ucfirst($field) . ' upload failed.');

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $_FILES[$field]['tmp_name']);
    finfo_close($finfo);
    if (!isset($allowedTypes[$mime])) {
        forecast_fail(ucfirst($field) . ' is not one of the supported file types.');
    }

    $dir = dirname(__DIR__) . '/uploads/forecast';
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    $filename = $episode_id . '-' . $field . '-' . time() . '.' . $allowedTypes[$mime];
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $filename)) {
        forecast_fail('Could not save the uploaded ' . $field . '.');
    }
    if ($existing && file_exists($dir . '/' . $existing)) unlink($dir . '/' . $existing);

    return $filename;
}

$episode_id = (int) ($_POST['episode_id'] ?? 0);
$week_of    = trim($_POST['week_of']    ?? '');
$guest_name = trim($_POST['guest_name'] ?? '');
$blurb      = trim($_POST['blurb']      ?? '');
$caption    = trim($_POST['caption']    ?? '');

if ($week_of === '' || $guest_name === '' || !DateTime::createFromFormat('Y-m-d', $week_of)) {
    forecast_fail('Guest name and a valid "week of" date are required.');
}

if ($episode_id) {
    $check = $conn->prepare("SELECT * FROM `forecast_episodes` WHERE `id` = :id AND `uid` = :uid");
    $check->execute([':id' => $episode_id, ':uid' => $admin_user['id']]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);
    if (!$existing) forecast_fail('That episode was not found.');
} else {
    $stmt = $conn->prepare(
        "INSERT INTO `forecast_episodes` (uid, week_of, guest_name, blurb, caption, stamp)
         VALUES (:uid, :week_of, :guest_name, :blurb, :caption, :stamp)"
    );
    $stmt->execute([
        ':uid' => $admin_user['id'], ':week_of' => $week_of, ':guest_name' => $guest_name,
        ':blurb' => $blurb ?: null, ':caption' => $caption ?: null, ':stamp' => time(),
    ]);
    $episode_id = (int) $conn->lastInsertId();
    $existing = ['guest_photo' => null, 'audio_file' => null, 'video_file' => null];
}

$photo = forecast_handle_upload('guest_photo', $episode_id, FORECAST_IMAGE_TYPES, $existing['guest_photo']);
$audio = forecast_handle_upload('audio_file',  $episode_id, FORECAST_AUDIO_TYPES, $existing['audio_file']);
$video = forecast_handle_upload('video_file',  $episode_id, FORECAST_VIDEO_TYPES, $existing['video_file']);

$dir = dirname(__DIR__) . '/uploads/forecast';

// A directly-uploaded video is the finished, final cut (the manual-edit
// fallback path — see forecast_extract_audio_from_video()'s docblock) —
// its own audio track is what the podcast RSS feed should actually
// enclose, not whatever audio_file happened to be uploaded, in this same
// request or an earlier one, and may since have diverged from it (a
// Premiere re-edit, say). Takes priority over a freshly-uploaded
// audio_file in the same request too, for the same reason: the video is
// the one guaranteed to be the real final cut.
if ($video !== null) {
    $extracted = forecast_extract_audio_from_video($dir, $video, $episode_id);
    if ($extracted !== null) {
        $staleAudio = $audio ?? $existing['audio_file'];
        if ($staleAudio && file_exists($dir . '/' . $staleAudio)) unlink($dir . '/' . $staleAudio);
        $audio = $extracted;
    }
}

// Standardize on MP3 regardless of what was uploaded — see
// forecast_transcode_audio_to_mp3()'s docblock. Runs synchronously: an
// audio-only transcode of a ~10 minute episode takes seconds, nowhere
// near long enough to need the background-job treatment video generation
// gets.
if ($audio !== null) {
    $transcoded = forecast_transcode_audio_to_mp3($dir, $audio);
    if ($transcoded !== null) $audio = $transcoded;
}

$duration = null;
if ($video !== null) {
    $duration = forecast_probe_duration($dir . '/' . $video);
} elseif ($audio !== null) {
    $duration = forecast_probe_duration($dir . '/' . $audio);
}

$sets = ['week_of = :week_of', 'guest_name = :guest_name', 'blurb = :blurb', 'caption = :caption', 'edited = :edited'];
$params = [
    ':week_of' => $week_of, ':guest_name' => $guest_name, ':blurb' => $blurb ?: null,
    ':caption' => $caption ?: null, ':edited' => time(),
    ':id' => $episode_id, ':uid' => $admin_user['id'],
];
if ($photo !== null) { $sets[] = 'guest_photo = :guest_photo'; $params[':guest_photo'] = $photo; }
if ($audio !== null) { $sets[] = 'audio_file = :audio_file';   $params[':audio_file']  = $audio; }
if ($video !== null) { $sets[] = 'video_file = :video_file';   $params[':video_file']  = $video; }
if ($duration !== null) { $sets[] = 'duration_seconds = :duration'; $params[':duration'] = $duration; }

$conn->prepare('UPDATE `forecast_episodes` SET ' . implode(', ', $sets) . ' WHERE id = :id AND uid = :uid')
     ->execute($params);

header('Location: /_admin/forecast.php');
exit;
