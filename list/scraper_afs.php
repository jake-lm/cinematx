<?php
require_once __DIR__ . '/cache.php';

// The scrape itself. Caching, locking and the stale-beats-empty fallback
// all live in ctx_cached_scrape(); this just fetches and parses.
function fetch_afs_films($force = false) {
    return ctx_cached_scrape('cache_afs.json', 6 * 3600, 'fetch_afs_films_scrape', $force);
}

// ═══════════════════════════════════════════════════════════════════════════
//  Per-screening detail — festival detection, and telling a shorts program
//  apart from a single film
//
//  The calendar page carries no signal at all — no class, no title prefix —
//  but every screening's own page does, in fields AFS applies uniformly:
//
//    <h2>Pan African Film Festival 2026</h2>                          series
//    <p class="t-small">Directed by Various</p>                       director
//    <img class="… c-image-grid__image--poster" src="…">              its own poster
//
//  Routine programming gets a series tag too ("New Releases", "Discovery
//  Zone"), so that field alone doesn't mean "festival" — only ones whose
//  name actually contains "film festival" do (afs_is_festival()).
//
//  A curated shorts anthology ("I AM BECAUSE WE ARE (Short Film Program)")
//  has no correct TMDB entry — the title matched an unrelated one-minute
//  short — so afs_is_short_program() flags it and the caller uses this same
//  fetch's own poster/director instead of ever asking TMDB.
//
//  One fetch per screening URL either way, cached forever — the same shape
//  as fetch_tmdb()'s cache_tmdb.json, since none of this changes once a
//  screening is published.
// ═══════════════════════════════════════════════════════════════════════════

const AFS_SCREENING_CACHE_V = 2;

function fetch_afs_detail($url) {
    $empty = ['series' => null, 'director' => null, 'poster' => null];
    if (!$url) return $empty;

    $cache_file = __DIR__ . '/cache_afs_series.json';
    $cache      = file_exists($cache_file) ? (json_decode(file_get_contents($cache_file), true) ?: []) : [];

    $have = $cache[$url] ?? null;
    if (is_array($have) && ($have['v'] ?? 0) === AFS_SCREENING_CACHE_V) return $have + $empty;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; CinemaTX/1.0)',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if (!$html) return $empty;   // transient failure — don't cache, retry next scrape

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $series_node = $xpath->query('//a[contains(@class,"c-screening__series-link")]')->item(0);
    $poster_node = $xpath->query('//img[contains(@class,"c-image-grid__image--poster")]')->item(0);

    // "Directed by X" has no class of its own — it shares .t-small with
    // nothing else on the page, so matching the text is the only way in.
    $director = null;
    foreach ($xpath->query('//p[contains(@class,"t-small")]') as $p) {
        if (preg_match('/^Directed by\s+(.+)$/i', trim($p->textContent), $m)) { $director = trim($m[1]); break; }
    }

    $out = [
        'v'        => AFS_SCREENING_CACHE_V,
        'series'   => $series_node ? trim($series_node->textContent) : null,
        'director' => $director,
        'poster'   => $poster_node ? $poster_node->getAttribute('src') : null,
    ];

    $cache[$url] = $out;
    file_put_contents($cache_file, json_encode($cache));

    return $out;
}

/** A real named festival, not one of AFS's routine programming buckets. */
function afs_is_festival($series) {
    return $series !== null && stripos($series, 'film festival') !== false;
}

/**
 * A curated shorts anthology rather than a single, TMDB-findable film.
 * The title usually says as much ("… Short Film Program") — checked first
 * since it needs no network call — and AFS's own "Directed by Various" is
 * the fallback for whatever doesn't.
 */
function afs_is_short_program($title, $director) {
    if (stripos((string)$title, 'short film') !== false) return true;
    return $director !== null && strcasecmp(trim($director), 'various') === 0;
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
            // film plays today shares the same URL and the same detail.
            $detail   = fetch_afs_detail($url);
            $festival = afs_is_festival($detail['series']) ? $detail['series'] : null;
            $is_short = afs_is_short_program($title, $detail['director']);

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
                    // A shorts program's own poster/director, standing in
                    // for TMDB's — see afs_is_short_program(). 'no_tmdb'
                    // tells fetch_all_screenings() not to let a TMDB lookup
                    // overwrite either, even if this poster fetch came back
                    // empty (a miss here should show no poster, not a wrong
                    // one borrowed from an unrelated film).
                    'no_tmdb'      => $is_short,
                    'poster'       => $is_short ? $detail['poster'] : null,
                    'director'     => $is_short ? 'Various' : null,
                ];
            }
        }
    }

    return $films;
}
