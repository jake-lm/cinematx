<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — delete a Film Forecast episode and its uploaded files
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/forecast.php';

$episode_id = (int) ($_POST['episode_id'] ?? 0);
$episode = forecast_get_episode($conn, $episode_id);

if ($episode && (int) $episode['uid'] === (int) $admin_user['id']) {
    $dir = dirname(__DIR__) . '/uploads/forecast';
    foreach (['guest_photo', 'audio_file', 'video_file', 'generated_video'] as $field) {
        if (!empty($episode[$field]) && file_exists($dir . '/' . $episode[$field])) {
            unlink($dir . '/' . $episode[$field]);
        }
    }
    $conn->prepare("DELETE FROM `forecast_episodes` WHERE id = :id AND uid = :uid")
         ->execute([':id' => $episode_id, ':uid' => $admin_user['id']]);
}

header('Location: /_admin/forecast.php');
exit;
