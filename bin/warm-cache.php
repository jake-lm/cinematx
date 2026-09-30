<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Cache warmer — run from cron, never from the web
//
//    */30 * * * *  /usr/bin/php /path/to/cinematx/bin/warm-cache.php >> /var/log/cinematx-warm.log 2>&1
//
//  Without this, the caches refill lazily: whichever visitor happens to arrive
//  after a TTL expires waits on three venue scrapes plus a TMDB lookup for
//  every unfamiliar title. On a fresh deployment, where every cache is cold,
//  that first visitor pays for the entire listing — roughly a hundred HTTP
//  round-trips — before a single byte renders.
//
//  Run it once by hand immediately after deploying, before announcing the site.
//
//  Scraping is forced here rather than TTL-gated, so the caches are always
//  refreshed out of band and a page request never has to.
// ═══════════════════════════════════════════════════════════════════════════

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
// The only place Rotten Tomatoes is ever fetched live — see list/rt.php.
// Defined before the includes so nothing that reads it can see it unset.
define('CTX_RT_LIVE', true);
require $root . '/config.php';
require $root . '/database.php';
require $root . '/list/fetch_screenings.php';
require $root . '/v7/screenings.php';

// One warmer at a time. A slow run must not have the next cron tick land on
// top of it and re-scrape everything in parallel.
$lock = fopen(sys_get_temp_dir() . '/cinematx-warm.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, date('c') . " warm: already running, skipping\n");
    exit(0);
}

$started = microtime(true);
$now     = time();

// Enriching is what populates the TMDB cache — poster, year, runtime, plot and
// the Wikipedia link — so this warms both layers in one pass, by doing exactly
// what a page request does.
// From midnight rather than from now: the front page lists all of today,
// showings already under way included, and page requests never fetch a
// Rotten Tomatoes score themselves (see list/rt.php) — so anything that
// started before this run would go without one for the rest of the day.
$films = ctx_enrich(fetch_all_screenings($conn, strtotime('today', $now), $now + CTX_LOOKAHEAD_DAYS * 86400, true));

// Pulls this schedule's IMDb ids out of the downloaded ratings file (see
// list/imdb.php). The scores were read during enrichment above, before the
// file had been scanned for any new ids, so they're re-read here.
$imdb_ready = imdb_index(array_column($films, 'imdb')) !== false;
foreach ($films as &$f) $f['imdb_score'] = !empty($f['imdb']) ? fetch_imdb($f['imdb']) : null;
unset($f);

$total = count($films);
$have  = ['poster' => 0, 'year' => 0, 'runtime' => 0, 'overview' => 0, 'wiki' => 0, 'rt' => 0, 'imdb_score' => 0];
foreach ($films as $f) {
    foreach ($have as $k => $_) if (!empty($f[$k])) $have[$k]++;
}

$venues = [];
foreach ($films as $f) $venues[$f['venue'] ?? '?'] = ($venues[$f['venue'] ?? '?'] ?? 0) + 1;

printf("%s warm: %d screenings in %.1fs\n", date('c'), $total, microtime(true) - $started);
foreach ($venues as $v => $n) printf("           %-24s %d\n", $v, $n);
foreach ($have as $k => $n)   printf("           %-24s %d/%d\n", $k, $n, $total);
echo "           (rt = Rotten Tomatoes critics score, imdb_score = IMDb rating; gaps are normal, mostly indie/local screenings)\n";
if (!$imdb_ready) fwrite(STDERR, date('c') . " warm: no IMDb ratings file yet — run: php bin/refresh-imdb.php\n");

// A venue that scrapes to nothing is usually a changed page structure, and it
// fails quietly — The List simply stops mentioning them. Worth a loud line in
// the log rather than a silent gap in the listings.
foreach (['Paramount Theatre', 'Austin Film Society', 'Hyperreal Film Club'] as $v) {
    if (empty($venues[$v])) fwrite(STDERR, date('c') . " warm: WARNING — no screenings from $v\n");
}

// The poster count above says HOW MANY missed; it doesn't say which, so a
// title TMDB can't match sits silently posterless until someone happens to
// notice it on Instagram or the site (see "Akira (Subtitled) in 4K" — an
// exhibition annotation TMDB_FORMATS didn't fully strip, permanently cached
// as a dead search). Naming them here means a bad title-cleaning pattern
// shows up in the log the same night it starts screening, not whenever
// someone spots the gap.
$posterless = [];
foreach ($films as $f) if (empty($f['poster'])) $posterless[] = $f['title'] ?? '(untitled)';
if ($posterless) {
    fwrite(STDERR, date('c') . ' warm: WARNING — no poster for: ' . implode('; ', array_unique($posterless)) . "\n");
}

flock($lock, LOCK_UN);
fclose($lock);
