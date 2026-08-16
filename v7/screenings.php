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

// Phrases venues use to bolt extra billing onto a title. "THE CABINET OF DR.
// CALIGARI with live score by David DiDonato" found TMDB nothing until this
// one was added — a real, easily findable film with no poster because the
// billing (not a re-release or a presenter credit) was still attached.
const CTX_CONNECTIVES = '/\s+(?:ft\.|feat\.|featuring|presented\s+with|presented\s+by|in\s+person|with\s+director|with\s+live\s+score|w\/)\s+.*$/i';

// Re-release billing: "… 55th Anniversary", "… 20th Anniversary - Studio
// Ghibli Fest 2026". Distributors put the occasion in the title and TMDB then
// finds nothing, so Willy Wonka, Point Break and both Ghibli titles arrived
// with no poster, year or plot. The leading ordinal is required, so a film
// actually called "The Anniversary" is untouched.
const CTX_ANNIVERSARY = '/\s+\d{1,3}(?:st|nd|rd|th)\s+anniversary\b.*$/i';

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
    $t = preg_replace(CTX_ANNIVERSARY, '', $t);              // "… 35th Anniversary…"
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
 * The two kinds of bolted-on billing worth surfacing rather than just
 * discarding: a live-score accompanist, or the org a screening is
 * presented with/by. Each pattern is anchored to the end of the raw title
 * and checked independently — not tied to whichever connective
 * ctx_clean_title() actually cut on — so "TENDER MERCIES ft. "Welcome to
 * Dollar Country" presented with End of an Ear" still surfaces "Presented
 * with End of an Ear" even though "ft." is the earlier, unrelated match
 * that strips the title down to "TENDER MERCIES".
 *
 * The other connectives (ft./featuring, in person, with director, w/)
 * stay silent strips — they're either a co-billed short (already folded
 * into the title text before this point) or a Q&A appearance too minor to
 * warrant its own line.
 */
