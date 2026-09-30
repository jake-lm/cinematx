<?php
// ═══════════════════════════════════════════════════════════════════════════
//  IMDb rating
//
//  From IMDb's own free bulk file (title.ratings.tsv.gz — every rated title,
//  about 1.7 million rows, updated daily), not their site: the site sits
//  behind a bot wall, and this file is what they publish for exactly this.
//  It is for personal and non-commercial use — revisit if the site ever
//  takes ads or payments.
//
//  The file is never loaded whole. bin/warm-cache.php scans it once for the
//  IDs of the films actually on the schedule and keeps just those (a few
//  hundred rows, ~10 KB) in cache_imdb.json; page requests read only that.
//
//  The IDs come straight from TMDB (external_ids.imdb_id), so a rating is
//  exactly as right as the TMDB match — no title matching of its own.
//
//  Downloading is a manual step for now: bin/refresh-imdb.php. Nothing here
//  fetches the file on its own.
// ═══════════════════════════════════════════════════════════════════════════

const IMDB_RATINGS_URL = 'https://datasets.imdbws.com/title.ratings.tsv.gz';

// Below this a rating is a handful of people, not a signal — a local
// premiere with six votes shouldn't read as a "9.2".
const IMDB_MIN_VOTES = 100;

function imdb_ratings_path() { return __DIR__ . '/imdb_ratings.tsv.gz'; }
function imdb_cache_path()   { return __DIR__ . '/cache_imdb.json'; }

/**
 * Downloads the ratings file. True on success, otherwise a message saying
 * why. Written beside the destination and renamed into place, so a dropped
 * connection never leaves a half-file behind.
 */
function imdb_refresh_ratings() {
    $dest = imdb_ratings_path();
    $tmp  = $dest . '.part';

    $fh = @fopen($tmp, 'wb');
    if (!$fh) return "can't write $tmp";

    $ch = curl_init(IMDB_RATINGS_URL);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_USERAGENT      => 'CinemaTX/1.0 (+https://cinematx.net)',
    ]);
    $ok   = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);

    if (!$ok || $code !== 200) { @unlink($tmp); return "download failed (HTTP $code)"; }

    $gz   = @gzopen($tmp, 'rb');
    $head = $gz ? gzgets($gz) : false;
    if ($gz) gzclose($gz);
    if (!$head || strpos($head, 'tconst') !== 0) { @unlink($tmp); return 'downloaded file is not the ratings table'; }

    rename($tmp, $dest);
    return true;
}

/**
 * The stored {imdb id => [rating, votes]} map. Pass a map to replace it.
 * Memoised per request, so rendering a few hundred cards reads the file once.
 */
function imdb_cache($replace = null) {
    static $memo = null;
    if ($replace !== null) {
        $memo = $replace;
        file_put_contents(imdb_cache_path(), json_encode($replace), LOCK_EX);
    }
    if ($memo === null) {
        $f    = imdb_cache_path();
        $memo = file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
    }
    return $memo;
}

/** The 0–10 rating for an IMDb id, or null when unrated or too few votes. */
function fetch_imdb($tt) {
    if (!$tt) return null;
    $row = imdb_cache()[$tt] ?? null;
    if (!$row || ($row[1] ?? 0) < IMDB_MIN_VOTES) return null;
    return (float)$row[0];
}

/**
 * Warmer step: pulls the given ids out of the downloaded file into the
 * small cache. Only scans when it has to — a new id it hasn't seen, or a
 * freshly downloaded file — so most 30-minute runs cost nothing.
 * Returns the number of ids found, or false when there is no file yet.
 */
function imdb_index(array $ids) {
    $file = imdb_ratings_path();
    if (!file_exists($file)) return false;

    $ids = array_values(array_unique(array_filter($ids, fn($i) => is_string($i) && preg_match('/^tt\d+$/', $i))));

    $cache_file = imdb_cache_path();
    $have       = imdb_cache();
    $stale      = filemtime($file) > (file_exists($cache_file) ? filemtime($cache_file) : 0);
    $missing    = array_diff($ids, array_keys($have));
    if (!$stale && !$missing) return count(array_filter($have));

    $gz = @gzopen($file, 'rb');
    if (!$gz) return false;

    $wanted = array_flip($ids);
    $out    = array_fill_keys($ids, null);   // null = looked up, nothing there
    $found  = 0;
    while (($line = gzgets($gz)) !== false) {
        $tab = strpos($line, "\t");
        if ($tab === false) continue;
        $id = substr($line, 0, $tab);
        if (!isset($wanted[$id])) continue;
        $p = explode("\t", rtrim($line));
        if (count($p) < 3) continue;
        $out[$id] = [(float)$p[1], (int)$p[2]];
        if (++$found === count($ids)) break;
    }
    gzclose($gz);

    imdb_cache($out);
    return $found;
}
