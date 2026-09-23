<?php
require_once __DIR__ . '/cache.php';

// The Paramount's year-round film programming. This used to scrape one
// season's own page (the 2026 Summer Classic Film Series), which meant
// every screening vanished the day that series ended. The site is
// WordPress, and its own `event` post type is the whole calendar — film,
// comedy, concerts — with exact per-showing timestamps, a real poster and
// the venue/format tags, all as JSON. Far sturdier than the card markup,
// which also renders a multi-night run ("Sep 28 - Sep 29") with no time at
// all, so the old parser dropped those silently.
const PARAMOUNT_FEED     = 'https://www.austintheatre.org/wp-json/wp/v2/event';
const PARAMOUNT_LISTING  = 'https://www.austintheatre.org/classic-film/';
const PARAMOUNT_BULLOCK  = 'https://www.austintheatre.org/imax-screenings-at-the-bullock-theatre/';
const PARAMOUNT_TICKETS  = 'https://tickets.austintheatre.org/';
const PARAMOUNT_TYPE_FILM = 2;
const PARAMOUNT_TYPE_PERFORMANCE = 4;

function fetch_paramount_films($force = false) {
    return ctx_cached_scrape('cache_paramount.json', 6 * 3600, 'fetch_paramount_films_scrape', $force);
}

function paramount_http($url) {
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

// Every event of one type, newest-published first. The feed keeps past
// events too, so a page is only worth asking for while the last one came
// back full.
function paramount_events($typeId, $maxPages = 3) {
    $out = [];
    for ($page = 1; $page <= $maxPages; $page++) {
        $body = paramount_http(PARAMOUNT_FEED . '?event-type=' . $typeId
            . '&per_page=100&page=' . $page . '&orderby=date&_fields=id,slug,title,link,acf');
        $batch = $body ? json_decode($body, true) : null;
        if (!is_array($batch) || !$batch || isset($batch['code'])) break;
        $out = array_merge($out, $batch);
        if (count($batch) < 100) break;
    }
    return $out;
}

// The production-season ids of everything on the Classic Film page. That
// page is the theatre's own curated film program, and it includes a few
// things the feed files under "Performance" instead of "Film" — Scream with
// David Arquette, a live conversation at Bass Concert Hall — whose card
// link ends in the same id the feed carries as event_production_season_id.
function paramount_listing_ids() {
    $html = paramount_http(PARAMOUNT_LISTING);
    if (!$html) return [];

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $ids = [];
    $links = $xpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," filmCard ")]//a[contains(@class,"links__overlay")]');
    foreach ($links as $a) {
        if (preg_match('#/(\d+)/?$#', $a->getAttribute('href'), $m)) $ids[$m[1]] = true;
    }
    return $ids;
}

// The production-season ids of every film currently shown at the Bullock
// Museum IMAX — a second curated page, same reasoning as
// paramount_listing_ids(). This is what actually resolves the Bullock
// location now: the feed's own "In IMAX" tag is applied inconsistently
// (missing on every RyMAX title — La La Land, Blade Runner 2049, Barbie,
// Project Hail Mary — while present on It and Sinners), and the ticket
// page that would otherwise say so outright is unreachable from here (see
// paramount_detail() below).
function paramount_bullock_ids() {
    $html = paramount_http(PARAMOUNT_BULLOCK);
    if (!$html) return [];

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $ids = [];
    $links = $xpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," filmCard ")]//a[contains(@class,"links__overlay")]');
    foreach ($links as $a) {
        if (preg_match('#/(\d+)/?$#', $a->getAttribute('href'), $m)) $ids[$m[1]] = true;
    }
    return $ids;
}

// One event's own ticketing page — server-rendered, and the only place the
// theatre says which room a show is in, and (per film, double features
// included) the year, runtime, director and synopsis: "(1980, 95min/color,
// DCP) … Directed by Sean S. Cunningham." followed by the blurb. The feed
// carries none of that, and TMDB alone guesses wrong on a bare title —
// "Friday the 13th" resolves to the 2009 remake, "Dracula" and
// "Frankenstein" to 2025 films, when the theatre is showing the originals.
// Cached per production season, refreshed every few days rather than
// forever: unlike a finished AFS screening, a ticket page still gets edited.
//
// As of 2026-09, tickets.austintheatre.org sits behind Incapsula, and it
// bot-walls this server's own IP outright — every request comes back a
// ~1KB JS-challenge page, 100% of the time, confirmed against several
// different screenings. A browser or a residential IP still gets the real
// page (that's how this function was built and verified in the first
// place); this box just can't reach it. So this stays in place rather
// than being ripped out — it costs nothing to keep trying, and it starts
// working again the moment that wall lifts, with no further changes — but
// it currently contributes nothing on the live site. Venue now leans on
// paramount_bullock_ids()/tags instead (see fetch_paramount_films_scrape());
// there is no working substitute yet for the year/runtime/director/
// synopsis this would otherwise supply.
function paramount_detail($season) {
    if ($season === '') return [];

    $file  = __DIR__ . '/cache_paramount_detail.json';
    $cache = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    $have  = $cache[$season] ?? null;
    if ($have && (time() - ($have['at'] ?? 0)) < 3 * 86400) return $have;

    $html = paramount_http(PARAMOUNT_TICKETS . $season);

    // A block page is short and never contains the real page's wrapper —
    // treated the same as a curl failure (not cached), since caching it
    // would read as "confirmed no data" and make a real parse unreachable
    // for the next 3 days even on a request that would have gone through.
    if (!$html || strpos($html, 'tn-event-detail') === false) return $have ?: [];

    $detail = paramount_parse_detail($html) + ['at' => time()];
    $cache[$season] = $detail;
    file_put_contents($file, json_encode($cache));
    return $detail;
}

