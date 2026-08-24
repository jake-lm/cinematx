<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — poll one episode's video generation progress
//
//  Read-only, JSON — just relays whatever bin/forecast-generate.php last
//  wrote to uploads/forecast/<id>-progress.json. No CSRF check: nothing
//  here writes anything, same reasoning the rest of this admin area
//  reserves CSRF checks for state-changing POSTs only.
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

$status = forecast_generation_status($episode_id);
echo json_encode($status ?: ['status' => 'idle']);
