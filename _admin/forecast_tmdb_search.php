<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — TMDB title search for "add a film" (Timeline bank)
//
//  Read-only, JSON — no CSRF check, same reasoning forecast_progress.php
//  already documents: nothing here writes anything. Just proxies
//  tmdb_search_movies() so the search box's own API key never has to
//  reach the browser.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
require dirname(__DIR__) . '/list/forecast.php';

header('Content-Type: application/json');

echo json_encode(['ok' => true, 'results' => tmdb_search_movies($_GET['q'] ?? '')]);
