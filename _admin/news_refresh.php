<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — force-refresh the film news feed
//
//  The "Refresh now" button on news.php. Both sources are small, fast RSS
//  fetches (no per-item detail pages the way AFS's own scraper needs), so
//  this runs synchronously and redirects back — nothing like
//  forecast_generate.php's detached-process treatment is warranted here.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/scraper_news.php';

fetch_all_news(true);

header('Location: /_admin/news.php');
exit;
