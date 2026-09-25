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

// Alamo's own copy for a screening, for when TMDB has nothing to say about it.
// The schedule feed already carries a synopsis, tagline, runtime and poster
// for every presentation — no second page to fetch, unlike Hyperreal's event
// pages — and it is the only place a series like "Mystery Transmission" or
// "Video Vortex" is described at all: TMDB has no entry for any of it. Only
// used as a fallback (see fetch_screenings.php); a real TMDB match still wins.
//
// Long enough to read as a real synopsis, short enough that the handful of
// multi-paragraph press-release entries (a live-score tour, a 4,000-character
// essay) do not bloat every cached film row — cards clamp to a few lines
// anyway.
const ALAMO_OVERVIEW_MAX = 600;

// A mystery screening — "Mystery Transmission" (Video Vortex, monthly),
// "Mystery Voyage" (AGFADROME, monthly) — is not a film: the title is a
// placeholder for a feature kept secret until the lights go down, so there
// is nothing for TMDB to look up, and whatever it does return for the name
// is somebody else's movie ("Mystery Voyage" came back as a 2006 film).
// Alamo's own blurb for the installment is the whole description that exists.
// Limited to Alamo's own collection series so a normal booking of a real film
// with "Mystery" in its name ("Mystery Train") still goes through TMDB.
function alamo_is_mystery(array $presentation, $title) {
    return preg_match('/^mystery\b/i', $title) === 1
        && in_array('alamo-exclusive', (array)($presentation['presentationAttributeSlugs'] ?? []), true)
        && (($presentation['superTitle']['type'] ?? null) === 'COLLECTION');
}

// The feed's HTML descriptions ("<p>…</p><p>…</p>", the odd link or <em>)
// flattened to one line of plain text, cut at a word boundary if it runs long.
function alamo_plain_text($html) {
    $html = preg_replace('#</p>|<br\s*/?>#i', ' ', (string)$html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if (mb_strlen($text) > ALAMO_OVERVIEW_MAX) {
        $cut = mb_substr($text, 0, ALAMO_OVERVIEW_MAX);
        // Prefer ending on a whole sentence, so a cut synopsis still reads
        // as finished rather than trailing off mid-thought — unless that
        // would throw away more than half of it.
        if (preg_match('/^(.{' . intdiv(ALAMO_OVERVIEW_MAX, 2) . ',}[.!?])(?:\s|$)/us', $cut, $m)) {
            return $m[1];
        }
        $cut  = preg_replace('/\s+\S*$/u', '', $cut);
        $text = rtrim($cut, " ,;:-\u{2014}") . '…';
    }
    return $text;
}

// The feed's poster URLs ask its image CDN for 1080×1620 — several times
// larger than any card on this site shows, and every visitor would download
// that full size. 600×900 keeps the 2:3 shape and stays sharp on a
// high-density screen (TMDB's own posters here are 300 wide).
function alamo_poster_url($uri) {
    if (!$uri) return null;
    $uri = preg_replace('/([?&])h=\d+/', '${1}h=900', $uri);
    return preg_replace('/([?&])w=\d+/', '${1}w=600', $uri);
}

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
        // Ubuntu 22.04 ships curl 7.81 with nghttp2 1.43, which cannot complete
        // an HTTP/2 handshake with Cloudflare here — every request died with
        // "stream 0 was not closed cleanly: PROTOCOL_ERROR" and curl_exec
        // returned false, so the scraper silently produced nothing. A newer
        // curl on the development machine negotiated it fine, which is why this
        // only appeared on deploy. HTTP/1.1 is unaffected and just as fast.
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
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

        // The event's own copy (each installment of a monthly series has its
        // own description and poster) beats the show-level one shared by all
        // of them; the tagline stands in for a missing description.
        $event = (array)($p['event'] ?? []);
        $show  = (array)($p['show']  ?? []);
        $overview = alamo_plain_text($event['description'] ?? '');
        if ($overview === '') $overview = alamo_plain_text($event['headline'] ?? '');
        if ($overview === '') $overview = alamo_plain_text($show['headline'] ?? '');
        $poster = $event['posterImage']['uri'] ?? ($show['posterImages'][0]['uri'] ?? null);

        $keep[$p['slug']] = [
            'title'    => $title,
            'poster'   => alamo_poster_url($poster),
            'runtime'  => !empty($event['runtimeMinutes']) ? (int)$event['runtimeMinutes'] : null,
            'overview' => $overview !== '' ? $overview : null,
            'mystery'  => alamo_is_mystery($p, $title),
        ];
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
            'title'        => $keep[$slug]['title'],
            'url'          => 'https://drafthouse.com/austin/show/' . rawurlencode($slug),
            'timestamp'    => $ts,
            'display_date' => (new DateTime('@' . $ts))->setTimezone($tz)->format('D, M j · g:ia'),
            'location'     => $cinemas[$s['cinemaId'] ?? ''] ?? '',
            'alamo_poster'   => $keep[$slug]['poster'],
            'alamo_runtime'  => $keep[$slug]['runtime'],
            'alamo_overview' => $keep[$slug]['overview'],
        ] + (!$keep[$slug]['mystery'] ? [] : [
            // Alamo's copy stands in for TMDB's outright, and 'no_tmdb' keeps
            // any lookup from overwriting it (see alamo_is_mystery()) — the
            // same contract AFS's shorts programs use in scraper_afs.php.
            'no_tmdb'  => true,
            'poster'   => $keep[$slug]['poster'],
            'director' => null,
            'runtime'  => $keep[$slug]['runtime'],
            'overview' => $keep[$slug]['overview'],
        ]);
    }

    return $films;
}
