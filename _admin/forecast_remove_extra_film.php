<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — remove a manually-added film from the Timeline bank
//
//  fetch()-driven, unlike forecast_add_extra_film.php — adding needs a
//  reload anyway to render the new film's storyboard thumbnail, but
//  removing needs no server-side regeneration at all, so there's no
//  reason to lose the timeline's in-progress drag state (unsaved marker
//  positions) to a full page reload just to delete one bank entry. Any
//  chapter marker still pointing at this key is simply dropped the next
//  time the timeline resolves — see forecast_resolve_timeline() — the
//  same silent-drop a real film's marker already gets when unchecked
//  from the selection, so nothing extra to clean up server-side either;
//  the client removes its own now-stale marker(s) immediately.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/forecast.php';

header('Content-Type: application/json');

$episode_id = (int) ($_POST['episode_id'] ?? 0);
$episode = forecast_get_episode($conn, $episode_id);
if (!$episode || (int) $episode['uid'] !== (int) $admin_user['id']) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'That episode was not found.']);
    exit;
}

$key = (string) ($_POST['key'] ?? '');
if ($key !== '') {
    forecast_remove_extra_film($conn, $episode_id, $admin_user['id'], $episode, $key);
}

echo json_encode(['ok' => true]);
