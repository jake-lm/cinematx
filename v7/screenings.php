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

/**
 * Fold one venue's screenings for a day into a single entry.
 *
 * Alamo is a five-location chain: it books the same film at the same minute in
 * several cinemas, so one Tuesday produced thirteen rows against six films
 * while Hyperreal produced one row per screening. Forcing both into the same
 * shape was the mistake — a chain's day is a schedule, not a listing.
 *
 * The rule is behavioural, not a name: any venue passed here that has $min or
 * more screenings in a day is folded. Below that a card would be a box drawn
 * around a single item, so those days stay ordinary rows — which is what
 * Friday, Saturday and Sunday look like.
 *
 * Returns the same flat list with the folded screenings replaced by one entry
 * carrying is_group, sorted back into chronological order by its earliest
 * showing so it sits where it belongs rather than pinned to the top.
 */
function ctx_fold_venue(array $films, $venue, $min = 3) {
    $tz = new DateTimeZone('America/Chicago');
    $out = [];
    $days = [];

    foreach ($films as $f) {
        if (($f['venue'] ?? '') !== $venue) { $out[] = $f; continue; }
        $days[(new DateTime('@' . $f['timestamp']))->setTimezone($tz)->format('Ymd')][] = $f;
    }

    foreach ($days as $day => $group) {
        if (count($group) < $min) { foreach ($group as $f) $out[] = $f; continue; }
        usort($group, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);

        // Within the day, one line per film, its showings gathered under it.
        $byFilm = [];
        foreach ($group as $f) {
            $k = $f['display_title'] ?? $f['title'];
            if (!isset($byFilm[$k])) { $byFilm[$k] = $f; $byFilm[$k]['showings'] = []; }
            $byFilm[$k]['showings'][] = ['t' => $f['timestamp'], 'loc' => $f['location'] ?? ''];
        }

        // Two or three faces for the stacked tile; skip films with no artwork
        // so the stack never shows a blank card.
        $posters = [];
        foreach ($byFilm as $f) {
            if (!empty($f['poster']) && count($posters) < 3) $posters[] = $f['poster'];
        }

        $out[] = [
            'is_group'      => true,
            'group_id'      => ctx_slug($venue) . '-' . $day,
            'venue'         => $venue,
            'location'      => null,
            'source'        => 'official',
            'timestamp'     => $group[0]['timestamp'],
            'title'         => $venue,
            'display_title' => $venue,
            'series'        => null,
            'url'           => '#',
            'films'         => array_values($byFilm),
            'n_films'       => count($byFilm),
            'n_showings'    => count($group),
            'posters'       => $posters,
            'day_label'     => (new DateTime('@' . $group[0]['timestamp']))->setTimezone($tz)->format('l j F'),
        ];
    }

    usort($out, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
    return $out;
}

/** The showings of one film inside a folded card: "10:45am Lakeline · 4:10pm Mueller". */
function ctx_showings_label(array $showings) {
    $parts = [];
    foreach ($showings as $s) {
        $parts[] = date('g:ia', $s['t']) . ($s['loc'] !== '' ? ' ' . $s['loc'] : '');
    }
    return implode(' · ', $parts);
}

/**
 * The panel a folded card opens. Markup only — the sheet, scrim, Escape and
 * click-outside behaviour already exist for the account panel, and
 * initOverlays() resolves data-open="x" to #sheet-x, so this needs no
 * JavaScript of its own.
 */
function ctx_group_sheet(array $e) {
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    ob_start(); ?>
<aside class="sheet sheet--side" id="sheet-<?php echo $h($e['group_id']); ?>">
  <div class="sheet__head">
    <span class="sheet__title"><?php echo $h($e['venue']); ?></span>
    <button class="ibtn sheet__x" data-close title="Close"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="sheet__body">
    <div class="fold__when"><?php echo $h($e['day_label']); ?></div>
    <div class="fold__count"><?php echo (int)$e['n_films']; ?> film<?php echo $e['n_films'] === 1 ? '' : 's'; ?>
      &middot; <?php echo (int)$e['n_showings']; ?> showing<?php echo $e['n_showings'] === 1 ? '' : 's'; ?></div>

    <?php foreach ($e['films'] as $f): ?>
    <a class="fold__film" href="<?php echo $h($f['url'] ?: '#'); ?>" target="_blank" rel="noopener">
      <span class="fold__art">
        <?php if (!empty($f['poster'])): ?><img src="<?php echo $h($f['poster']); ?>" alt="" loading="lazy" /><?php endif; ?>
      </span>
      <span class="fold__meta">
        <span class="fold__title"><?php echo $h($f['display_title'] ?? $f['title']); ?></span>
        <?php $bits = ctx_bits($f, false); if ($bits): ?>
        <span class="fold__bits"><?php echo $h(implode(' · ', $bits)); ?></span>
        <?php endif; ?>
        <span class="fold__times"><?php echo $h(ctx_showings_label($f['showings'])); ?></span>
      </span>
    </a>
    <?php endforeach; ?>
  </div>
</aside>
<?php return ob_get_clean();
}

/**
 * The card a folded venue-day renders as, in either view. Shared by /list/ and
 * the front page so the two cannot drift — the tile and the row are only
 * different triggers for the same panel.
 */
function ctx_fold_card($s, $view) {
    $e     = 'ctx_e';
    $label = $s['n_films'] . ' film' . ($s['n_films'] === 1 ? '' : 's');
    $sub   = $s['n_showings'] . ' showing' . ($s['n_showings'] === 1 ? '' : 's');
    $attrs = 'data-venue="' . $e(ctx_slug($s['venue'])) . '" data-source="venue"'
           . ' data-count="' . (int)$s['n_showings'] . '"'
           . ' data-open="' . $e($s['group_id']) . '"';

    if ($view === 'grid') { ?>
      <a class="shot shot--fold" <?php echo $attrs; ?> href="#">
        <span class="shot__art fold__stack">
          <?php // Two or three faces, back to front, so the offset reads as a
                // pile of films rather than one card with a heavy border. ?>
          <?php foreach (array_reverse($s['posters']) as $i => $p): ?>
          <img class="fold__face fold__face--<?php echo count($s['posters']) - $i; ?>" src="<?php echo $e($p); ?>" alt="" />
          <?php endforeach; ?>
          <span class="shot__time"><?php echo $e($label); ?></span>
        </span>
        <span class="shot__title"><?php echo $e(ctx_venue_short($s['venue'])); ?><span class="shot__series"><?php echo $e($sub); ?></span></span>
        <span class="shot__venue"><?php echo date('D j M', $s['timestamp']); ?> &middot; tap for times</span>
      </a>
    <?php } else { ?>
      <a class="line line--fold" <?php echo $attrs; ?> href="#">
        <span class="line__time"><?php echo date('g:ia', $s['timestamp']); ?></span>
        <span>
          <span class="line__title"><?php echo $e($s['venue']); ?><span class="line__series"><?php echo $e($label); ?></span></span>
          <span class="line__sub"><?php echo $e($sub); ?> across five cinemas &middot; tap for times</span>
        </span>
        <span class="line__venue"><?php echo date('D', $s['timestamp']); ?></span>
      </a>
    <?php }
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
