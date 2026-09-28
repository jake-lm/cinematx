<?php
require_once __DIR__ . '/cache.php';

// We Luv Video — a North Loop video store that also runs a near-weekly
// screening calendar: KaiJune, SunGays, Pulsing Cinema, Blood Shed Theater
// and a handful of other in-house series, each its own recurring night.
//
// The site is Next.js. Every event on the listing page is embedded as JSON
// inside a React Server Components payload (self.__next_f.push([1,"…"])),
// not a documented API — the only place that lists every event at once, so
// it's what this reads, found by content rather than by which push() call
// happens to carry it, since that isn't guaranteed stable across their own
// deploys. Each event's own page additionally carries a standard schema.org
// JSON-LD <script> block, a much steadier thing to parse than React
// internals — used here for the one field the listing doesn't carry: the
// real synopsis.
const WELUVVIDEO_EVENTS_URL = 'https://store.weluvvideo.org/events';
const WELUVVIDEO_EVENT_URL  = 'https://store.weluvvideo.org/events/';

// A specific film's own listing almost always carries its year right in the
// title — "Dazed and Confused (1994)" — the same signal ctx_year() reads
// elsewhere, checked independently here rather than importing v7/screenings.php
// into a scraper. Nothing else in their calendar is reliably this
// consistent: "Saturday Toon Time," a tribute night, a VHS swap meet have no
// single film to look up at all, and without this a generic enough one of
// those can confidently match the wrong thing on TMDB rather than just
// missing outright — "Toon Time" alone matched an unrelated 2012 short.
// Their own logo standing in for a poster is the same call already made for
// Hyperreal, which never has one of its own either.
const WELUVVIDEO_YEAR = '/\((?:19|20)\d{2}\)/';

// The site's own configured logo (Squarespace's logoImageUrl setting) —
// verified against their site's own settings JSON, not guessed from a
// generic image on the page.
const WELUVVIDEO_LOGO = 'https://images.squarespace-cdn.com/content/v1/62e166514da03e7adfe55102/e5c2edaa-a99e-482b-aa5a-42943ae84dd8/WLV_LogoGreenHeart.png?format=1500w';

function fetch_weluvvideo_films($force = false) {
    return ctx_cached_scrape('cache_weluvvideo.json', 6 * 3600, 'fetch_weluvvideo_films_scrape', $force);
}

function weluvvideo_http($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; CinemaTX/1.0)',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return $body ?: null;
}

// Whichever spelling/casing an event was typed with ("WeLuvVideo",
// "WE LUV VIDEO", "we luv video" and "We Luv Video" all appear in the same
// feed) collapses to nothing — the store is the default venue, so there's
// nothing worth naming. Anything that isn't some form of their own name
// (so far, just "Camp East", a one-off pop-up) is carried as the location
// instead, the same shape Alamo's and Flick Clique's own venue/location
// split already uses.
function weluvvideo_location($raw) {
    $slug = preg_replace('/[^a-z]/', '', strtolower((string)$raw));
    return $slug === 'weluvvideo' ? null : trim((string)$raw);
}

// The listing's embedded payload is a JS string literal — escaped exactly
// like a JSON string (that's what Next.js serializes it as), so wrapping a
// candidate chunk back in quotes and handing it to json_decode() unescapes
// it correctly rather than approximating with str_replace. Once decoded,
// the "events" array's own bounds are found by counting brackets rather
// than guessing at a closing pattern, since the array itself contains
// plenty of unrelated [ and ] inside strings and other nested values.
function weluvvideo_parse_listing($html) {
    if (!preg_match_all('/self\.__next_f\.push\(\[1,"(.*?)"\]\)/s', $html, $matches)) return [];

    foreach ($matches[1] as $chunk) {
        $decoded = json_decode('"' . $chunk . '"');
        if (!is_string($decoded)) continue;
        $key = '"events":[';
        $pos = strpos($decoded, $key);
        if ($pos === false) continue;

        $start = $pos + strlen($key) - 1; // include the opening [
        $depth = 0;
        $end   = null;
        for ($i = $start, $len = strlen($decoded); $i < $len; $i++) {
            if ($decoded[$i] === '[') $depth++;
            elseif ($decoded[$i] === ']') {
                $depth--;
                if ($depth === 0) { $end = $i + 1; break; }
            }
        }
        if ($end === null) continue;

        // React Server Components' own date serialization — "$D" immediately
        // before an ISO 8601 value, inside its own quotes already. Stripping
        // just the two-character marker leaves valid JSON behind.
        $arrayText = str_replace('$D', '', substr($decoded, $start, $end - $start));
        $events    = json_decode($arrayText, true);
        if (is_array($events)) return $events;
    }
    return [];
}

