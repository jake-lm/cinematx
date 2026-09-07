<?php
require_once __DIR__ . '/cache.php';

// ═══════════════════════════════════════════════════════════════════════════
//  Flick Clique — Austin
//
//  A monthly outdoor classic-film series at one fixed venue (The Sekrit
//  Theater), roughly a screening a month rather than the near-daily volume
//  every other source here has. Server-rendered Squarespace "Simple List"
//  block though, and a clean, stable one: one <li class="list-item"> per
//  screening, a title, and a three-paragraph description (date / where+time /
//  synopsis) — no headless browser needed, same cURL+DOMDocument approach as
//  every other scraper.
//
//  Time is always given as "Doors at H:MM, Movie at X" — X is sometimes a
//  clock time and sometimes literally "sunset" (which floats through the
//  year), so rather than compute actual sunset for the date, this lists the
//  door time as the start — always a real clock time, always given.
// ═══════════════════════════════════════════════════════════════════════════

const FLICKCLIQUE_URL   = 'https://www.flickclique.org/austin-flick-clique';
const FLICKCLIQUE_VENUE = 'Flick Clique';

function fetch_flickclique_films($force = false) {
    return ctx_cached_scrape('cache_flickclique.json', 6 * 3600, 'fetch_flickclique_films_scrape', $force);
}

function fetch_flickclique_films_scrape() {
    $ch = curl_init(FLICKCLIQUE_URL);
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
    $items = $xpath->query(
        '//li[contains(concat(" ",normalize-space(@class)," ")," list-item ")]'
        . '[.//h2[contains(@class,"list-item-content__title")]]'
    );

    $films = [];
    $tz    = new DateTimeZone('America/Chicago');
    $now   = time();

    foreach ($items as $item) {
        $title_node = $xpath->query('.//h2[contains(@class,"list-item-content__title")]', $item)->item(0);
        if (!$title_node) continue;
        $title = trim($title_node->textContent);
        if ($title === '') continue;

        $paras = $xpath->query('.//div[contains(@class,"list-item-content__description")]//p', $item);
        $date_str = null;
        $doors    = null;
        foreach ($paras as $p) {
            $text = trim(preg_replace('/\s+/', ' ', $p->textContent));
            if ($date_str === null && preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})$#', $text, $dm)) {
                $date_str = $dm;
                continue;
            }
            // "Doors at" is always a real clock time — unlike "Movie at",
            // which is sometimes literally "sunset" — and always evening for
            // an outdoor movie night, so an hour under 12 with no am/pm
            // marker reads as PM rather than needing one.
            if ($doors === null && preg_match('/Doors at\s*(\d{1,2}):(\d{2})/i', $text, $tm)) {
                $doors = $tm;
            }
        }
        if (!$date_str || !$doors) continue;

        $hour = (int) $doors[1];
        if ($hour < 12) $hour += 12;
        $dt = DateTime::createFromFormat(
            'n j Y H i',
            $date_str[1] . ' ' . $date_str[2] . ' ' . (strlen($date_str[3]) === 2 ? '20' . $date_str[3] : $date_str[3])
                . ' ' . $hour . ' ' . $doors[2],
            $tz
        );
        if (!$dt) continue;
        $timestamp = $dt->getTimestamp();

        // The page keeps the whole year's schedule up at once, past
        // screenings included — filter_screenings() downstream would drop
        // them anyway, but skip early rather than resolving a Facebook link
        // and TMDB match for something that can never show.
        if ($timestamp < $now - 86400) continue;

        $films[] = [
            'title'        => $title,
            'venue'        => FLICKCLIQUE_VENUE,
            // No dedicated per-screening page, cash-at-the-door with no
            // booking system — every screening just links to Flick Clique's
            // one page, easy enough to find the current listing from there.
            'url'          => FLICKCLIQUE_URL,
            'timestamp'    => $timestamp,
            'display_date' => $dt->format('D, M j · g:ia'),
        ];
    }

    return $films;
}
