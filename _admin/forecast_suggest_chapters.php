<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — suggest film chapter placements from an episode's transcript
//
//  Read-only, JSON — no CSRF check, same reasoning forecast_progress.php
//  already documents: nothing here writes anything. Just runs
//  forecast_match_transcript_films() against whatever transcript and
//  current film selection the episode already has and hands the result
//  back for the timeline JS to drop onto the bank as proposed markers —
//  never saved here, never saved anywhere until the admin hits "Save
//  chapters" themselves.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
require dirname(__DIR__) . '/v7/screenings.php';
require dirname(__DIR__) . '/list/forecast.php';

header('Content-Type: application/json');

$episode_id = (int) ($_GET['id'] ?? 0);
$episode = forecast_get_episode($conn, $episode_id);
if (!$episode || (int) $episode['uid'] !== (int) $admin_user['id']) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Not found.']);
    exit;
}

if (empty($episode['transcript'])) {
    echo json_encode(['ok' => false, 'error' => 'No transcript yet — transcribe the audio first.']);
    exit;
}

$segments = json_decode($episode['transcript'], true) ?: [];
$byDay = forecast_all_week_films($conn, $episode['week_of']);
$selectedFilms = forecast_resolve_selection($episode, $byDay);

echo json_encode(['ok' => true, 'suggestions' => forecast_match_transcript_films($segments, $selectedFilms)]);
