<?php
// ═══════════════════════════════════════════════════════════════════════════
//  TMDB lookup — poster, year, runtime, and the hover-card copy
//
//  Two calls per film, both cached forever under the title|year key:
//
//    /search/movie                       poster_path, release_date, id
//    /movie/{id}?append_to_response=…    runtime, overview, genres, director,
//                                        and the film's Wikidata id
//    wikidata wbgetentities              that id's English Wikipedia article
//
//  Runtime is the only field that needs the second call; everything after it
//  rides along on that same request for free, credits and external ids
//  included. The year and the plot were both fetched and thrown away before.
//
//  The Wikipedia link is resolved by id rather than by searching Wikipedia for
//  the title. Title search would be a second independent chance to land on the
//  wrong film — the failure that put a 45-minute documentary behind "Titanic
//  in 3D". Going through Wikidata makes the link exactly as right as the TMDB
//  match and never independently wrong.
//
//  Entries carry a version. Bump TMDB_CACHE_V whenever a field is added and
//  the next read refetches rather than serving a row that predates it.
// ═══════════════════════════════════════════════════════════════════════════

const TMDB_CACHE_V = 6;

const TMDB_EMPTY = [
    'v'        => TMDB_CACHE_V,
    'id'       => null,
    'poster'   => null,
    'year'     => null,
    'runtime'  => null,
    'overview' => null,
    'genres'   => null,
    'director' => null,
    'cast'     => null,
    'wiki'     => null,
];

// Exhibition format is how a venue is showing a film, never part of its title,
// but TMDB will happily match it against one — "Titanic in 3D" returns
// "Titanic: 100 Years in 3D", a 45-minute documentary, instead of the film.
// Stripped from the search query only; listings still show what the venue
// called it. Anchored to the end so a real title is never cut short.
const TMDB_FORMATS = '/\s+(?:in|on)?\s*(?:3-?D|IMAX|4K(?:\s+restorations?)?|70\s?mm|35\s?mm|16\s?mm|DCP)\s*$/i';

function tmdb_query($title) {
    $q = trim(preg_replace(TMDB_FORMATS, '', trim((string)$title)));
    return $q !== '' ? $q : trim((string)$title);
}

// A handful of titles are shared by several unrelated films, at a venue
// whose listing carries no year or director to break the tie the way
// AFS's own screening page can (see scraper_afs.php's director_hint) —
// tmdb_best() then falls back to whichever same-titled film has the
// highest generic TMDB "popularity", which has nothing to do with which
// one is actually screening, and isn't even stable over time (production
// and local scraped this file's own first entry weeks apart and landed on
// two different wrong-vs-right films as popularity drifted). Curated by
// hand as they're found — the same role a scraped director_hint plays
// automatically, just supplied here instead.
const TMDB_KNOWN_DIRECTOR_HINTS = [
    // "Memento Mori" (1999, Kim Tae-yong & Min Kyu-dong) — the Korean
    // Whispering Corridors sequel. TMDB has a dozen unrelated films with
    // this exact title across many countries and years; popularity
    // ordering picked a 2022 Spanish short instead.
    'memento mori' => 'Kim Tae-yong',
];

function tmdb_known_director_hint($title) {
    $key = trim(mb_strtolower((string) $title));
    return TMDB_KNOWN_DIRECTOR_HINTS[$key] ?? null;
}

/**
 * Pick the right film from a search response.
 *
 * TMDB orders by its own relevance, which is usually right and occasionally
 * badly wrong: "Willy Wonka and the Chocolate Factory" returned the 2017 Tom
 * and Jerry crossover ahead of the 1971 film, because the distributor writes
 * "and" where TMDB has "&". "MIRROR" returned Mirror Mirror (2012) ahead of
 * Tarkovsky.
 *
 * So: if any result's title matches the query exactly once punctuation and
 * ampersands are normalised away, prefer those, most popular first. Otherwise
 * keep TMDB's own ordering — a query with no exact match is one where its
 * relevance ranking is the best signal available, and sorting everything by
 * popularity would hand obscure searches to whatever blockbuster shares a word.
 *
 * Popularity itself can pick wrong between two exact matches, though:
 * "MEANTIME" matched a 2022 short over the real 1983 Mike Leigh film playing
 * that night, because the short's TMDB popularity edged out the real one's
 * (2.2 vs 1.7 — both are "Meantime", nothing else in the search response
 * tells them apart). When the caller knows who actually directed the film
 * (AFS's own screening page says so — see scraper_afs.php), a genuine
 * ambiguity like that is checked against each exact match's real director
 * before falling back to popularity.
 */
function tmdb_best(array $results, $query, $director_hint = null) {
    if (!$results) return null;

    $norm = function ($s) {
        $s = strtolower(str_replace('&', ' and ', (string)$s));
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', $s)));
    };
    $q = $norm($query);

    $exact = [];
    foreach ($results as $r) {
        if ($norm($r['title'] ?? '') === $q) $exact[] = $r;
    }
    if (!$exact) return $results[0];

    usort($exact, fn($a, $b) => ($b['popularity'] ?? 0) <=> ($a['popularity'] ?? 0));

    if (count($exact) > 1 && $director_hint) {
        $want = trim(mb_strtolower($director_hint));
        foreach ($exact as $candidate) {
            if (empty($candidate['id'])) continue;
            $have = tmdb_movie_director((int)$candidate['id']);
            if ($have && trim(mb_strtolower($have)) === $want) return $candidate;
        }
    }

    return $exact[0];
}

