<?php
require_once __DIR__ . '/cache.php';

// ═══════════════════════════════════════════════════════════════════════════
//  Fathom Events — Austin metro
//
//  Fathom is a distributor rather than a cinema: it books a title into every
//  chain at once for one or two dates. So a single booking arrives as six or
//  seven near-identical rows — Willy Wonka at 4:00pm on one Sunday, at Regal
//  Metropolitan and Cinemark Southpark and four others. That is exactly the
//  shape ctx_fold_venue() exists for, so this registers as one venue and folds
//  into a single card per day.
//
//  Finding the showtimes took some digging. The public endpoints are:
//
//    GET /api/events                                  every event, dates only
//    GET /api/events/nearme?lat&lng                   event ids near a point
//    GET /api/events/showtimes?lat&lng&eventID&maxTheaters
//
//  The last one is the whole game and is easy to miss: omit maxTheaters and it
//  404s, because ASP.NET matches a route on its full parameter set rather than
//  treating the extra one as optional. The API is ASP.NET Web API, so /Help
//  lists every route — that is how the signature was found.
//
//  It costs one request for the id list plus one per event, about 12 seconds
//  in total. Cached for six hours and driven by the warmer, so no page request
//  ever pays for it.
// ═══════════════════════════════════════════════════════════════════════════

const FATHOM_API   = 'https://api.fathomentertainment.com/api';
const FATHOM_LAT   = 30.2672;   // downtown Austin
const FATHOM_LNG   = -97.7431;

// Far enough for Round Rock, Pflugerville and Cedar Park, which is the metro
// people will actually drive across; short of San Antonio, which the feed will
// happily offer.
const FATHOM_MILES = 25;

// The API caps this itself; it only has to be larger than the number of
// cinemas within FATHOM_MILES.
const FATHOM_MAX_THEATERS = 25;

function fetch_fathom_films($force = false) {
    return ctx_cached_scrape('cache_fathom.json', 6 * 3600, 'fetch_fathom_films_scrape', $force);
}

function fathom_get($path) {
    $ch = curl_init(FATHOM_API . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; CinemaTX/1.0; +https://cinematx.net)',
        CURLOPT_TIMEOUT        => 20,
        // Not strictly needed here, but the Alamo feed failed on production
        // with an HTTP/2 PROTOCOL_ERROR that never appeared in development.
        // Pinned everywhere now rather than waiting to be surprised twice.
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return $body ? json_decode($body, true) : null;
}

function fetch_fathom_films_scrape() {
    // Titles and slugs live on the full event list; the location endpoints
    // return bare ids.
    $catalogue = fathom_get('/events');
    if (empty($catalogue['Events'])) return [];

    $titles = [];
    foreach ($catalogue['Events'] as $e) {
        if (isset($e['EventID'])) $titles[$e['EventID']] = trim((string)($e['Title'] ?? ''));
    }

    $near = fathom_get('/events/nearme?lat=' . FATHOM_LAT . '&lng=' . FATHOM_LNG);
    if (!is_array($near) || !$near) return [];

    $tz    = new DateTimeZone('America/Chicago');
    $films = [];

    foreach ($near as $id) {
        $id = (int)$id;
        $title = $titles[$id] ?? '';
        if ($title === '') continue;

        $days = fathom_get('/events/showtimes?lat=' . FATHOM_LAT . '&lng=' . FATHOM_LNG
                         . '&eventID=' . $id . '&maxTheaters=' . FATHOM_MAX_THEATERS);
        if (!is_array($days)) continue;

        foreach ($days as $day) {
            $date = $day['Date'] ?? null;
            if (!$date) continue;

            foreach ((array)($day['Theaters'] ?? []) as $th) {
                // The feed reaches well past the metro; Distance is how we
                // draw the line, rather than guessing from city names.
                if (($th['Distance'] ?? 9999) > FATHOM_MILES) continue;

                foreach ((array)($th['Showtimes'] ?? []) as $s) {
                    if (empty($s['Time'])) continue;
                    try {
                        $ts = (new DateTime($date . ' ' . $s['Time'], $tz))->getTimestamp();
                    } catch (Exception $e) {
                        continue;
                    }

                    $films[] = [
                        'title'        => $title,
                        // Straight to the seat rather than to a marketing page:
                        // Fathom's own release pages do not list local times.
                        'url'          => $s['PurchaseURL'] ?? 'https://www.fathomentertainment.com/',
                        'timestamp'    => $ts,
                        'display_date' => (new DateTime('@' . $ts))->setTimezone($tz)->format('D, M j · g:ia'),
                        'location'     => trim((string)($th['TheaterName'] ?? '')),
                    ];
                }
            }
        }
    }

    return $films;
}