function ctx_billing($raw) {
    $raw = trim((string)$raw);
    if (preg_match('/\bwith\s+live\s+score\s+by\s+(.+)$/i', $raw, $m)) {
        return 'Live score by ' . trim($m[1]);
    }
    if (preg_match('/\bwith\s+live\s+score\s*$/i', $raw)) {
        return 'Live score';
    }
    if (preg_match('/\bpresented\s+(with|by)\s+(.+)$/i', $raw, $m)) {
        return 'Presented ' . strtolower($m[1]) . ' ' . trim($m[2]);
    }
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
        $f['billing']       = ctx_billing($raw);
        $f['display_title'] = $raw;
        $f['year']          = $f['year']     ?? null;
        $f['runtime']       = $f['runtime']  ?? null;
        $f['overview']      = $f['overview'] ?? null;
        $f['genres']        = $f['genres']   ?? null;
        $f['director']      = $f['director'] ?? null;
        $f['cast']          = $f['cast']     ?? null;
        $f['wiki']          = $f['wiki']     ?? null;
        $f['location']      = $f['location'] ?? null;

        // A year printed in the listing itself is the fallback, not the
        // preference — TMDB knows the film, the venue is describing it.
        if (!$f['year']) $f['year'] = ctx_year($raw);

        if (!empty($f['poster'])) continue;              // raw matched — leave it alone
        // A curated shorts anthology never gets this fallback lookup either
        // — the exact failure it exists to fix: "I AM BECAUSE WE ARE" cleaned
        // right down to a title TMDB confidently, wrongly matched to an
        // unrelated one-minute short. A miss on its own AFS poster fetch
        // should show no poster, not a wrong one from here instead.
        if (!empty($f['no_tmdb'])) continue;
        if (!function_exists('fetch_tmdb')) continue;

        $clean = ctx_clean_title($raw);
        if ($clean === '' || strcasecmp($clean, $raw) === 0) continue;

        $tmdb = fetch_tmdb($clean, ctx_year($raw), $f['director_hint'] ?? null);
        if (!empty($tmdb['poster'])) {
            $f['poster']        = $tmdb['poster'];
            $f['display_title'] = $clean;
            $f['series']        = ctx_series($raw);      // now safe to trust
            // The cleaned lookup is the one that matched, so its metadata is
            // the metadata for this film.
            foreach (['year', 'runtime', 'overview', 'genres', 'director', 'cast', 'wiki'] as $k) {
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
    if (!empty($s['billing'])) $bits[] = $s['billing'];
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

        // Faces for the tile, skipping films with no artwork so the stack never
        // shows a blank card. Depth follows the number of showings, not the
        // number of films — Fathom books one title into six cinemas at once, so
        // its card has a single poster and would otherwise render flat, looking
        // like an ordinary screening rather than seven of them. Where there are
        // fewer posters than showings the artwork repeats, which is what the
        // front page already does for a film running two nights.
        $art = [];
        foreach ($byFilm as $f) if (!empty($f['poster'])) $art[] = $f['poster'];

        $depth   = min(3, max(count($art), count($group)));
        $posters = [];
        for ($i = 0; $i < $depth && $art; $i++) $posters[] = $art[$i % count($art)];

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

/**
 * Fold one AFS festival's screenings for a day into a single entry — the
 * exact same shape ctx_fold_venue() produces above (group_id, films,
 * n_films, n_showings, posters, day_label, and 'venue' as the headline
 * string), so every renderer that already understands a folded card
 * (ctx_fold_card(), ctx_fold_children(), ctx_group_sheet()) works
 * unmodified: none of them know or care whether "venue" names a cinema
 * chain or a festival. Only entries carrying a detected 'festival' (see
 * afs_is_festival() in scraper_afs.php) are eligible, and — unlike
 * ctx_fold_venue() above — every detected festival folds in one pass
 * rather than needing to be named, since there's no fixed list of them.
 *
 * The threshold counts distinct FILMS, not raw showings. ctx_fold_venue()
 * can use showing-count as a stand-in for film-count because a chain
 * booking the same title into five cinemas is still "five things
 * happening" in the sense that matters there. Here it would be wrong: a
 * single popular festival film shown twice in a day must not read as
 * "3 films" when it's genuinely one.
 */
function ctx_fold_festival(array $films, $min = 3) {
    $tz   = new DateTimeZone('America/Chicago');
    $out  = [];
    $days = [];

    foreach ($films as $f) {
        $festival = $f['festival'] ?? null;
        if ($festival === null) { $out[] = $f; continue; }
        $day = (new DateTime('@' . $f['timestamp']))->setTimezone($tz)->format('Ymd');
        $days[$festival . '|' . $day][] = $f;
    }

    foreach ($days as $key => $group) {
        [$festival, $day] = explode('|', $key, 2);
        usort($group, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);

        // One line per film, its showings gathered under it — built before
        // the threshold check, since the threshold is about films, not rows.
        $byFilm = [];
        foreach ($group as $f) {
            $k = $f['display_title'] ?? $f['title'];
            if (!isset($byFilm[$k])) { $byFilm[$k] = $f; $byFilm[$k]['showings'] = []; }
            $byFilm[$k]['showings'][] = ['t' => $f['timestamp'], 'loc' => $f['location'] ?? ''];
        }

        if (count($byFilm) < $min) { foreach ($group as $f) $out[] = $f; continue; }

        $art = [];
        foreach ($byFilm as $f) if (!empty($f['poster'])) $art[] = $f['poster'];

        $depth   = min(3, max(count($art), count($group)));
        $posters = [];
        for ($i = 0; $i < $depth && $art; $i++) $posters[] = $art[$i % count($art)];

        $out[] = [
            'is_group'      => true,
            'group_id'      => ctx_slug($festival) . '-' . $day,
            'venue'         => $festival,
            'location'      => null,
            'source'        => 'official',
            'timestamp'     => $group[0]['timestamp'],
            'title'         => $festival,
            'display_title' => $festival,
            'series'        => null,
            // Carried through so ctx_fold_card() can tell this apart from an
            // ordinary Alamo/Fathom venue fold and give it the same banner
            // an unfolded festival card gets — otherwise a stack of festival
            // films looks exactly like a stack of a chain's schedule.
            'festival'      => $festival,
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

/**
 * A few of the titles inside a folded card: "Rashomon, Arco, Casualties of War
 * + 3 more". Naming them beats counting them — "6 films" tells you the size of
 * the thing, not whether you want it.
 */
function ctx_fold_titles(array $films, $show = 3) {
    $names = [];
    foreach ($films as $f) $names[] = $f['display_title'] ?? $f['title'];
    $head = array_slice($names, 0, $show);
    $rest = count($names) - count($head);
    return implode(', ', $head) . ($rest > 0 ? ' + ' . $rest . ' more' : '');
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
    // data-fold marks the collapsed card; its children carry data-unfold with
    // the same value. Narrowing to this venue swaps which of the two is shown —
    // once you have asked for Alamo, a card whose headline is "Alamo" tells you
    // nothing you did not just say.
    $attrs = 'data-venue="' . $e(ctx_slug($s['venue'])) . '" data-source="venue"'
           . ' data-count="' . (int)$s['n_showings'] . '"'
           . ' data-fold="' . $e(ctx_slug($s['venue'])) . '"'
           . ' data-open="' . $e($s['group_id']) . '"';

    if ($view === 'grid') { ?>
      <a class="shot shot--fold" <?php echo $attrs; ?> href="#">
        <span class="shot__art fold__stack">
          <?php // Same banner an unfolded festival card gets — without it,
                // a stack of festival films looks exactly like a stack of a
                // chain's schedule until you read the small text below. ?>
          <?php if (!empty($s['festival'])): ?><span class="shot__festival"><?php echo $e($s['festival']); ?></span><?php endif; ?>
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
          <span class="line__title">
            <?php if (!empty($s['festival'])): ?><i class="fa-solid fa-clapperboard line__festival-icon" title="Film festival"></i><?php endif; ?>
            <?php echo $e($s['venue']); ?><span class="line__series"><?php echo $e($label); ?></span>
          </span>
          <span class="line__sub"><?php echo $e(ctx_fold_titles($s['films'])); ?></span>
        </span>
        <span class="line__venue"><?php echo date('D', $s['timestamp']); ?></span>
      </a>
    <?php }
}

/**
 * What a folded card becomes when its venue is the one being filtered to: one
 * entry per film for that day, carrying all of its times and cinemas. Rendered
 * alongside the collapsed card and hidden until needed, so the swap is a class
 * toggle rather than a fetch.
 *
 * Per film rather than per showing — unfolding Alamo to all 39 of its
 * screenings would put back the flood this was built to remove.
 */
function ctx_fold_children($s, $view) {
    $e   = 'ctx_e';
    $key = ctx_slug($s['venue']);

    foreach ($s['films'] as $f) {
        $attrs = 'data-venue="' . $e($key) . '" data-source="venue"'
               . ' data-count="' . count($f['showings']) . '"'
               . ' data-unfold="' . $e($key) . '"'
               . ctx_screening_hover($f);
        $times = ctx_showings_label($f['showings']);
        $href  = !empty($f['url']) ? $f['url'] : '#';

        if ($view === 'grid') { ?>
      <a class="shot is-hidden" <?php echo $attrs; ?> href="<?php echo $e($href); ?>" target="_blank" rel="noopener">
        <span class="shot__art">
          <?php if (!empty($f['poster'])): ?>
          <img src="<?php echo $e($f['poster']); ?>" alt="<?php echo $e($f['display_title']); ?>" loading="lazy" />
          <?php else: ?><span class="shot__blank"><?php echo $e($f['display_title']); ?></span><?php endif; ?>
          <?php // A count only tells you something when there's more than
                // one — "1 ×" is just noise on a poster that's already,
                // visibly, one card. ?>
          <?php if (count($f['showings']) > 1): ?>
          <span class="shot__time"><?php echo count($f['showings']); ?> &times;</span>
          <?php endif; ?>
        </span>
        <span class="shot__title"><?php echo $e($f['display_title']); ?></span>
        <span class="shot__venue"><?php echo $e($times); ?></span>
      </a>
        <?php } else { ?>
      <a class="line is-hidden" <?php echo $attrs; ?> href="<?php echo $e($href); ?>" target="_blank" rel="noopener">
        <span class="line__time"><?php echo date('g:ia', $f['showings'][0]['t']); ?></span>
        <span>
          <span class="line__title"><?php echo $e($f['display_title']); ?></span>
          <span class="line__sub"><?php echo $e(implode(' · ', ctx_bits($f, false))); ?> &middot; <?php echo $e($times); ?></span>
        </span>
        <span class="line__venue"><?php echo date('D', $f['showings'][0]['t']); ?></span>
      </a>
        <?php }
    }
}

/**
 * Collapse repeat showings of the same film at the same venue into one entry,
 * so it renders as an alamo stack rather than as two near-identical cards.
 *
 * The front page covers tonight and tomorrow, so a film running both nights
 * appeared twice with only the time to tell the cards apart. Entries already
 * folded by ctx_fold_venue() pass through untouched — a venue card is not a
 * film and must not be merged with one.
 */
function ctx_fold_repeats(array $entries) {
    $out = [];
    $at  = [];

    foreach ($entries as $f) {
        if (!empty($f['is_group'])) { $out[] = $f; continue; }

        $key = ($f['display_title'] ?? $f['title']) . '|' . ($f['venue'] ?? '');
        if (!isset($at[$key])) {
            $f['showings'] = [];
            $at[$key] = count($out);
            $out[] = $f;
        }
        $out[$at[$key]]['showings'][] = ['t' => $f['timestamp'], 'loc' => $f['location'] ?? ''];
    }

    // A card announces itself by its earliest showing, and merging moved
    // entries out of order, so both need settling again.
    foreach ($out as &$f) {
        if (empty($f['showings'])) continue;
        usort($f['showings'], fn($a, $b) => $a['t'] <=> $b['t']);
        $f['timestamp'] = $f['showings'][0]['t'];
    }
    unset($f);

    usort($out, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
    return $out;
}

/**
 * The lines on a poster overlay: "4:30pm, Tue" per showing.
 * The weekday is always named on a stack — the whole point is that the
 * showings are on different days — and omitted for a lone showing today.
 */
function ctx_time_lines(array $showings, $now) {
    $multi = count($showings) > 1;
    $lines = [];
    foreach ($showings as $s) {
        $other = date('j M', $s['t']) !== date('j M', $now);
        $lines[] = date('g:ia', $s['t']) . (($multi || $other) ? ', ' . date('D', $s['t']) : '');
    }
    return $lines;
}

/**
 * The front page's third view: one big card per film, synopsis and every
 * showtime visible at once rather than behind a hover or a tap. A folded
 * venue (Alamo, Fathom) gets its own shape rather than being forced into the
 * per-film layout — the whole reason it folds is that it is a schedule, not
 * one film, so "in depth" for it means unfolding the schedule inline instead
 * of pretending it is a single poster.
 */
function ctx_deep_card($s, $now) {
    if (!empty($s['is_group'])) { echo ctx_deep_fold($s); return; }

    $e      = 'ctx_e';
    $member = ($s['source'] ?? '') === 'user';
    $href   = !empty($s['url']) ? $s['url'] : '/list';
    $times  = ctx_time_lines($s['showings'] ?? [['t' => $s['timestamp'], 'loc' => $s['location'] ?? '']], $now);

    $bits = [];
    if (!empty($s['year']))     $bits[] = (string)$s['year'];
    if (!empty($s['runtime']))  $bits[] = (int)$s['runtime'] . 'm';
    if (!empty($s['billing']))  $bits[] = $s['billing'];
    if (!empty($s['director'])) $bits[] = $s['director'];
    if (!empty($s['genres']))   $bits[] = $s['genres'];
    ?>
    <div class="deep<?php echo $member ? ' deep--member' : ''; ?>"
       data-venue="<?php echo $e(ctx_slug($s['venue'])); ?>" data-source="<?php echo $member ? 'user' : 'venue'; ?>"
       data-count="<?php echo count($times); ?>">
      <span class="deep__art">
        <?php if (!empty($s['festival'])): ?><span class="shot__festival"><?php echo $e($s['festival']); ?></span><?php endif; ?>
        <?php if (!empty($s['poster'])): ?>
        <img src="<?php echo $e($s['poster']); ?>" alt="<?php echo $e($s['display_title']); ?>" loading="lazy" />
        <?php else: ?><span class="shot__blank"><?php echo $e($s['display_title']); ?></span><?php endif; ?>
      </span>
      <span class="deep__body">
        <span class="deep__head">
          <span class="deep__title"><?php echo $e($s['display_title']); ?><?php if (empty($s['festival']) && !empty($s['series'])): ?><span class="shot__series"><?php echo $e($s['series']); ?></span><?php endif; ?></span>
          <?php if ($member): ?><span class="shot__by">&#9679; By a member</span><?php endif; ?>
        </span>
        <?php if ($bits): ?><span class="deep__bits"><?php echo $e(implode(' · ', $bits)); ?></span><?php endif; ?>
        <span class="deep__venue"><?php echo $e($s['venue'] . (empty($s['location']) ? '' : ', ' . $s['location'])); ?></span>
        <?php if (!empty($s['overview'])): ?><span class="deep__overview"><?php echo $e($s['overview']); ?></span><?php endif; ?>
        <?php if (!empty($s['cast'])): ?>
        <span class="deep__cast"><span class="deep__cast-label">Features</span> <?php echo $e($s['cast']); ?></span>
        <?php endif; ?>
        <span class="deep__foot">
          <span class="deep__times">
            <?php foreach ($times as $t): ?><span class="deep__time"><?php echo $e($t); ?></span><?php endforeach; ?>
          </span>
          <?php if (!empty($s['wiki'])): ?>
          <a class="deep__wiki" href="<?php echo $e($s['wiki']); ?>" target="_blank" rel="noopener">Wikipedia <i class="fa-solid fa-arrow-up-right-from-square"></i></a>
          <?php endif; ?>
        </span>
      </span>
      <?php // A single full-card link would swallow the Wikipedia link inside
            // it — anchors cannot nest. This sits behind everything and under
            // the Wikipedia link's own stacking context, so the card is a
            // click target everywhere except the one spot with its own. ?>
      <a class="deep__stretch" href="<?php echo $e($href); ?>"<?php echo $member ? '' : ' target="_blank" rel="noopener"'; ?>
         aria-label="<?php echo $e($s['display_title']); ?>"></a>
    </div>
    <?php
}

/** The folded-venue shape of the card above: the schedule ctx_group_sheet()
 *  otherwise hides behind a tap, shown inline instead. */
function ctx_deep_fold(array $s) {
    $e = 'ctx_e';
    ob_start(); ?>
    <div class="deep deep--fold" data-venue="<?php echo $e(ctx_slug($s['venue'])); ?>" data-source="venue"
         data-count="<?php echo (int)$s['n_showings']; ?>">
      <span class="deep__stack fold__stack">
        <?php if (!empty($s['festival'])): ?><span class="shot__festival"><?php echo $e($s['festival']); ?></span><?php endif; ?>
        <?php foreach (array_reverse($s['posters']) as $i => $p): ?>
        <img class="fold__face fold__face--<?php echo count($s['posters']) - $i; ?>" src="<?php echo $e($p); ?>" alt="" />
        <?php endforeach; ?>
      </span>
      <div class="deep__body">
        <div class="deep__head">
          <span class="deep__title"><?php echo $e($s['venue']); ?></span>
        </div>
        <span class="deep__meta"><?php echo $e($s['day_label']); ?> &middot;
          <?php echo (int)$s['n_films']; ?> film<?php echo $s['n_films'] === 1 ? '' : 's'; ?> &middot;
          <?php echo (int)$s['n_showings']; ?> showing<?php echo $s['n_showings'] === 1 ? '' : 's'; ?></span>
        <div class="deep__films">
          <?php foreach ($s['films'] as $f): ?>
          <a class="fold__film" href="<?php echo $e($f['url'] ?: '#'); ?>" target="_blank" rel="noopener">
            <span class="fold__art">
              <?php if (!empty($f['poster'])): ?><img src="<?php echo $e($f['poster']); ?>" alt="" loading="lazy" /><?php endif; ?>
            </span>
            <span class="fold__meta">
              <span class="fold__title"><?php echo $e($f['display_title'] ?? $f['title']); ?></span>
              <?php $bits = ctx_bits($f, false); if ($bits): ?>
              <span class="fold__bits"><?php echo $e(implode(' · ', $bits)); ?></span>
              <?php endif; ?>
              <span class="fold__times"><?php echo $e(ctx_showings_label($f['showings'])); ?></span>
            </span>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php return ob_get_clean();
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
    $short = trim(preg_replace('/\s*(Theatre|Theater|Cinema|Film Club|Drafthouse|Events)$/i', '', $v));
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
