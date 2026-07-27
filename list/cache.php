<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Scrape cache
//
//  All three venue scrapers had the same eight lines of caching copied into
//  them, and the same two problems with it:
//
//    A failed scrape returned []. The cache file was still sitting there with
//    perfectly good listings from a few hours ago, but an expired TTL meant we
//    re-scraped, the venue was down, and that venue vanished from The List
//    entirely. For a listings site, six-hour-old showtimes beat no showtimes.
//
//    Nothing serialised the refresh. On a cold cache every concurrent visitor
//    scraped simultaneously — worst on a fresh deploy, which is exactly when
//    every cache is cold.
//
//  Both matter more with a cron in front, because a cron that fails leaves an
//  expired cache for page requests to trip over.
// ═══════════════════════════════════════════════════════════════════════════

// How soon to try again after a failed scrape. Long enough not to hammer a
// venue that is down, far short of the six hours a success buys.
const CTX_SCRAPE_RETRY = 900;

function ctx_cached_scrape($file, $ttl, callable $fetch, $force = false) {
    $path = __DIR__ . '/' . $file;
    $read = function () use ($path) {
        return file_exists($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
    };

    if (!$force && file_exists($path) && (time() - filemtime($path)) < $ttl) return $read();

    // Whoever gets the lock refreshes; everyone else serves what is on disk
    // rather than queueing behind a fifteen-second HTTP request.
    $lock = @fopen($path . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) fclose($lock);
        return $read();
    }

    try {
        $films = $fetch();
    } catch (Throwable $e) {
        error_log('scrape failed for ' . $file . ': ' . $e->getMessage());
        $films = [];
    }

    if ($films) {
        file_put_contents($path, json_encode($films));
    } elseif (file_exists($path)) {
        // Keep the old listings and come back sooner than a full TTL. Setting
        // mtime into the past is what schedules that retry.
        touch($path, time() - $ttl + CTX_SCRAPE_RETRY);
        $films = $read();
    }

    flock($lock, LOCK_UN);
    fclose($lock);
    return $films;
}
