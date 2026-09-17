<?php
require_once __DIR__ . '/cache.php';

// Hyperreal appends this to every real screening's title — but not to other
// event types on the same calendar (a members' mixer, "PREHEAT", has no
// such suffix). A better signal than the URL slug: most screenings' hrefs
// end in "-movie-screening", but a live-scored classic and a touring shorts
// festival used a different slug and were silently dropped by that filter
// even though their titles carry the same suffix as every other screening.
const HYPERREAL_SUFFIX = '/\s+at\s+hyperreal\s+film\s+club\s*$/i';

// Hyperreal never supplies a poster of its own the way AFS sometimes does —
// there's no per-screening image on their event pages at all — so a TMDB
// miss here has nothing to borrow and falls back to this instead. Their
// own logo, hotlinked from their own site (same approach list/scraper_news.php
// already takes for a source with no article image of its own).
const HYPERREAL_LOGO = 'https://static1.squarespace.com/static/58a13eba20099eb147e68d26/t/6a04f6e78939e571f40bdfd2/1778710247731/Hyperreal+Film+Club+Logo.png?format=1500w';

// The scrape itself. Caching, locking and the stale-beats-empty fallback
// all live in ctx_cached_scrape(); this just fetches and parses.
function fetch_hyperreal_films($force = false) {
    return ctx_cached_scrape('cache_hyperreal.json', 6 * 3600, 'fetch_hyperreal_films_scrape', $force);
}

/**
 * One screening's own event page — fetched lazily, only when TMDB has
 * already come up empty for it (fetch_screenings.php), since most Hyperreal
 * screenings are real, TMDB-findable films that never need this. A curated
 * showcase or a touring indie ("8th Local Filmmakers' Showcase presented by
 * Experimental Response Cinema", "WITHDRAWAL ~ Discovery Zone Roadshow")
 * never matches anything on TMDB, but Hyperreal's own page almost always
 * has a real description — same Squarespace text block on every event page
 * checked so far (data-sqsp-text-block-content), always structured as: a
 * "The vitals: doors/showtime" paragraph first, then the actual description.
 * The first paragraph is skipped for exactly that reason — it's logistics,
 * not synopsis. Cached forever per URL, same as fetch_afs_detail() — none
 * of this changes once a screening is published.
 */
function fetch_hyperreal_detail($url) {
    $empty = ['overview' => null];
    if (!$url) return $empty;

    $cache_file = __DIR__ . '/cache_hyperreal_detail.json';
    $cache      = file_exists($cache_file) ? (json_decode(file_get_contents($cache_file), true) ?: []) : [];
    if (isset($cache[$url])) return $cache[$url] + $empty;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; CinemaTX/1.0)',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if (!$html) return $empty;   // transient failure — don't cache, retry next time

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $paras = $xpath->query('//div[contains(@class,"sqs-html-content")][@data-sqsp-text-block-content]//p');
    $overview = null;
    if ($paras->length > 1) {
        $overview = trim(preg_replace('/\s+/', ' ', $paras->item(1)->textContent));
    }

    $out = ['overview' => $overview ?: null];
    $cache[$url] = $out;
    file_put_contents($cache_file, json_encode($cache));

    return $out;
}

function fetch_hyperreal_films_scrape() {

    // Determine which month(s) to fetch — if the next 8 days spill into the next month, grab both
    $now    = time();
    $tz     = new DateTimeZone('America/Chicago');
    $months = [date('m-Y', $now)];
    if (date('n', $now + 8 * 86400) !== date('n', $now)) {
        $months[] = date('m-Y', strtotime('first day of next month', $now));
    }

    $seen  = [];
    $films = [];

    foreach ($months as $month) {
        $url  = 'https://hyperrealfilm.club/events?view=calendar&month=' . $month;
        $ch   = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; CinemaTX/1.0)',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $html = curl_exec($ch);
        curl_close($ch);
        if (!$html) continue;

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Every card in the events list, filtered below by title rather
        // than by href — see HYPERREAL_SUFFIX.
        $items = $xpath->query(
            '//main[contains(@class,"Main--events-list")]
             //li[.//h1/a]'
        );

        foreach ($items as $item) {
            $link_node = $xpath->query('.//h1/a', $item)->item(0);
            if (!$link_node) continue;

            $raw_title = trim($link_node->textContent);
            if (!preg_match(HYPERREAL_SUFFIX, $raw_title)) continue;   // not a screening

            $href = $link_node->getAttribute('href');
            $abs_url = 'https://hyperrealfilm.club' . $href;

            if (isset($seen[$abs_url])) continue; // deduplicate across months
            $seen[$abs_url] = true;

            $title = trim(preg_replace(HYPERREAL_SUFFIX, '', $raw_title));

            // Find the date/time div — it's the one that contains "AM" or "PM"
            $date_text = '';
            $divs = $xpath->query('./div', $item);
            foreach ($divs as $div) {
                $text = trim($div->textContent);
                if (preg_match('/[AP]M/i', $text) && strlen($text) > 5) {
                    $date_text = html_entity_decode($text, ENT_HTML5, 'UTF-8');
                    // Normalize Unicode whitespace variants (narrow no-break space U+202F, etc.) to plain spaces
                    $date_text = preg_replace('/[\x{00A0}\x{202F}\x{2009}\x{200A}]/u', ' ', $date_text);
                    $date_text = preg_replace('/\s+/', ' ', trim($date_text));
                    break;
                }
            }

            // Parse "Tuesday, June 24, 2026, 7:30 PM – 11:00 PM" — we only need the start
            $timestamp = null;
            if ($date_text && preg_match('/(\w+, \w+ \d+, \d{4}, \d+:\d+ [AP]M)/i', $date_text, $m)) {
                $dt = DateTime::createFromFormat('l, F j, Y, g:i A', $m[1], $tz);
                $timestamp = $dt ? $dt->getTimestamp() : null;
            }

            $films[] = [
                'title'        => $title,
                'url'          => $abs_url,
                'timestamp'    => $timestamp,
                'display_date' => $timestamp
                    ? (new DateTime('@' . $timestamp))->setTimezone($tz)->format('D, M j · g:ia')
                    : trim($date_text),
            ];
        }
    }

    return $films;
}
