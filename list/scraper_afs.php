<?php
require_once __DIR__ . '/cache.php';

// The scrape itself. Caching, locking and the stale-beats-empty fallback
// all live in ctx_cached_scrape(); this just fetches and parses.
function fetch_afs_films($force = false) {
    return ctx_cached_scrape('cache_afs.json', 6 * 3600, 'fetch_afs_films_scrape', $force);
}

// ═══════════════════════════════════════════════════════════════════════════
//  Festival detection
//
//  The calendar page carries no signal at all — no class, no title prefix —
//  but every screening's own page does, in a field AFS applies uniformly:
//
//    <a class="c-screening__series-link" href="…">Pan African Film Festival 2026</a>
//
//  Routine programming gets tagged too ("New Releases", "Discovery Zone"),
//  so the field alone doesn't mean "festival" — only ones whose name
//  actually contains "film festival" do. Cached forever per URL, the same
//  shape as fetch_tmdb()'s cache_tmdb.json, since a screening's assigned
//  series never changes once published.
// ═══════════════════════════════════════════════════════════════════════════

const AFS_SERIES_CACHE_V = 1;

function fetch_afs_series($url) {
    if (!$url) return null;

    $cache_file = __DIR__ . '/cache_afs_series.json';
    $cache      = file_exists($cache_file) ? (json_decode(file_get_contents($cache_file), true) ?: []) : [];

    $have = $cache[$url] ?? null;
    if (is_array($have) && ($have['v'] ?? 0) === AFS_SERIES_CACHE_V) return $have['series'];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; CinemaTX/1.0)',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if (!$html) return null;   // transient failure — don't cache, retry next scrape

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();

    $node   = (new DOMXPath($dom))->query('//a[contains(@class,"c-screening__series-link")]')->item(0);
    $series = $node ? trim($node->textContent) : null;

    $cache[$url] = ['v' => AFS_SERIES_CACHE_V, 'series' => $series];
    file_put_contents($cache_file, json_encode($cache));

    return $series;
}

/** A real named festival, not one of AFS's routine programming buckets. */
function afs_is_festival($series) {
    return $series !== null && stripos($series, 'film festival') !== false;
}

function fetch_afs_films_scrape() {

    $ch = curl_init('https://www.austinfilm.org/calendar/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; CinemaTX/1.0)',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $html = curl_exec($ch);
    curl_close($ch);

    if (!$html) return [];

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $tz    = new DateTimeZone('America/Chicago');
    $films = [];

    // Each calendar day cell carries its date in the id attribute (YYYYMMDD)
    $days = $xpath->query('//td[contains(@class,"c-calendar__day") and not(contains(@class,"c-calendar__day--inactive"))]');

    foreach ($days as $day) {
        $date_id = $day->getAttribute('id'); // e.g. "20260621"
        if (!preg_match('/^\d{8}$/', $date_id)) continue;

        // Only screenings (red text) — skip afs_event entries entirely
        $screenings = $xpath->query(
            './/div[contains(@class,"afs_screening") and contains(@class,"current") and not(contains(@class,"expired"))]',
            $day
        );

        foreach ($screenings as $event) {
            $link_node  = $xpath->query('.//a[contains(@class,"afs_screening_link")]', $event)->item(0);
            $time_node  = $xpath->query('.//p[contains(@class,"t-smaller")]', $event)->item(0);
            if (!$link_node) continue;

            $title    = trim($link_node->textContent);
            $url      = $link_node->getAttribute('href') ?: null;
            $time_str = $time_node ? trim($time_node->textContent) : '';

            // Once per screening, not once per showtime — every time this
            // film plays today shares the same URL and the same series.
            $series   = fetch_afs_series($url);
            $festival = afs_is_festival($series) ? $series : null;

            // A film playing more than once in a day renders as one row with
            // every time comma-joined ("4:15 PM, 7:30 PM"), not a row per
            // showing — split first, or the parse below chokes on the
            // leftover ", 7:30 PM", fails, and the fallback loses both times.
            $times = $time_str !== '' ? array_filter(array_map('trim', explode(',', $time_str))) : [''];

            foreach ($times as $one_time) {
                $timestamp = null;
                if ($one_time !== '') {
                    $dt = DateTime::createFromFormat('Ymd g:i A', "$date_id $one_time", $tz);
                    $timestamp = $dt ? $dt->getTimestamp() : null;
                }
                if (!$timestamp) {
                    // Fallback: midnight of the day. 'Ymd' leaves every time
                    // component the format doesn't mention — here, all of
                    // them — at the current wall-clock time rather than
                    // zero, so without an explicit setTime() this "midnight"
                    // silently drifts to whatever moment the scrape runs.
                    $dt = DateTime::createFromFormat('Ymd', $date_id, $tz);
                    if ($dt) $dt->setTime(0, 0, 0);
                    $timestamp = $dt ? $dt->getTimestamp() : null;
                }

                $films[] = [
                    'title'        => $title,
                    'url'          => $url,
                    'timestamp'    => $timestamp,
                    'display_date' => $timestamp
                        ? (new DateTime('@' . $timestamp))->setTimezone($tz)->format('D, M j · g:ia')
                        : $date_id,
                    'festival'     => $festival,
                ];
            }
        }
    }

    return $films;
}
