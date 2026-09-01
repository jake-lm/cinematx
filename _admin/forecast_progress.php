<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — poll one episode's generation progress (video, package export,
//  waveform export, or intro animation — ?kind=, default 'video')
//
//  Read-only, JSON — just relays whatever the matching bin/forecast-*.php
//  worker last wrote to its own uploads/forecast/<id>[-kind]-progress.json.
//  No CSRF check: nothing here writes anything, same reasoning the rest of
//  this admin area reserves CSRF checks for state-changing POSTs only.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
require dirname(__DIR__) . '/list/forecast.php';

header('Content-Type: application/json');

$episode_id = (int) ($_GET['id'] ?? 0);
$episode = forecast_get_episode($conn, $episode_id);
if (!$episode || (int) $episode['uid'] !== (int) $admin_user['id']) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'error' => 'Not found.']);
    exit;
}

$kind = in_array($_GET['kind'] ?? 'video', ['video', 'package', 'waveform', 'intro'], true) ? $_GET['kind'] : 'video';
$status = forecast_generation_status($episode_id, $kind);
echo json_encode($status ?: ['status' => 'idle']);
