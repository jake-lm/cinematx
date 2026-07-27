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

            $timestamp = null;
            if ($time_str) {
                $dt = DateTime::createFromFormat('Ymd g:i A', "$date_id $time_str", $tz);
                $timestamp = $dt ? $dt->getTimestamp() : null;
            }
            if (!$timestamp) {
                // Fallback: midnight of the day
                $dt = DateTime::createFromFormat('Ymd', $date_id, $tz);
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

    return $films;
}