/** Just the director, for disambiguating between same-titled search results
 *  — a much smaller request than the full detail fetch fetch_tmdb() makes
 *  once a match is actually chosen. */
function tmdb_movie_director($id) {
    $credits = tmdb_get('https://api.themoviedb.org/3/movie/' . $id . '/credits?api_key=' . TMDB_API_KEY);
    foreach ($credits['crew'] ?? [] as $c) {
        if (($c['job'] ?? '') === 'Director' && !empty($c['name'])) return $c['name'];
    }
    return null;
}

function tmdb_get($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        // Wikidata rejects requests without one, and it is good manners anyway.
        CURLOPT_USERAGENT      => 'CinemaTX/1.0 (+https://cinematx.net)',
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response ? json_decode($response, true) : null;
}

/**
 * Everything we know about a title. Returns the TMDB_EMPTY shape on a miss, so
 * callers can read ['year'] without checking for null first.
 *
 * $director_hint isn't part of the cache key — it only breaks a tie between
 * two exact-title matches (see tmdb_best()), so a correctly-resolved entry
 * is exactly as correct for a caller who doesn't have a hint to offer, and
 * stays cached for them too.
 */
function fetch_tmdb($title, $year = null, $director_hint = null) {
    if (!defined('TMDB_API_KEY') || !TMDB_API_KEY) return TMDB_EMPTY;

    $title      = tmdb_query($title);
    $cache_file = __DIR__ . '/cache_tmdb.json';
    $cache_key  = $title . '|' . ($year ?: '');   // normalised, so formats share an entry
    $cache      = file_exists($cache_file) ? (json_decode(file_get_contents($cache_file), true) ?: []) : [];

    // Anything older than the current shape — including the bare poster URLs
    // the very first build stored — reads as a miss.
    $have = $cache[$cache_key] ?? null;
    if (is_array($have) && ($have['v'] ?? 0) === TMDB_CACHE_V) return $have + TMDB_EMPTY;

    $search = tmdb_get(
        'https://api.themoviedb.org/3/search/movie?api_key=' . TMDB_API_KEY
        . '&query=' . urlencode($title)
        . ($year ? '&year=' . urlencode($year) : '')
    );
    if ($search === null) return TMDB_EMPTY;   // transient failure — don't cache, retry next time

    $hit = tmdb_best($search['results'] ?? [], $title, $director_hint);
    $out = TMDB_EMPTY;

    if ($hit) {
        $out['id']     = isset($hit['id']) ? (int)$hit['id'] : null;
        $out['poster'] = !empty($hit['poster_path'])
                       ? 'https://image.tmdb.org/t/p/w300' . $hit['poster_path'] : null;
        // release_date is "YYYY-MM-DD", and is sometimes an empty string.
        if (!empty($hit['release_date'])) $out['year'] = (int)substr($hit['release_date'], 0, 4);

        if ($out['id']) {
            $detail = tmdb_get('https://api.themoviedb.org/3/movie/' . $out['id']
                             . '?api_key=' . TMDB_API_KEY . '&append_to_response=credits,external_ids');

            // A runtime of 0 means TMDB has no figure, not a zero-length film.
            if (!empty($detail['runtime']))  $out['runtime']  = (int)$detail['runtime'];
            if (!empty($detail['overview'])) $out['overview'] = trim($detail['overview']);

            if (!empty($detail['genres'])) {
                $names = array_column($detail['genres'], 'name');
                // Two is enough to place a film; the full list reads as a tag dump.
                $out['genres'] = implode(', ', array_slice($names, 0, 2)) ?: null;
            }

            // Co-directors are common enough to be worth keeping both.
            $dirs = [];
            foreach ($detail['credits']['crew'] ?? [] as $c) {
                if (($c['job'] ?? '') === 'Director' && !empty($c['name'])) $dirs[] = $c['name'];
            }
            if ($dirs) $out['director'] = implode(' & ', array_slice($dirs, 0, 2));

            // TMDB already returns cast in billing order, so the first three
            // are the top-billed rather than an arbitrary three.
            if (!empty($detail['credits']['cast'])) {
                $names = array_column($detail['credits']['cast'], 'name');
                $out['cast'] = implode(', ', array_slice($names, 0, 3)) ?: null;
            }

            $out['wiki'] = wikidata_article($detail['external_ids']['wikidata_id'] ?? null);
        }
    }

    $cache[$cache_key] = $out;
    file_put_contents($cache_file, json_encode($cache));

    return $out;
}

/**
 * The English Wikipedia article for a Wikidata entity, or null.
 * Films without an English article — plenty of the repertory programme — simply
 * get no link rather than one pointing somewhere approximate.
 */
function wikidata_article($qid) {
    if (!$qid) return null;
    $d = tmdb_get('https://www.wikidata.org/w/api.php?action=wbgetentities&format=json'
                . '&props=sitelinks/urls&sitefilter=enwiki&ids=' . urlencode($qid));
    return $d['entities'][$qid]['sitelinks']['enwiki']['url'] ?? null;
}

/** Kept for callers that only want the artwork. */
function fetch_tmdb_poster($title, $year = null) {
    return fetch_tmdb($title, $year)['poster'];
}
