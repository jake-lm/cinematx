<?php
require_once __DIR__ . '/cache.php';

// The scrape itself. Caching, locking and the stale-beats-empty fallback
// all live in ctx_cached_scrape(); this just fetches and parses.
function fetch_afs_films($force = false) {
    return ctx_cached_scrape('cache_afs.json', 6 * 3600, 'fetch_afs_films_scrape', $force);
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
                ];
            }
        }
    }

    return $films;
}
