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
        $f['year']          = $f['year']     ?? null;
        $f['runtime']       = $f['runtime']  ?? null;
        $f['overview']      = $f['overview'] ?? null;
        $f['genres']        = $f['genres']   ?? null;
        $f['director']      = $f['director'] ?? null;
        $f['wiki']          = $f['wiki']     ?? null;
        $f['location']      = $f['location'] ?? null;

        // A year printed in the listing itself is the fallback, not the
        // preference — TMDB knows the film, the venue is describing it.
        if (!$f['year']) $f['year'] = ctx_year($raw);

        if (!empty($f['poster'])) continue;              // raw matched — leave it alone
        if (!function_exists('fetch_tmdb')) continue;

        $clean = ctx_clean_title($raw);
        if ($clean === '' || strcasecmp($clean, $raw) === 0) continue;

        $tmdb = fetch_tmdb($clean, ctx_year($raw));
        if (!empty($tmdb['poster'])) {
            $f['poster']        = $tmdb['poster'];
            $f['display_title'] = $clean;
            $f['series']        = ctx_series($raw);      // now safe to trust
            // The cleaned lookup is the one that matched, so its metadata is
            // the metadata for this film.
            foreach (['year', 'runtime', 'overview', 'genres', 'director', 'wiki'] as $k) {
                if (!empty($tmdb[$k])) $f[$k] = $tmdb[$k];
            }
        }
    }
    return $films;
}

/**
 * The quiet metadata cluster under a title: year, runtime, venue.
 * Anything TMDB had no answer for is simply absent — a listing should never
 * show a dash standing in for a fact nobody has.
 */
function ctx_bits($s, $venue = true) {
    $bits = [];
    if (!empty($s['year']))    $bits[] = (string)$s['year'];
    if (!empty($s['runtime'])) $bits[] = (int)$s['runtime'] . 'm';
    if ($venue && !empty($s['venue'])) {
        // Alamo runs the same film at the same minute in five cinemas, so
        // without the location two rows are indistinguishable. Only the row
        // view gets it — a poster card has no width to spare.
        $bits[] = $s['venue'] . (empty($s['location']) ? '' : ', ' . $s['location']);
    }
    return $bits;
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
    $short = trim(preg_replace('/\s*(Theatre|Theater|Cinema|Film Club|Drafthouse)$/i', '', $v));
    return $short !== '' ? $short : $v;                    // Hyperreal Film Club → Hyperreal
}

/**
 * The `data-hover` attribute the hovercard reads, as a ready-to-echo string.
 *
 * Deliberately generic — six named slots, no notion of what a film is — so the
 * same primitive can preview a member, a job posting or an essay later without
 * the JS learning anything new. Empty slots are dropped and the card lays out
 * around whatever survives.
 *
 *   img  small artwork          title  the heading
 *   meta one quiet line         sub    a second quiet line
 *   body the paragraph          foot   the bottom rule line
 *   link {href, label} — sits opposite foot on that same rule
 */
function ctx_hover(array $slots) {
    $slots = array_filter($slots, fn($v) => $v !== null && $v !== '' && $v !== []);

    // A card that only repeats what is already on screen is worse than none,
    // so the paragraph is what earns it. No plot, no preview.
    if (empty($slots['body'])) return '';

    // htmlspecialchars rather than ctx_e(): every other function in this file
    // stands on its own, and one convenience call would tie it to _lib.php.
    $json = json_encode($slots, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return ' data-hover="' . htmlspecialchars($json, ENT_QUOTES, 'UTF-8') . '"';
}

/** The screening flavour of the above. */
function ctx_screening_hover($s) {
    $sub = array_filter([$s['director'] ?? null, $s['genres'] ?? null]);

    return ctx_hover([
        'img'   => $s['poster'] ?? null,
        'title' => $s['display_title'] ?? ($s['title'] ?? null),
        'meta'  => implode(' · ', ctx_bits($s, false)),
        'sub'   => implode(' · ', $sub),
        'body'  => $s['overview'] ?? null,
        'foot'  => trim(($s['venue'] ?? '')
                      . (empty($s['location']) ? '' : ', ' . $s['location'])
                      . (empty($s['timestamp']) ? '' : ' · ' . date('D j M, g:ia', $s['timestamp'])), ' ·'),
        'link'  => empty($s['wiki']) ? null : ['href' => $s['wiki'], 'label' => 'Wikipedia'],
    ]);
}

/** Stable slug used for filter matching in the client. */
function ctx_slug($s) {
    return trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string)$s)), '-');
}