// The description block as plain lines. It is malformed markup (a <p> inside
// a <p>, comments around template leftovers), so this works on text rather
// than a DOM.
function paramount_parse_detail($html) {
    if (!preg_match('/tn-event-detail__description">(.*)/s', $html, $m)) return [];

    $body = $m[1];
    $end  = strlen($body);
    foreach (['<span class="sr-only"', '<form ', 'tn-ticket-selector'] as $marker) {
        $pos = strpos($body, $marker);
        if ($pos !== false && $pos < $end) $end = $pos;
    }
    $body = substr($body, 0, $end);
    $body = preg_replace('/<!--.*?-->/s', '', $body);
    $body = preg_replace('#<(script|style|iframe)\b.*?</\1>#is', '', $body);
    $body = preg_replace('#<br\s*/?>|</?(?:p|div|h\d)\b[^>]*>#i', "\n", $body);
    $text = str_replace("\xc2\xa0", ' ', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    $lines = [];
    foreach (explode("\n", $text) as $l) {
        $l = trim(preg_replace('/\s+/', ' ', $l));
        if ($l !== '') $lines[] = $l;
    }
    if (!$lines) return [];

    $head = implode("\n", array_slice($lines, 0, 25));
    $venue = null;
    if (stripos($head, 'Bullock Museum IMAX') !== false)  $venue = 'Bullock IMAX';
    elseif (stripos($head, 'Bass Concert Hall') !== false) $venue = 'Bass Concert Hall';
    elseif (stripos($head, 'The State Theatre') !== false) $venue = 'State Theatre';

    // A film block is "(year, NNmin/color[/language], format) cast. Directed
    // by X." with the film's own name on the line above — or two above, when
    // an "Nth Anniversary!" banner sits between. A single-film page often
    // has no name line at all (a showtime line sits there instead).
    $films = [];
    foreach ($lines as $i => $line) {
        if (!preg_match('/^\(\s*((?:19|20)\d\d)\s*,\s*(\d+)\s*min\b[^)]*\)\s*(.*)$/i', $line, $mm)) continue;

        $name = null;
        for ($j = $i - 1, $tries = 0; $j >= 0 && $tries < 2; $j--, $tries++) {
            $cand = $lines[$j];
            if (preg_match('/anniversary|restoration|premiere|!$/i', $cand)) continue;
            if (!preg_match('/^(?:doors|film|show|member)\b|\d\s?[ap]m\b/i', $cand)) $name = $cand;
            break;
        }

        $director = preg_match('/Directed by (.+?)\.?$/i', $mm[3], $dm) ? trim($dm[1]) : null;
        $blurb    = $lines[$i + 1] ?? '';
        if (strlen($blurb) < 60 || strpos($blurb, '(') === 0) $blurb = '';

        $films[] = [
            'name'     => $name,
            'year'     => (int)$mm[1],
            'runtime'  => (int)$mm[2],
            'director' => $director,
            'overview' => $blurb ?: null,
        ];
    }

    // Anything without a film block (a live conversation, a movie-riffing
    // show) still has a description worth having — its first real paragraph.
    $overview = null;
    foreach ($lines as $l) {
        if (strlen($l) > 120 && strpos($l, '(') !== 0 && !preg_match('/^(?:member benefits|for more information|paramount member presale)/i', $l)) {
            $overview = $l;
            break;
        }
    }

    return ['venue' => $venue, 'films' => $films, 'overview' => $overview];
}

function paramount_norm($s) {
    $s = preg_replace('/\s*\((?:19|20)\d\d\)\s*$/', '', (string)$s);
    return preg_replace('/[^a-z0-9]+/', '', strtolower($s));
}

// Which of a page's film blocks belongs to this event. A double feature
// carries two, named; a single-film page carries one, often unnamed.
function paramount_pick_film(array $films, $title) {
    if (!$films) return null;
    $want = paramount_norm($title);
    foreach ($films as $f) {
        if ($f['name'] !== null && paramount_norm($f['name']) === $want) return $f;
    }
    foreach ($films as $f) {
        $n = $f['name'] !== null ? paramount_norm($f['name']) : '';
        if ($n !== '' && $want !== '' && (strpos($want, $n) !== false || strpos($n, $want) !== false)) return $f;
    }
    return count($films) === 1 ? $films[0] : null;
}

function fetch_paramount_films_scrape() {
    $events = paramount_events(PARAMOUNT_TYPE_FILM);

    $seasonOf = fn($e) => (string)($e['acf']['event_tessitura_data']['event_production_season_id'] ?? '');
    $have = [];
    foreach ($events as $e) $have[$seasonOf($e)] = true;

    $wanted = array_diff_key(paramount_listing_ids(), $have);
    if ($wanted) {
        foreach (paramount_events(PARAMOUNT_TYPE_PERFORMANCE) as $e) {
            if (isset($wanted[$seasonOf($e)])) $events[] = $e;
        }
    }

    $bullockIds = paramount_bullock_ids();

    $tz    = new DateTimeZone('America/Chicago');
    $films = [];

    foreach ($events as $e) {
        $acf   = $e['acf'] ?? [];
        $title = trim(html_entity_decode(strip_tags($e['title']['rendered'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($title === '') continue;

        $season = $seasonOf($e);
        $url    = $season !== '' ? PARAMOUNT_TICKETS . $season : ($e['link'] ?? null);

        // No point fetching the ticket page of something already over.
        $upcoming = false;
        foreach (($acf['event_performances'] ?? []) as $p) {
            if (strtotime($p['event_performance_time'] ?? '') > time() - 86400) $upcoming = true;
        }
        $detail = $upcoming ? paramount_detail($season) : [];
        $film   = paramount_pick_film($detail['films'] ?? [], $title);

        $tags = [];
        foreach (($acf['event_tags'] ?? []) as $t) $tags[$t['slug'] ?? ''] = true;

        // Which room. The ticket page itself would say outright, but is
        // unreachable from here (see paramount_detail()); its answer is
        // kept first in line for whenever that changes. The Bullock listing
        // page is the reliable signal in the meantime — the feed's own
        // "In IMAX" tag misses every RyMAX title (La La Land, Blade Runner
        // 2049, Barbie, Project Hail Mary), so it's only the last resort.
        $location = $detail['venue'] ?? null;
        if (!$location && isset($bullockIds[$season])) $location = 'Bullock IMAX';
        if (!$location) {
            if (isset($tags['in-imax']))                $location = 'Bullock IMAX';
            elseif (isset($tags['bass-concert-hall']))  $location = 'Bass Concert Hall';
        }
        // The State Theatre is the Paramount's own default room — naming it
        // would only read "Paramount Theatre — State Theatre" and crowd
        // the director off a compact row. Only a show elsewhere says where.
        if ($location === 'State Theatre') $location = null;

        // Worth telling someone before they show up: one ticket covers both
        // halves of a double feature, and a print format is the whole point
        // of going for some of these.
        $billing = [];
        if (isset($tags['double-feature']) || isset($tags['35mm-double-feature'])) $billing[] = 'Double feature';
        if (isset($tags['35mm']) || isset($tags['in-35mm']) || isset($tags['35mm-double-feature'])) $billing[] = '35mm';
        if (isset($tags['70mm'])) $billing[] = '70mm';

        $poster = $acf['event_poster_image']['url'] ?? '';
        if (!$poster) $poster = $acf['event_image']['url'] ?? '';

        foreach (($acf['event_performances'] ?? []) as $p) {
            $when = $p['event_performance_time'] ?? '';
            $dt   = $when ? DateTime::createFromFormat('Y-m-d H:i:s', $when, $tz) : false;
            if (!$dt) continue;
            $timestamp = $dt->getTimestamp();

            $films[] = [
                'title'            => $title,
                'url'              => $url,
                'timestamp'        => $timestamp,
                'display_date'     => $dt->format('D, M j · g:ia'),
                'location'         => $location,
                'paramount_poster' => $poster ?: null,
                'year_hint'        => $film['year'] ?? null,
                'paramount_year'     => $film['year'] ?? null,
                'paramount_runtime'  => $film['runtime'] ?? null,
                'paramount_director' => $film['director'] ?? null,
                'paramount_overview' => ($film['overview'] ?? null) ?: ($detail['overview'] ?? null),
                'tag_billing'      => $billing ? implode(' · ', $billing) : null,
            ];
        }
    }

    usort($films, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
    return $films;
}