// One event's own page — the listing has no synopsis at all, and this is
// the only place a real one exists. schema.org JSON-LD rather than the
// page's React markup: a standard block meant to be machine-read, and far
// less likely to shift shape on a redesign than the component tree around
// it. Cached forever per URL, same as fetch_afs_detail() — a published
// screening's own description doesn't change after the fact.
function fetch_weluvvideo_detail($url) {
    $empty = ['overview' => null];
    if (!$url) return $empty;

    $cache_file = __DIR__ . '/cache_weluvvideo_detail.json';
    $cache      = file_exists($cache_file) ? (json_decode(file_get_contents($cache_file), true) ?: []) : [];
    if (isset($cache[$url])) return $cache[$url] + $empty;

    $html = weluvvideo_http($url);
    if (!$html) return $empty;   // transient failure — don't cache, retry next time

    if (!preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m)) return $empty;
    $ld = json_decode($m[1], true);
    if (!is_array($ld)) return $empty;

    $out = ['overview' => !empty($ld['description']) ? trim($ld['description']) : null];
    $cache[$url] = $out;
    file_put_contents($cache_file, json_encode($cache));

    return $out;
}

function fetch_weluvvideo_films_scrape() {
    $html = weluvvideo_http(WELUVVIDEO_EVENTS_URL);
    if (!$html) return [];

    $events = weluvvideo_parse_listing($html);
    $now    = time();
    $films  = [];

    foreach ($events as $e) {
        $title = trim((string)($e['title'] ?? ''));
        $slug  = trim((string)($e['slug'] ?? ''));
        if ($title === '' || $slug === '' || empty($e['startsAt'])) continue;

        // ISO 8601 with an explicit "Z" — already absolute, unlike AFS's or
        // Hyperreal's own bare "7:30 PM" that needs a timezone attached to
        // mean anything. strtotime() resolves it correctly on its own.
        $timestamp = strtotime($e['startsAt']);
        if (!$timestamp || $timestamp < $now - 86400) continue;   // past events stay in the feed

        $url      = WELUVVIDEO_EVENT_URL . $slug;
        $detail   = fetch_weluvvideo_detail($url);
        $is_dated = (bool)preg_match(WELUVVIDEO_YEAR, $title);

        $films[] = [
            'title'        => $title,
            'url'          => $url,
            'timestamp'    => $timestamp,
            'display_date' => (new DateTime('@' . $timestamp))->setTimezone(new DateTimeZone('America/Chicago'))->format('D, M j · g:ia'),
            'location'     => weluvvideo_location($e['venue'] ?? ''),
            // See WELUVVIDEO_YEAR: no year in the title means no specific
            // film to look up at all, so fetch_all_screenings() is told not
            // to try — their own logo stands in for a poster the same way
            // HYPERREAL_LOGO does, and their real description still comes
            // through regardless.
            'no_tmdb'      => !$is_dated,
            'poster'       => $is_dated ? null : WELUVVIDEO_LOGO,
            'overview'     => $is_dated ? null : $detail['overview'],
            'director'     => null,
            'runtime'      => null,
            // The ordinary TMDB-fallback pair, same role afs_poster/
            // afs_overview play — only reached when $is_dated but TMDB still
            // came back with nothing, an actual miss rather than "there was
            // never a film to find."
            'weluvvideo_poster'   => !empty($e['posterUrl']) ? $e['posterUrl'] : null,
            'weluvvideo_overview' => $detail['overview'],
        ];
    }

    usort($films, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
    return $films;
}
