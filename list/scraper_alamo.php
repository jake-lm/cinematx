<?php
require_once __DIR__ . '/cache.php';

// ═══════════════════════════════════════════════════════════════════════════
//  Alamo Drafthouse — Austin market
//
//  The only source with a real API rather than HTML: the same JSON endpoint
//  their own site runs on. No key, no parsing of markup, and far less likely
//  to break on a redesign than the others.
//
//  The whole job is deciding what counts. Alamo's Austin market carries about
//  87 presentations at a time, most of them the wide-release slate — and a
//  hundred showings of a new blockbuster would drown The List. They tag their
//  own programming, so we do not have to guess:
//
//    first-run                the wide-release slate                 — excluded
//    hdr-by-barco             a projection format, not programming   — excluded
//    advance-screening        a preview of a wide release            — excluded
//    alamo-exclusive          "Only at the Alamo"                    — KEPT
//    special-event, movie-party, sing-along, live-q-a, fan-event,
//    alamo-crafthouse, special-menu                                  — KEPT
//
//  alamo-exclusive is the one that matters and the one easiest to overlook.
//  Most of it carries no eventType at all, and it is where the repertory
//  lives: a Brian De Palma retrospective, two Elaine May films, Rashomon,
//  Phantom of the Paradise. Filtering on eventType alone drops all of it and
//  leaves you with quote-alongs.
// ═══════════════════════════════════════════════════════════════════════════

const ALAMO_URL = 'https://drafthouse.com/s/mother/v2/schedule/market/austin';

// Programming we want, when it is tagged at all.
const ALAMO_EVENT_TYPES = ['special-event', 'movie-party', 'sing-along', 'live-q-a',
                           'livestream-q-a', 'fan-event', 'alamo-crafthouse', 'special-menu'];

// Tagged, but describing how it is shown rather than what is shown.
const ALAMO_NOT_PROGRAMMING = ['hdr-by-barco', 'advance-screening'];

function fetch_alamo_films($force = false) {
    return ctx_cached_scrape('cache_alamo.json', 6 * 3600, 'fetch_alamo_films_scrape', $force);
}

function fetch_alamo_films_scrape() {
    $ch = curl_init(ALAMO_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; CinemaTX/1.0; +https://cinematx.net)',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    if (!$body) return [];

    $data = json_decode($body, true);
    $data = $data['data'] ?? null;
    if (!$data || empty($data['presentations']) || empty($data['sessions'])) return [];

    // cinemaId → the short name people actually use. One venue chip covers all
    // five, so this is what tells you which side of town you are driving to.
    $cinemas = [];
    foreach ($data['market'][0]['cinemas'] ?? [] as $c) {
        if (isset($c['id'])) $cinemas[$c['id']] = $c['name'] ?? '';
    }

    $keep = [];
    foreach ($data['presentations'] as $p) {
        $attrs = (array)($p['presentationAttributeSlugs'] ?? []);
        $type  = $p['eventType']['slug'] ?? null;

        if (in_array('first-run', $attrs, true)) continue;
        if ($type && in_array($type, ALAMO_NOT_PROGRAMMING, true)) continue;

        $wanted = in_array('alamo-exclusive', $attrs, true)
               || ($type && in_array($type, ALAMO_EVENT_TYPES, true));
        if (!$wanted) continue;

        $title = trim((string)($p['show']['title'] ?? ''));
        if ($title === '') continue;
        $keep[$p['slug']] = $title;
    }
    if (!$keep) return [];

    $tz    = new DateTimeZone('America/Chicago');
    $films = [];

    foreach ($data['sessions'] as $s) {
        $slug = $s['presentationSlug'] ?? null;
        if (!$slug || !isset($keep[$slug])) continue;
        if (($s['status'] ?? '') !== 'ONSALE' || !empty($s['isHidden'])) continue;
        if (empty($s['showTimeClt'])) continue;

        // showTimeClt is cinema-local. The feed also carries showTimeUtc and
        // the two agree, so either works; local is the one that survives a
        // reader who forgets to say which zone they meant.
        try {
            $ts = (new DateTime($s['showTimeClt'], $tz))->getTimestamp();
        } catch (Exception $e) {
            continue;
        }

        $films[] = [
            'title'        => $keep[$slug],
            'url'          => 'https://drafthouse.com/austin/show/' . rawurlencode($slug),
            'timestamp'    => $ts,
            'display_date' => (new DateTime('@' . $ts))->setTimezone($tz)->format('D, M j · g:ia'),
            'location'     => $cinemas[$s['cinemaId'] ?? ''] ?? '',
        ];
    }

    return $films;
}
