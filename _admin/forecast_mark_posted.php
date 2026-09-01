<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — mark one Film Forecast episode as already posted, without
//  actually posting it
//
//  For an episode posted by hand outside the app (Instagram's own app,
//  say, after a manual Premiere re-edit the auto-pipeline never saw) —
//  sets the same posted_media_id/posted_at pair forecast_post.php sets
//  after a real Graph API publish, without ever calling the API.
//  posted_media_id doubles as both "posted" and "public in the podcast
//  RSS feed" (see forecast_feed_episodes()), so this is also the only way
//  to get such an episode into the RSS feed at all.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/forecast.php';

function forecast_mark_posted_fail($msg) {
    header('Location: /_admin/forecast.php?error=' . urlencode($msg));
    exit;
}

$episode_id = (int) ($_POST['episode_id'] ?? 0);
$episode = forecast_get_episode($conn, $episode_id);
if (!$episode || (int) $episode['uid'] !== (int) $admin_user['id']) {
    forecast_mark_posted_fail('That episode was not found.');
}
if (!empty($episode['posted_media_id'])) {
    forecast_mark_posted_fail('This episode has already been posted.');
}

// Whatever the admin actually has to hand — a permalink, a media id — is
// worth keeping as a real reference (shown back on the workshop page the
// same way a real Graph API media id already is); falls back to a plain
// marker so posted_media_id still reads as "not empty" if left blank.
$reference = trim($_POST['reference'] ?? '');
$mediaId = $reference !== '' ? $reference : 'manual-' . time();

$conn->prepare("UPDATE `forecast_episodes` SET posted_media_id = :mid, posted_at = :at WHERE id = :id AND uid = :uid")
     ->execute([':mid' => $mediaId, ':at' => time(), ':id' => $episode_id, ':uid' => $admin_user['id']]);

header('Location: /_admin/forecast.php?posted=' . urlencode($mediaId));
exit;
