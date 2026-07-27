<?php
// ═══════════════════════════════════════════════════════════════════════════
//  v7 — screening enrichment
//
//  Venue listings arrive with the series name and assorted annotations baked
//  into the title, which is why four of thirty-seven screenings had no poster:
//
//     Discovery Zone: MIRACLE MILE
//     PHAT GIRLZ (20th Anniversary)
//     I AM BECAUSE WE ARE (Short Film Program)
//     TENDER MERCIES ft. "Welcome to Dollar Country" presented with…
//
//  Pulling those apart fixes the poster lookup AND recovers the series as
//  real metadata, which is a browsing axis worth having.
//
//  Cleaning is only ever used as a FALLBACK — the raw title is tried first,
//  so a film genuinely called "Something: Something" is never mangled.
// ═══════════════════════════════════════════════════════════════════════════

// Phrases venues use to bolt extra billing onto a title.
const CTX_CONNECTIVES = '/\s+(?:ft\.|feat\.|featuring|presented\s+with|presented\s+by|in\s+person|with\s+director|w\/)\s+.*$/i';

/** The series/programme a screening belongs to, if the title carries one. */
function ctx_series($raw) {
    $raw = trim((string)$raw);
    // "Discovery Zone: MIRACLE MILE" — but not "Blade Runner 2049: The Final Cut"
    // style suffixes, so require the left side to be short and the right non-empty.
    if (preg_match('/^(.{3,34}?):\s*(\S.*)$/u', $raw, $m)) {
        $series = trim($m[1]);
        // A number-only or single-word-of-digits prefix is not a series.
        if ($series !== '' && !preg_match('/^\d+$/', $series)) return $series;
    }
    return null;
}

/** Title with series prefix and trailing annotations removed. */
function ctx_clean_title($raw) {
    $t = trim((string)$raw);
    $t = preg_replace(CTX_CONNECTIVES, '', $t);              // "… ft. X"
    $t = preg_replace('/\s*\([^)]*\)\s*$/u', '', $t);        // "… (20th Anniversary)"
    if (preg_match('/^(.{3,34}?):\s*(\S.*)$/u', $t, $m)) {   // "Series: Title"
        if (!preg_match('/^\d+$/', trim($m[1]))) $t = $m[2];
    }
    return trim($t) !== '' ? trim($t) : trim((string)$raw);
}

/** A year mentioned parenthetically, e.g. "HIERARCHY (2025) presented by …". */
function ctx_year($raw) {
    if (preg_match('/\((19|20)\d{2}\)/', (string)$raw, $m)) return (int)substr($m[0], 1, 4);
    return null;
}

/**
 * Adds `series`, `display_title`, and — for entries the raw lookup missed —
 * a second-chance poster from the cleaned title.
 *
 * TMDB arbitrates the ambiguity. Colon-splitting alone is unsafe: "BLACK IS
 * BEAUTIFUL: THE KWAME BRATHWAITE STORY" is one film title, not a series and
 * a film. So a prefix is only accepted as a series when the raw title failed
 * to match and the cleaned one succeeded — meaning the prefix really was the
 * thing in the way. Titles TMDB already recognises are left untouched.
 */
function ctx_enrich(array $films) {
    foreach ($films as &$f) {
        $raw = (string)($f['title'] ?? '');
        $f['series']        = null;
        $f['display_title'] = $raw;

        if (!empty($f['poster'])) continue;              // raw matched — leave it alone
        if (!function_exists('fetch_tmdb_poster')) continue;

        $clean = ctx_clean_title($raw);
        if ($clean === '' || strcasecmp($clean, $raw) === 0) continue;

        $poster = fetch_tmdb_poster($clean, ctx_year($raw));
        if ($poster) {
            $f['poster']        = $poster;
            $f['display_title'] = $clean;
            $f['series']        = ctx_series($raw);      // now safe to trust
        }
    }
    return $films;
}

/** Distinct venues present, for the filter chips. */
function ctx_venues(array $films) {
    $v = [];
    foreach ($films as $f) if (!empty($f['venue'])) $v[$f['venue']] = true;
    $out = array_keys($v);
    sort($out);
    return $out;
}

/**
 * Short venue label for filter chips.
 * Naively stripping the trailing words turns "Austin Film Society" into
 * "Austin", which names a city rather than a cinema — so societies become
 * initials and only genuinely redundant suffixes are dropped.
 */
function ctx_venue_short($v) {
    $v = trim((string)$v);
    if (preg_match('/film society$/i', $v)) {
        $initials = '';
        foreach (preg_split('/\s+/', $v) as $w) $initials .= mb_strtoupper(mb_substr($w, 0, 1));
        return $initials;                                  // Austin Film Society → AFS
    }
    $short = trim(preg_replace('/\s*(Theatre|Theater|Cinema|Film Club)$/i', '', $v));
    return $short !== '' ? $short : $v;                    // Hyperreal Film Club → Hyperreal
}

/** Stable slug used for filter matching in the client. */
function ctx_slug($s) {
    return trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string)$s)), '-');
}
