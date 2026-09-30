<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Rotten Tomatoes audience score (the Popcornmeter)
//
//  Not an API — RT's is a paid partner licence. This finds a film's RT page
//  through Wikidata instead of guessing a slug from the title: Wikidata holds
//  RT's own path for a film (property P1258, "m/dazed_and_confused"), and
//  list/tmdb.php already carries each match's Wikidata id. That makes the
//  page exactly as right as the TMDB match and never independently wrong —
//  same reasoning as the Wikipedia link. Both scores are embedded in that
//  page's HTML as JSON, so a plain GET is enough. The critics Tomatometer is
//  stored too since it comes free with the same fetch, but only the audience
//  score is shown — see fetch_rt().
//
//  Plenty of films never get a score: no Wikidata entry, no RT id on it, or
//  an RT page with no audience ratings yet. Indie and local screenings are mostly
//  this. Those return null and the hovercard just omits the line.
//
//  Live fetching is opt-in (CTX_RT_LIVE, set by bin/warm-cache.php only). A
//  page request only ever reads what the warmer already stored, so a cold
//  cache costs a blank score, never a slow page.
// ═══════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/tmdb.php';

// Scores drift as reviews come in, and a film with no slug may gain one on
// Wikidata later, so misses are re-checked on the same clock.
const RT_TTL = 7 * 86400;

// Per warm run. Everything expires together a week after the first fill, and
// the warmer runs every 30 minutes — capping each run spreads that out
// rather than sending a couple of hundred requests to RT in one burst.
const RT_MAX_PER_RUN = 40;

function rt_http($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; CinemaTX/1.0)',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body ?: null];
}

/**
 * RT's path for a Wikidata film ("m/terminator_2_judgment_day"), null when
 * Wikidata has none, false when Wikidata itself couldn't be reached — the
 * caller must not cache that as a miss.
 */
function rt_slug_from_wikidata($qid) {
    $d = tmdb_get('https://www.wikidata.org/w/api.php?action=wbgetclaims&format=json&property=P1258&entity=' . urlencode($qid));
    if ($d === null) return false;

    $best = null;
    foreach ($d['claims']['P1258'] ?? [] as $claim) {
        $rank = $claim['rank'] ?? 'normal';
        $val  = $claim['mainsnak']['datavalue']['value'] ?? null;
        if ($rank === 'deprecated' || !is_string($val)) continue;
        // Movies only. A TV path here would be a different page shape.
        if (!preg_match('#^m/[a-z0-9_]+$#', $val)) continue;
        if ($rank === 'preferred') return $val;
        $best = $best ?? $val;
    }
    return $best;
}

/**
 * Both scores on an RT page, each null when the page has none yet.
 * The audience score is pinned to scoreType ALL: newer pages also carry a
 * VERIFIED-audience variant, and ALL is the Popcornmeter RT leads with.
 */
function rt_parse_scores($html) {
    $pick = function ($re) use ($html) {
        if (!preg_match($re, $html, $m)) return null;
        $n = (int)$m[1];
        return $n >= 0 && $n <= 100 ? $n : null;
    };
    return [
        'critics'  => $pick('/"criticsScore":\{[^}]*?"score":"(\d{1,3})"/'),
        'audience' => $pick('/"audienceScore":\{[^}]*?"score":"(\d{1,3})","scoreType":"ALL"/'),
    ];
}

/**
 * The audience score (0–100) for a Wikidata film id, or null.
 * Serves the cache, stale or not, unless the warmer has switched live
 * fetching on.
 */
function fetch_rt($qid) {
    static $cache = null, $fetched = 0, $blocked = false;
    if (!$qid) return null;

    $file = __DIR__ . '/cache_rt.json';
    if ($cache === null) {
        $cache = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    }

    $have  = $cache[$qid] ?? null;
    // An entry that has a page but no 'audience' key predates the swap from
    // critics to audience — stale however recent, so it's fetched again.
    $fresh = $have && (time() - (int)($have['at'] ?? 0)) < RT_TTL
          && (empty($have['slug']) || array_key_exists('audience', $have));
    $live  = defined('CTX_RT_LIVE') && CTX_RT_LIVE;
    if ($fresh || !$live || $blocked || $fetched >= RT_MAX_PER_RUN) return $have['audience'] ?? null;

    $fetched++;
    $slug = $have['slug'] ?? null;
    if (!$slug) {
        $slug = rt_slug_from_wikidata($qid);
        if ($slug === false) return $have['audience'] ?? null;   // transient — retry next run
    }

    $entry = ['slug' => $slug, 'score' => null, 'audience' => null, 'at' => time()];
    if ($slug) {
        usleep(300000);
        [$code, $html] = rt_http('https://www.rottentomatoes.com/' . $slug);
        if ($code === 200 && $html) {
            // A live page that suddenly parses to nothing is more likely
            // RT's markup moving than a film losing its ratings — keep the
            // scores we had rather than blank them.
            $got = rt_parse_scores($html);
            $entry['score']    = $got['critics']  ?? ($have['score']    ?? null);
            $entry['audience'] = $got['audience'] ?? ($have['audience'] ?? null);
        } elseif ($code === 404) {
            $entry['slug'] = null;   // stale id — re-resolve through Wikidata next time
        } else {
            // Blocked, rate-limited or down. Stop for this run rather than
            // keep knocking, and keep whatever score we already had.
            $blocked = true;
            return $have['audience'] ?? null;
        }
    }

    $cache[$qid] = $entry;
    file_put_contents($file, json_encode($cache), LOCK_EX);
    return $entry['audience'];
}
