<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Film news cache warmer — run from cron, never from the web
//
//    0 7 * * *  /usr/bin/php /path/to/cinematx/bin/warm-news.php >> /var/log/cinematx-warm-news.log 2>&1
//
//  Its own cron entry, independent of bin/warm-cache.php's 30-minute cycle —
//  news moves on a daily cadence, not an hourly one, so there's nothing to
//  gain from checking anywhere near that often. The admin panel's own
//  "Refresh now" button (_admin/news_refresh.php) covers wanting it sooner
//  than the next morning.
// ═══════════════════════════════════════════════════════════════════════════

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/config.php';
require $root . '/list/scraper_news.php';

$lock = fopen(sys_get_temp_dir() . '/cinematx-warm-news.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, date('c') . " warm-news: already running, skipping\n");
    exit(0);
}

$started = microtime(true);
$items   = fetch_all_news(true);

printf("%s warm-news: %d stories in %.1fs\n", date('c'), count($items), microtime(true) - $started);
$bySource = [];
foreach ($items as $i) $bySource[$i['source']] = ($bySource[$i['source']] ?? 0) + 1;
foreach ($bySource as $source => $n) printf("           %-24s %d\n", $source, $n);

foreach (['Austin Chronicle', 'Austin Film Festival'] as $source) {
    if (empty($bySource[$source])) {
        fwrite(STDERR, date('c') . " warm-news: WARNING — no stories from $source\n");
    }
}

flock($lock, LOCK_UN);
fclose($lock);
