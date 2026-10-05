<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Daily "Today in Austin" Instagram card
//
//  Renders today's real-venue + member screenings (the same feed The List
//  page shows) into a branded PNG via GD, builds a matching caption, and
//  posts both through the Instagram Graph API (Content Publishing).
//
//  GD, not Imagick — the project has no image-processing dependency yet and
//  GD ships with PHP. The two brand fonts are static instances pulled out of
//  Google's variable-font sources (assets/fonts/), since GD's imagettftext
//  can't select a weight from a variable font.
// ═══════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/fetch_screenings.php';
require_once dirname(__DIR__) . '/v7/screenings.php';
require_once __DIR__ . '/instagram_animation.php';

define('IG_GRAPH_VERSION', 'v21.0');
define('IG_FONT_HEADLINE', dirname(__DIR__) . '/assets/fonts/Fraunces-Bold.ttf');
define('IG_FONT_BODY',     dirname(__DIR__) . '/assets/fonts/InstrumentSans-SemiBold.ttf');
// Marquee's title face — bold, tall and condensed, the closest a Google Font
// gets to a real letter-board headline without hand-drawing tile panels.
// Themes are otherwise free to keep using IG_FONT_HEADLINE/IG_FONT_BODY;
// this exists because Marquee specifically wanted its own identity, not
// because every theme needs its own font.
define('IG_FONT_MARQUEE_TITLE', dirname(__DIR__) . '/assets/fonts/Anton-Regular.ttf');
// Zine's title face — a distressed typewriter face rather than a display
// font, for the photocopied-flyer read. Its ascent/descent at the sizes
// used here (measured) are close enough to Fraunces' that zine reuses the
// paper theme's exact spacing constants rather than needing its own.
define('IG_FONT_ZINE_TITLE', dirname(__DIR__) . '/assets/fonts/SpecialElite-Regular.ttf');
// Newsprint's title face — a bold slab serif, extracted as a static Bold
// instance from Roboto Slab's variable source (fonttools, same as
// Fraunces/Instrument Sans originally were). Also carries the masthead
// nameplate, not just film titles, since the wordmark needed the same
// "printed newspaper name" character.
define('IG_FONT_NEWSPRINT_TITLE', dirname(__DIR__) . '/assets/fonts/RobotoSlab-Bold.ttf');
// Neon/VHS's title face. First attempt was Monoton, an actual neon-tube
// novelty font — but its glyphs are drawn with several parallel strokes
// per letter (real neon signage sometimes bends one tube back and forth
// for a bold look, and the font mimics that), and stacked with the glow on
// top that read as noise rather than legible type. A clean single-stroke
// rounded sans reads as neon just as well once glowed, and reads far
// better as text — the glow is what's doing the "neon" work either way.
define('IG_FONT_NEON_TITLE', dirname(__DIR__) . '/assets/fonts/Baloo2-Bold.ttf');
// Terminal's face, for everything it draws — a monospaced pixel font built
// for exactly this retro command-line/departure-board read, so the "board"
// identity comes from the font itself rather than a drawn segment trick like
// the star/arrow primitives elsewhere.
define('IG_FONT_TERMINAL', dirname(__DIR__) . '/assets/fonts/DepartureMono-Regular.otf');
// Darkroom's title face — a clean single-weight stencil, the way a film can
// or a darkroom equipment label is actually lettered. All-caps only, which
// suits it: stencils don't have a lowercase to skip.
define('IG_FONT_DARKROOM_TITLE', dirname(__DIR__) . '/assets/fonts/AllertaStencil-Regular.ttf');

// ── Data ─────────────────────────────────────────────────────────────────

// The arthouse venues this post is meant to promote — not the chains
// (Alamo Drafthouse, Fathom Events) and not real member-submitted `events`
// (source 'user', kept out until public engagement is actually on). Alamo
// is still selectable on a per-day basis, though — see ig_alamo_films().
// An admin-added screening (source 'admin', see _admin/events.php) is let
// in regardless of venue name — see ig_arthouse_films()'s filter.
const IG_VENUES = ['Austin Film Society', 'Paramount Theatre', 'Hyperreal Film Club', 'Flick Clique'];
const IG_ALAMO_VENUE = 'Alamo Drafthouse';

// The admin page's own "today." Past IG_ADMIN_CUTOVER_HOUR (10pm Central —
// this project's only timezone, see database.php) it rolls forward to
// tomorrow's date, so the evening before can be spent setting tomorrow's
// post up rather than waiting past midnight to touch it. Every admin
// page/handler should read this instead of calling time()/strtotime('today')
// directly. The cron job is the one exception and must never call this: by
// the time it runs (7-8am) the literal calendar day already IS the day that
// was prepared the night before, so it always wants straight today.
const IG_ADMIN_CUTOVER_HOUR = 22;

function ig_admin_target_date() {
    $today = strtotime('today');
    return (int) date('G') >= IG_ADMIN_CUTOVER_HOUR ? strtotime('+1 day', $today) : $today;
}

// Today's raw arthouse lineup (IG_VENUES) — always the full day, regardless
// of what a prior save excluded, the same "full day, independent of the
// selection" shape ig_alamo_films() uses. ig_today_films() is what actually
// applies exclusions; this is what the admin checklist offers to exclude
// from, so unchecking something is always reversible.
//
// $date defaults to the real strtotime('today') — not ig_admin_target_date()
// — so bin/post-instagram.php's plain ig_arthouse_films($conn) call keeps
// fetching the literal current day unchanged; admin callers pass the target
// date explicitly.
function ig_arthouse_films($conn, $date = null) {
    $start = $date ?? strtotime('today');
    $end   = strtotime('+1 day', $start) - 1;
    $films = fetch_all_screenings($conn, $start, $end, false);
    // A scraped screening qualifies by venue name; an admin-added one (see
    // _admin/events.php) qualifies regardless of what venue it names, since
    // that's the whole point — a one-off venue no scraper covers. A real
    // member submission (source 'user') matches neither and stays out.
    $films = array_values(array_filter(
        $films,
        fn($f) => in_array($f['venue'], IG_VENUES, true) || ($f['source'] ?? null) === 'admin'
    ));
    // The raw scrape misses posters for anything a venue re-bills with extra
    // billing baked into the title — "PHAT GIRLZ (20th Anniversary)" doesn't
    // match TMDB, "PHAT GIRLZ" does. v7/screenings.php's ctx_enrich() already
    // solves this with a cleaned-title second-chance lookup (that's what
    // fixes it on /list and the homepage); this just needed to run here too.
    $films = ctx_enrich($films);

    // Every card/caption renderer here reads $film['title'] directly rather
    // than knowing about display_title vs. the raw scrape — folding the
    // enriched title in here means the poster fix and the title cleanup
    // land together everywhere at once, not just wherever someone remembers
    // to check for display_title.
    foreach ($films as &$f) {
        if (!empty($f['display_title'])) $f['title'] = $f['display_title'];
    }
    unset($f);

    return ig_group_showtimes($films);
}

// Today's window in the project's own timezone (America/Chicago, set in
// database.php). $force is false — this rides on whatever warm-cache.php
// has already scraped, same as a normal page request.
function ig_today_films($conn, $date = null) {
    $start = $date ?? strtotime('today');

    // Every arthouse screening is on the card by default — the admin
    // checklist is opt-*out*, the mirror image of Alamo's opt-in below, so
    // an empty/absent exclude-list (every day nobody's touched) reproduces
    // today's exact current behavior.
    $excluded = ig_excluded_read($start);
    $films = array_values(array_filter(
        ig_arthouse_films($conn, $start),
        fn($f) => !in_array(ig_film_key($f), $excluded, true)
    ));

    // Alamo is left out of IG_VENUES above (a chain, not the arthouse lineup
    // this post is meant to promote) but can be opted into per day through
    // the admin checklist — fold in whichever ones were checked, or, on a
    // day nobody's touched that checklist, the last couple Alamo screenings
    // of the night by default (see ig_alamo_selected_keys()). Fetches
    // ig_alamo_films() (a second scrape-cache read, see list/cache.php —
    // cheap, not a live re-scrape) every day now rather than only when a
    // selection exists, since even the default needs to know what's
    // actually playing tonight to pick the last two.
    $alamoFilms    = ig_alamo_films($conn, $start);
    $alamoSelected = ig_alamo_selected_keys($start, $alamoFilms);
    if ($alamoSelected) {
        $alamo = array_values(array_filter(
            $alamoFilms,
            fn($f) => in_array(ig_alamo_key($f), $alamoSelected, true)
        ));
        $films = array_merge($films, $alamo);
        usort($films, fn($a, $b) => min($a['timestamps']) <=> min($b['timestamps']));
    }

    return $films;
}

/**
 * Today's Alamo Drafthouse screenings, one row per film with every location
 * and showtime folded together — Alamo books the same title into up to 5
 * Austin cinemas, sometimes several times a night, and per-location rows
 * would make the opt-in checklist unusably long.
 *
 * When every showing of a title happens to share one cinema, `venue` becomes
 * that cinema's own name ("Alamo Village") instead of the generic chain
 * name — the same way AFS, the Paramount, and Hyperreal already show their
 * own names, so Alamo reads the same way whenever it can. The moment two
 * different cinemas are folded into the same line, no single one of them is
 * accurate, so it falls back to IG_ALAMO_VENUE. `location` itself is still
 * dropped on the merged row either way, since the per-location detail
 * already lives in `venue` when it survives the merge at all, and every
 * card renderer already appends `location` after `venue` when it's set —
 * appending it too here would double it up.
 *
 * Not filtered into the card by default — this is purely what the admin
 * checklist offers. See ig_today_films() for where a day's selections
 * actually get merged in.
 */
function ig_alamo_films($conn, $date = null) {
    $start = $date ?? strtotime('today');
    $end   = strtotime('+1 day', $start) - 1;
    $films = fetch_all_screenings($conn, $start, $end, false);
    $films = array_values(array_filter($films, fn($f) => ($f['venue'] ?? '') === IG_ALAMO_VENUE));
    $films = ctx_enrich($films);
    foreach ($films as &$f) {
        if (!empty($f['display_title'])) $f['title'] = $f['display_title'];
    }
    unset($f);

    $locationsByKey = [];
    foreach ($films as $f) {
        $locationsByKey[ig_alamo_key($f)][$f['location']] = true;
    }

    $grouped = ig_group_showtimes($films, 'ig_alamo_key');
    foreach ($grouped as &$g) {
        $locs = array_keys($locationsByKey[ig_alamo_key($g)] ?? []);
        $g['venue']    = (count($locs) === 1 && $locs[0] !== '') ? 'Alamo ' . $locs[0] : IG_ALAMO_VENUE;
        $g['location'] = null;
    }
    unset($g);

    return $grouped;
}

// Alamo's checklist identity — title only, deliberately coarser than
// ig_film_key() (title+venue+location), since every Alamo location is
// meant to collapse into the same checkbox rather than getting one each.
function ig_alamo_key(array $film) {
    return mb_strtolower(trim($film['title']));
}

// Same identity a film has for grouping repeat showtimes and for the
// On the Carousel checkboxes — title+venue+location, already unique enough
// within one day's window that nothing else is needed.
function ig_film_key(array $film) {
    return mb_strtolower(trim($film['title'])) . '|' . $film['venue'] . '|' . ($film['location'] ?? '');
}

/**
 * A film playing twice in one day at the same venue (a scraper row per
 * showing, since the AFS parser fix) is one listing here, not two — one
 * poster, one row, one spotlight page, with every time on it. Same title,
 * same venue, same location (already same day — ig_today_films() only ever
 * fetches one day) collapse into a single entry carrying a `timestamps`
 * array; every other field is kept from whichever showing was seen first,
 * since it comes from the same TMDB lookup either way.
 *
 * $keyFn defaults to ig_film_key() (title+venue+location) but takes
 * ig_alamo_key() (title only) too, for folding every Alamo location into
 * one row instead of one per location.
 */
function ig_group_showtimes(array $films, $keyFn = 'ig_film_key') {
    $groups = [];
    foreach ($films as $film) {
        $key = $keyFn($film);
        if (isset($groups[$key])) {
            $groups[$key]['timestamps'][] = $film['timestamp'];
        } else {
            $film['timestamps'] = [$film['timestamp']];
            $groups[$key] = $film;
        }
    }

    $out = [];
    foreach ($groups as $g) {
        // Folding several Alamo locations together can carry in the same
        // showtime twice (two cinemas running the same 7:00 print) — a
        // plain ig_film_key() grouping never hits this since one venue+
        // location doesn't repeat a showing, but it costs nothing to guard
        // here for both.
        $g['timestamps'] = array_values(array_unique($g['timestamps']));
        sort($g['timestamps']);
        $out[] = $g;
    }
    usort($out, fn($a, $b) => min($a['timestamps']) <=> min($b['timestamps']));
    return $out;
}

// "7:00 PM" for one showing, "7:00 PM & 9:30 PM" for two, an Oxford-joined
// list for more than that — every card/caption line that shows a time reads
// off this instead of the single `timestamp` field once ig_today_films()
// has grouped same-day repeats together.
function ig_format_times(array $timestamps) {
    sort($timestamps);
    $times = array_map(fn($t) => date('g:i A', $t), $timestamps);
    if (count($times) === 1) return $times[0];
    $last = array_pop($times);
    return implode(', ', $times) . ' & ' . $last;
}

// ── Image ────────────────────────────────────────────────────────────────

function ig_hex($im, $hex) {
    $hex = ltrim($hex, '#');
    return imagecolorallocate(
        $im,
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2))
    );
}

// Trims $text until it fits $maxWidth at $size, appending an ellipsis.
function ig_fit_text($text, $font, $size, $maxWidth) {
    $bbox = imagettfbbox($size, 0, $font, $text);
    if ($bbox[2] - $bbox[0] <= $maxWidth) return $text;
    while (mb_strlen($text) > 1) {
        $text = mb_substr($text, 0, -1);
        $bbox = imagettfbbox($size, 0, $font, $text . '…');
        if ($bbox[2] - $bbox[0] <= $maxWidth) return $text . '…';
    }
    return $text;
}

// The compact "w/ Org" form of ctx_billing()'s "Presented with/by Org" —
// for tight spaces (right next to a row title) where the full phrase
// doesn't fit. Live-score billing has no compact form and returns null;
// it's spelled out in full elsewhere (the spotlight page) instead.
function ig_presented_with($billing) {
    if ($billing && preg_match('/^Presented (?:with|by) (.+)$/i', $billing, $m)) {
        return 'w/ ' . trim($m[1]);
    }
    return null;
}

// A pill — rectangle with semicircular ends — for the wordmark chip and
// showtime pills. Carries its own weak drop shadow (an offset, low-alpha
// copy of the same shape, drawn first) so it reads as
// sitting slightly above whatever it's on — hero photo, paper, or marquee's
// black — rather than flat against it. GD has no blur filter worth reaching
// for here, so this is the shadow: a soft edge would need compositing onto
// a separate canvas first, more than a "weak" shadow calls for.
function ig_pill($im, $x1, $y1, $x2, $y2, $color) {
    $r = (int) round(($y2 - $y1) / 2);

    $shadow = imagecolorallocatealpha($im, 0, 0, 0, 105);
    $dx = 3;
    $dy = 4;
    imagefilledrectangle($im, $x1 + $r + $dx, $y1 + $dy, $x2 - $r + $dx, $y2 + $dy, $shadow);
    imagefilledellipse($im, $x1 + $r + $dx, $y1 + $r + $dy, $r * 2, $r * 2, $shadow);
    imagefilledellipse($im, $x2 - $r + $dx, $y1 + $r + $dy, $r * 2, $r * 2, $shadow);

    imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
    imagefilledellipse($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
    imagefilledellipse($im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
}

// A drawn arrow — shaft plus a filled triangular head — rather than a "→"
// glyph, since nothing guarantees an arrow character is in either font, so
// it's drawn instead of trusted to render. Runs from $x1 to $x2 at height $y.
function ig_draw_arrow($im, $x1, $y, $x2, $color, $thickness = 3, $headSize = 9) {
    imagesetthickness($im, $thickness);
    imageline($im, (int) $x1, (int) $y, (int) ($x2 - $headSize), (int) $y, $color);
    imagesetthickness($im, 1);
    imagefilledpolygon($im, [
        $x2, $y,
        $x2 - $headSize, $y - $headSize,
        $x2 - $headSize, $y + $headSize,
    ], $color);
}

// A soft halo behind crisp text — eight low-alpha copies at a small radius,
// then the real text on top. GD has no blur to reach for, so this is the
// glow: cheap, and convincing at the sizes these cards render at.
function ig_neon_text($im, $size, $x, $y, $font, $text, $color, $glowColor) {
    foreach ([[-2, 0], [2, 0], [0, -2], [0, 2], [-2, -2], [2, 2], [-2, 2], [2, -2]] as [$dx, $dy]) {
        imagettftext($im, $size, 0, $x + $dx, $y + $dy, $glowColor, $font, $text);
    }
    imagettftext($im, $size, 0, $x, $y, $color, $font, $text);
}

// A glowing rectangle frame — a thick low-alpha outline, then a thin bright
// one on top — the CRT-screen-edge equivalent of Marquee's bulb border.
function ig_neon_border($im, $x1, $y1, $x2, $y2, $glowColor, $lineColor) {
    imagesetthickness($im, 10);
    imagerectangle($im, $x1, $y1, $x2, $y2, $glowColor);
    imagesetthickness($im, 2);
    imagerectangle($im, $x1, $y1, $x2, $y2, $lineColor);
    imagesetthickness($im, 1);
}

// ── October's neon accents ───────────────────────────────────────────────────
// Glowing tubes for the Neon theme's Halloween look: each stroke is a wide dim
// halo, a narrower brighter one, then the coloured tube itself with a hotter
// core — the same low-alpha-then-crisp idea as ig_neon_text(), for shapes.
// $pts is a list of [x, y]; $closed joins the last point back to the first.
//
// $level (0..1) dims the whole tube — the halo thins and the colour sinks
// toward the card's background — which is how the animated version makes
// tubes flicker and fade; 1, the default, is the still.
function ig_neon_tube($im, array $pts, array $rgb, $closed = false, $core = 3, $level = 1.0) {
    $n = count($pts);
    $haloA = fn($a) => $a;
    if ($level < 1.0) {
        $level = max(0.0, $level);
        if ($level < 0.03) return;
        $bgc = [0x15, 0x08, 0x29];
        $rgb = [(int) round($bgc[0] + ($rgb[0] - $bgc[0]) * $level), (int) round($bgc[1] + ($rgb[1] - $bgc[1]) * $level), (int) round($bgc[2] + ($rgb[2] - $bgc[2]) * $level)];
        $haloA = fn($a) => (int) round(127 - (127 - $a) * $level);
    }
    $draw = function ($color) use ($im, $pts, $n, $closed) {
        for ($i = 0; $i < $n - 1; $i++) imageline($im, (int) round($pts[$i][0]), (int) round($pts[$i][1]), (int) round($pts[$i + 1][0]), (int) round($pts[$i + 1][1]), $color);
        if ($closed) imageline($im, (int) round($pts[$n - 1][0]), (int) round($pts[$n - 1][1]), (int) round($pts[0][0]), (int) round($pts[0][1]), $color);
    };
    foreach ([[14, 118, $rgb], [8, 100, $rgb], [$core + 1, 40, $rgb]] as [$th, $a, $c]) {
        imagesetthickness($im, $th);
        $draw(imagecolorallocatealpha($im, $c[0], $c[1], $c[2], $haloA($a)));
    }
    imagesetthickness($im, max(1, $core - 1));
    $draw(imagecolorallocate($im, min(255, $rgb[0] + 70), min(255, $rgb[1] + 70), min(255, $rgb[2] + 70)));
    imagesetthickness($im, 1);
}

// Points along an elliptical arc ($a0..$a1 degrees, 0 = right, clockwise on
// screen) — the building block for rounded tube shapes.
function ig_neon_arc($cx, $cy, $rx, $ry, $a0 = 0, $a1 = 360, $steps = 32) {
    $pts = [];
    for ($i = 0; $i <= $steps; $i++) {
        $a = deg2rad($a0 + ($a1 - $a0) * $i / $steps);
        $pts[] = [$cx + $rx * cos($a), $cy + $ry * sin($a)];
    }
    return $pts;
}

// A skull in violet tube with pink eyes and nose and cyan teeth, $r px from
// its centre to the edge of the cranium.
//
// Animated, $st = ['outline' => 0..1, 'eyes' => 0..1] dims the cranium and the
// eyes/nose independently; null is the still.
function ig_neon_skull($im, $cx, $cy, $r, $st = null) {
    $lo = $st['outline'] ?? 1.0; $le = $st['eyes'] ?? 1.0;
    $violet = [170, 110, 255]; $pink = [255, 46, 154]; $cyan = [45, 226, 230];
    $outline = ig_neon_arc($cx, $cy - 0.1 * $r, 0.7 * $r, 0.72 * $r, 150, 390, 32);
    array_push($outline,
        [$cx + 0.42 * $r, $cy + 0.55 * $r], [$cx + 0.42 * $r, $cy + 0.9 * $r],
        [$cx - 0.42 * $r, $cy + 0.9 * $r],  [$cx - 0.42 * $r, $cy + 0.55 * $r]);
    $k = $r / 68;   // stroke weights were drawn for r=68; smaller skulls get lighter tubes
    $t4 = max(2, (int) round(4 * $k)); $t3 = max(2, (int) round(3 * $k)); $t2 = max(1, (int) round(2 * $k));
    ig_neon_tube($im, $outline, $violet, true, $t4, $lo);
    foreach ([-1, 1] as $m) {
        ig_neon_tube($im, ig_neon_arc($cx + $m * 0.28 * $r, $cy - 0.05 * $r, 0.2 * $r, 0.22 * $r, 0, 360, 20), $pink, true, $t3, $le);
    }
    ig_neon_tube($im, [[$cx, $cy + 0.2 * $r], [$cx - 0.09 * $r, $cy + 0.4 * $r], [$cx + 0.09 * $r, $cy + 0.4 * $r]], $pink, true, $t3, $le);
    foreach ([-0.2, 0.0, 0.2] as $dx) {
        ig_neon_tube($im, [[$cx + $dx * $r, $cy + 0.62 * $r], [$cx + $dx * $r, $cy + 0.9 * $r]], $cyan, false, $t2, $lo);
    }
}

// "CINEMA, TX" with its E burnt out: the title drawn a letter at a time, the
// E an unlit plum tube with no glow, the M beside it sagging to half
// brightness, and a few sparks jumping off the dead tube. Drawn exactly where
// the plain title sits ($x, $baseline) so nothing around it shifts. Letter
// positions come from the width of everything before each letter.
//
// Animated, $st = ['e' => 0..1, 'm' => 0..1, 'spark' => 0..1, 'seed' => int]
// says how lit the E is (0 dead, 1 full neon), how bright the M is, how
// hard the sparks are firing, and which way they jump; null is the still.
function ig_neon_dying_title($im, $size, $x, $baseline, $font, $pink, $pinkGlow, $st = null) {
    $text = 'CINEMA, TX';
    $dead = ig_hex($im, '#5B1B48');
    $dim  = imagecolorallocatealpha($im, 0xFF, 0x2E, 0x9A, 58);
    $span = function ($s) use ($size, $font) { $b = imagettfbbox($size, 0, $font, $s . 'l'); $l = imagettfbbox($size, 0, $font, 'l'); return $b[2] - $l[2]; };
    $eX = $x;
    for ($i = 0; $i < strlen($text); $i++) {
        $ch = $text[$i];
        $px = $x + ($i === 0 ? 0 : $span(substr($text, 0, $i)));
        if ($ch === 'E') {
            $eX = $px;
            $e = $st['e'] ?? 0.0;
            if ($e <= 0.0) {
                imagettftext($im, $size, 0, $px, $baseline, $dead, $font, $ch);
            } else {
                // Lit: blend from the dead plum up to the full pink, and only
                // once it is mostly up does it throw a glow.
                $dr = ($dead >> 16) & 255; $dg = ($dead >> 8) & 255; $db = $dead & 255;
                $lit = imagecolorallocate($im, (int) round($dr + (0xFF - $dr) * $e), (int) round($dg + (0x2E - $dg) * $e), (int) round($db + (0x9A - $db) * $e));
                if ($e >= 0.5) ig_neon_text($im, $size, $px, $baseline, $font, $ch, $lit, imagecolorallocatealpha($im, 0xFF, 0x2E, 0x9A, (int) round(127 - 27 * $e)));
                else           imagettftext($im, $size, 0, $px, $baseline, $lit, $font, $ch);
            }
        }
        elseif ($ch === 'M') {
            $mc = isset($st['m']) ? imagecolorallocatealpha($im, 0xFF, 0x2E, 0x9A, (int) round(127 - 127 * min(1.0, max(0.0, $st['m'])))) : $dim;
            if (isset($st['m']) && $st['m'] > 0.9) ig_neon_text($im, $size, $px, $baseline, $font, $ch, $pink, $pinkGlow);
            else                                   imagettftext($im, $size, 0, $px, $baseline, $mc, $font, $ch);
        }
        else                  ig_neon_text($im, $size, $px, $baseline, $font, $ch, $pink, $pinkGlow);
    }
    // Sparks: short bright strokes jumping off the top of the E.
    $spark = imagecolorallocate($im, 255, 214, 100);
    $hot   = imagecolorallocatealpha($im, 255, 180, 60, 90);
    $k = $size / 34;
    $level = $st['spark'] ?? 1.0;
    $seed  = $st['seed'] ?? 0;
    foreach ([[10, -26, -10, -48], [18, -26, 30, -44], [14, -28, 14, -54], [24, -22, 44, -30]] as $si => [$x1, $y1, $x2, $y2]) {
        if ($level <= 0.0) break;
        if ($st !== null) {
            // Each frame the sparks jump a little differently, and a weak
            // burst drops the outer ones.
            if ($si >= 2 && $level < 0.75) continue;
            $x2 += (int) round(9 * sin($seed * 2.31 + $si * 1.7));
            $y2 += (int) round(7 * sin($seed * 1.37 + $si * 2.9));
            $scale = $k * (0.55 + 0.45 * $level);
        } else $scale = $k;
        [$x1, $y1, $x2, $y2] = [(int) round($eX + $x1 * $scale), (int) round($baseline + $y1 * $scale), (int) round($eX + $x2 * $scale), (int) round($baseline + $y2 * $scale)];
        imagesetthickness($im, 6); imageline($im, $x1, $y1, $x2, $y2, $hot);
        imagesetthickness($im, 2); imageline($im, $x1, $y1, $x2, $y2, $spark);
    }
    imagesetthickness($im, 1);
}

// A little graveyard along the ground, in tube: a ground line, four stones
// (rounded RIP slab, cross, tall arch, a slab leaning the other way) and a few
// grass tufts. $x0..$x1 is the span, $base the ground's y.
//
// Animated ($anim = ['frame', 'frames', ...]): the cross flickers once in a
// while, and a small ghost rises out of the RIP slab, sways its way up the
// right-hand side of the card (clear of the skull and, mostly, of the list
// text) and out through the top edge. It is off-screen for the loop's first
// and last stretch, so the loop closes on an empty graveyard.
function ig_neon_tombstones($im, $x0, $x1, $base, $anim = null) {
    $crossLevel = 1.0; $ghost = null;
    if ($anim !== null) {
        $f = $anim['frame'] % IG_ANIM_FRAMES;
        if (in_array($f, [40, 41, 44], true)) $crossLevel = 0.15;
        elseif (in_array($f, [42, 45], true)) $crossLevel = 0.6;
        // The ghost's single journey spans the whole video, not one cycle.
        $gp = ($anim['frame'] / $anim['frames'] - 0.03) / 0.895;
        if ($gp > 0 && $gp < 1) {
            // Fades in as it leaves the slab, then climbs steadily until it is
            // past the top edge (centre at -40, so the whole ghost and its
            // glow are clear of the frame by the time it stops being drawn).
            $ghost = [$x0 + 150 + 14 * sin(2 * M_PI * 10 * $gp), ($base - 96) - (($base - 96) + 40) * $gp, min(1.0, $gp / 0.08) * 0.9];
        }
    }
    $pink = [255, 46, 154]; $violet = [170, 110, 255]; $cyan = [45, 226, 230]; $green = [120, 255, 90];
    ig_neon_tube($im, [[$x0, $base], [$x1, $base]], $violet, false, 2);
    $slab = function ($cx, $w, $h, $color, $lean = 0) use ($im, $base) {
        $half = $w / 2;
        $pts = [[$cx - $half, $base]];
        foreach (ig_neon_arc($cx, $base - $h + $half, $half, $half, 180, 360, 14) as $p) $pts[] = $p;
        $pts[] = [$cx + $half, $base];
        if ($lean) foreach ($pts as &$p) $p[0] += $lean * ($base - $p[1]) / $h;
        unset($p);
        ig_neon_tube($im, $pts, $color, false, 3);
    };
    $slab($x0 + 150, 66, 84, $pink);
    $slab($x0 + 40,  44, 50, $cyan, -8);
    $slab($x0 + 262, 40, 76, $violet);
    // Cross.
    $cxm = $x0 + 214;
    ig_neon_tube($im, [[$cxm, $base], [$cxm, $base - 62]], $cyan, false, 3, $crossLevel);
    ig_neon_tube($im, [[$cxm - 17, $base - 44], [$cxm + 17, $base - 44]], $cyan, false, 3, $crossLevel);
    // Grass.
    foreach ([$x0 + 92, $x0 + 188, $x0 + 300] as $gx) {
        foreach ([[-7, -11], [0, -15], [7, -11]] as [$dx, $dy]) ig_neon_tube($im, [[$gx, $base], [$gx + $dx, $base + $dy]], $green, false, 2);
    }
    // R.I.P. on the big slab.
    $rip = imagecolorallocatealpha($im, 255, 46, 154, 100);
    ig_neon_text($im, 15, $x0 + 150 - 17, $base - 40, IG_FONT_NEON_TITLE, 'RIP', ig_hex($im, '#FF7FC4'), $rip);
    if ($ghost) ig_neon_ghost($im, $ghost[0], $ghost[1], 20, $ghost[2]);
}

// A small cyan ghost, pink-eyed, $r px across its dome, drawn at $level.
function ig_neon_ghost($im, $cx, $cy, $r, $level = 1.0) {
    $cyan = [45, 226, 230]; $pink = [255, 46, 154];
    $pts = ig_neon_arc($cx, $cy - 0.2 * $r, 0.62 * $r, 0.7 * $r, 180, 360, 20);
    $pts[] = [$cx + 0.62 * $r, $cy + 0.7 * $r];
    for ($i = 1; $i <= 6; $i++) $pts[] = [$cx + 0.62 * $r - 1.24 * $r * $i / 6, $cy + 0.7 * $r + ($i % 2 === 0 ? 0 : 0.28 * $r)];
    ig_neon_tube($im, $pts, $cyan, true, 2, $level);
    foreach ([-1, 1] as $m) ig_neon_tube($im, ig_neon_arc($cx + $m * 0.24 * $r, $cy - 0.28 * $r, 0.08 * $r, 0.13 * $r, 0, 360, 10), $pink, true, 2, $level);
    ig_neon_tube($im, ig_neon_arc($cx, $cy + 0.12 * $r, 0.1 * $r, 0.14 * $r, 0, 360, 10), $pink, true, 2, $level);
}

// ── October's newsprint accents ──────────────────────────────────────────────
// Kept to the page's own two inks (black and the brick red) and its own
// vocabulary: a halftone moon, engraved clouds, a classifieds box.

// An engraved cloud: a puffy outline in ink, painted opaque so it covers
// whatever it floats in front of, with ink hatching across its underside that
// gets closer together toward the bottom. ($cx, $cy) is its centre, $s its
// scale (1 is about 100 px wide).
function ig_news_cloud($im, $cx, $cy, $s, $ink, $paper) {
    $puffs = [[-34, 9, 15], [-15, -2, 21], [12, -6, 23], [36, 6, 17], [2, 10, 20]];
    foreach ($puffs as [$dx, $dy, $r]) imagefilledellipse($im, (int) round($cx + $dx * $s), (int) round($cy + $dy * $s), (int) round(2 * ($r * $s + 2)), (int) round(2 * ($r * $s + 2)), $ink);
    foreach ($puffs as [$dx, $dy, $r]) imagefilledellipse($im, (int) round($cx + $dx * $s), (int) round($cy + $dy * $s), (int) round(2 * $r * $s), (int) round(2 * $r * $s), $paper);
    $inside = function ($x, $y) use ($puffs, $cx, $cy, $s) {
        foreach ($puffs as [$dx, $dy, $r]) if (hypot($x - ($cx + $dx * $s), $y - ($cy + $dy * $s)) < $r * $s - 3) return true;
        return false;
    };
    $y = $cy + 8 * $s; $gap = 6 * $s;
    while ($y < $cy + 30 * $s) {
        $runStart = null;
        for ($x = (int) round($cx - 60 * $s); $x <= (int) round($cx + 60 * $s); $x++) {
            $in = $inside($x, $y);
            if ($in && $runStart === null) $runStart = $x;
            if ((!$in || $x === (int) round($cx + 60 * $s)) && $runStart !== null) { imageline($im, $runStart + 2, (int) round($y), $x - 3, (int) round($y), $ink); $runStart = null; }
        }
        $y += $gap; $gap = max(2.5 * $s, $gap - 1.1 * $s);
    }
}

// A halftone-engraved moon: a staggered grid of dots whose size follows the
// shading — bare paper on the lit (left) side, heavy dots in the shadow and
// toward the rim — with a few cratered patches, and a thin outline.
function ig_halftone_moon($im, $cx, $cy, $r, $ink) {
    $g = 9;
    for ($y = -$r; $y <= $r; $y += $g) {
        for ($x = -$r; $x <= $r; $x += $g) {
            $xo = $x + (((int) ($y / $g)) % 2 ? $g / 2 : 0);
            $d = sqrt($xo * $xo + $y * $y);
            if ($d > $r - 1) continue;
            $shade = 0.12 + 0.88 * pow(($xo + $r) / (2 * $r), 1.3) + 0.25 * pow($d / $r, 4);
            $dot = max(0.0, min(1.0, $shade)) * 4.4;
            foreach ([[-0.35, -0.25, 0.22], [0.1, 0.35, 0.18], [-0.1, -0.5, 0.12]] as [$ox, $oy, $orr]) {
                if (hypot($xo - $ox * $r, $y - $oy * $r) < $orr * $r) $dot *= 0.55;
            }
            if ($dot > 0.6) imagefilledellipse($im, (int) ($cx + $xo), (int) ($cy + $y), (int) round($dot * 2), (int) round($dot * 2), $ink);
        }
    }
    imageellipse($im, $cx, $cy, 2 * $r, 2 * $r, $ink);
}

// The classifieds' three ads, three short lines each (the first line opens
// with a category that is set in red), which the list page cycles through.
function ig_news_ads() {
    return [
        ['MISSING: projectionist. Last', 'seen entering the booth. The', 'film is still running.'],
        ['FOR SALE: haunted theatre', 'seats, row F. Sold as-is.', 'They talk during previews.'],
        ['NOTICE: will the person', 'screaming in row C please', 'keep it down? — Management'],
    ];
}

// The little classifieds box: an ink title bar, then a few lines of ad. $ads is
// a list of 3-line ads; the still shows the first. Animated, the ads cycle like
// a reel being advanced a frame at a time: the video is split evenly among the
// ads, and at the end of each one's share it rolls up and out as the next
// rolls up from below (a six-frame, smoothstepped slip); the last rolls into
// the first at the loop's wrap so the cycle closes. Drawn with the clip set to the ad window
// so a rolling ad is cut off at the box edge.
function ig_news_classifieds($im, $x1, $y1, $x2, $y2, $ink, $red, $paper, array $ads, $anim = null) {
    imagerectangle($im, $x1, $y1, $x2, $y2, $ink);
    imagefilledrectangle($im, $x1, $y1, $x2, $y1 + 22, $ink);
    imagettftext($im, 11, 0, $x1 + 10, $y1 + 16, $paper, IG_FONT_BODY, 'CLASSIFIEDS');

    $cur = 0; $next = 0; $p = 0.0;
    if ($anim !== null) {
        $n = count($ads);
        $f = $anim['frame'] % $anim['frames'];
        $per = $anim['frames'] / $n;
        $cur = (int) floor($f / $per);
        $phase = $f - $cur * $per;
        $roll = 6;
        if ($phase >= $per - $roll) {
            $p = ig_ease(($phase - ($per - $roll) + 1) / $roll);
            $next = ($cur + 1) % $n;
        }
    }

    $winTop = $y1 + 24; $winH = $y2 - $winTop - 1;
    imagesetclip($im, $x1 + 1, $winTop, $x2 - 1, $y2 - 1);
    foreach ($p > 0 ? [[$cur, -$p * $winH], [$next, (1 - $p) * $winH]] : [[$cur, 0]] as [$ai, $dy]) {
        $y = $y1 + 46 + (int) round($dy);
        foreach ($ads[$ai] as $li => $line) {
            // The first line opens with the ad's category ("FOR SALE:"), which
            // alone is in red, like a classified's headline word.
            $colon = $li === 0 ? strpos($line, ':') : false;
            if ($colon !== false) {
                $label = substr($line, 0, $colon + 1);
                $lw = imagettfbbox(15, 0, IG_FONT_BODY, $label . 'l')[2] - imagettfbbox(15, 0, IG_FONT_BODY, 'l')[2];
                imagettftext($im, 15, 0, $x1 + 10, $y, $red, IG_FONT_BODY, $label);
                imagettftext($im, 15, 0, $x1 + 10 + $lw, $y, $ink, IG_FONT_BODY, substr($line, $colon + 1));
            } else {
                imagettftext($im, 15, 0, $x1 + 10, $y, $ink, IG_FONT_BODY, $line);
            }
            $y += 21;
        }
    }
    imagesetclip($im, 0, 0, imagesx($im) - 1, imagesy($im) - 1);
}

// ── October's darkroom accents ───────────────────────────────────────────────
// Spirit photography, in the safelight's one warm family: a print with
// something developing in it, eyes watching from the film's sprocket holes,
// and a strip of film with something approaching frame by frame.

// A cream ghost standing on $by at scale $s (about 44*$s tall), with dark eyes.
function ig_ghost_shape($im, $cx, $by, $s, $col, $eye) {
    $h = 44 * $s; $w = 34 * $s; $r = $w / 2; $top = $by - $h; $pts = [];
    for ($a = 180; $a <= 360; $a += 15) { $pts[] = (int) round($cx + $r * cos(deg2rad($a))); $pts[] = (int) round($top + $r + $r * sin(deg2rad($a))); }
    $pts[] = (int) round($cx + $r); $pts[] = (int) round($by);
    for ($i = 1; $i <= 8; $i++) { $pts[] = (int) round($cx + $r - $w * $i / 8); $pts[] = (int) round($by - ($i % 2 ? 0 : 5 * $s)); }
    imagefilledpolygon($im, $pts, $col);
    foreach ([-0.2, 0.2] as $dx) imagefilledellipse($im, (int) round($cx + $dx * $w), (int) round($top + $r + $s), (int) round(5 * $s + 1), (int) round(8 * $s + 1), $eye);
    imagefilledellipse($im, (int) $cx, (int) round($top + $r + 12 * $s), (int) round(5 * $s + 1), (int) round(7 * $s + 1), $eye);
}

// A print lying at $angle degrees with a ghost showing in it. $develop (0..1)
// is how far the image has come up in the developer: 0 is a blank, warmly lit
// print, 1 the ghost in full (the still). Instant film comes out of the camera
// as a milky white cloud that slowly clears, the picture showing through it
// first as faint outlines: $milk (0..1) is how much of that cloud is still
// over the image, and $flash (0..1) is a burst of white light round the print
// at the instant it is taken. All zero (the still) leaves the finished print.
//
// $subject says what is in the picture ('scream', the still, 'sonofman',
// 'psycho' or 'gothic' — each a famous picture or film with one of the October
// characters in the lead; 'ghost', 'cat', 'pumpkin' and 'cinema' are the plain
// originals) and $caption is what is written under it.
function ig_darkroom_print($im, $cx, $cy, $angle, $develop = 1.0, $milk = 0.0, $flash = 0.0, $subject = 'scream', $caption = 'booooo') {
    $W = 128; $H = 158;
    if ($flash > 0.02) {
        // Twenty faint rings (about 2% each) so the burst reads as a smooth
        // glow rather than bands, kept inside the header: clear of the date on
        // the left and of the rule underneath.
        imagesetclip($im, (int) $cx - 125, 20, (int) $cx + 135, 258);
        $ring = imagecolorallocatealpha($im, 0xFF, 0xF4, 0xE0, 127 - (int) round(3 * $flash));
        for ($g = 20; $g >= 1; $g--) imagefilledellipse($im, (int) $cx, (int) $cy, 100 + $g * 7, 118 + $g * 8, $ring);
        imagesetclip($im, 0, 0, imagesx($im) - 1, imagesy($im) - 1);
    }
    $S = imagecreatetruecolor($W + 30, $H + 30);
    imagesavealpha($S, true); imagealphablending($S, false);
    $clear = imagecolorallocatealpha($S, 0, 0, 0, 127);
    imagefill($S, 0, 0, $clear); imagealphablending($S, true);
    imagefilledrectangle($S, 15, 15, 15 + $W, 15 + $H, imagecolorallocate($S, 0xE9, 0xDF, 0xCB));
    $ix1 = 15 + 10; $iy1 = 15 + 10; $ix2 = 15 + $W - 10; $iy2 = 15 + $H - 32;
    imagefilledrectangle($S, $ix1, $iy1, $ix2, $iy2, imagecolorallocate($S, 0x1B, 0x12, 0x0C));
    $gx = (int) (($ix1 + $ix2) / 2);
    for ($i = 0; $i < 14; $i++) imagefilledellipse($S, $gx, (int) (($iy1 + $iy2) / 2) + 3, 90 - $i * 5, 100 - $i * 5, imagecolorallocatealpha($S, 0xE0, 0x8A, 0x3E, 118 - $i));
    $gb = $iy2 - 11;
    if ($develop > 0.02 && $subject === 'cat') {
        // A black cat in silhouette against the amber glow: ears, head and
        // shoulders, whiskers, and a pair of lit slit-pupilled eyes that come
        // up last, once the picture is mostly there.
        $a   = (int) round(127 * (1 - $develop));
        $blk = imagecolorallocatealpha($S, 0x0A, 0x07, 0x05, $a);
        imagesetclip($S, $ix1, $iy1, $ix2, $iy2);
        imagefilledellipse($S, $gx, $iy2 + 2, 84, 62, $blk);
        imagefilledellipse($S, $gx, $iy2 - 40, 54, 46, $blk);
        foreach ([-1, 1] as $m) {
            imagefilledpolygon($S, [$gx + $m * 25, $iy2 - 46, $gx + $m * 20, $iy2 - 84, $gx + $m * 4, $iy2 - 60], $blk);
            foreach ([-6, 0, 6] as $wy) imageline($S, $gx + $m * 18, $iy2 - 34 + (int) ($wy / 2), $gx + $m * 50, $iy2 - 38 + $wy, imagecolorallocatealpha($S, 0xE0, 0xA0, 0x60, (int) round(127 - 70 * $develop)));
        }
        $ea = max(0.0, min(1.0, ($develop - 0.55) / 0.45));
        foreach ([-1, 1] as $m) {
            $ex = $gx + $m * 10; $ey = $iy2 - 42;
            if ($ea > 0.02) {
                for ($g = 3; $g >= 1; $g--) imagefilledellipse($S, $ex, $ey, 12 + $g * 6, 9 + $g * 5, imagecolorallocatealpha($S, 0xFF, 0xB8, 0x4A, (int) round(127 - (127 - (116 - $g * 2)) * $ea)));
                imagefilledellipse($S, $ex, $ey, 12, 9, imagecolorallocatealpha($S, 0xFF, 0xC8, 0x5A, (int) round(127 * (1 - $ea))));
                imagefilledellipse($S, $ex, $ey, 2, 9, imagecolorallocatealpha($S, 0x0A, 0x07, 0x05, (int) round(127 * (1 - $ea))));
            }
        }
        imagesetclip($S, 0, 0, imagesx($S) - 1, imagesy($S) - 1);
    } elseif ($develop > 0.02 && $subject === 'scream') {
        // The Scream's setting — Munch's swirling bands of sky in the
        // safelight's ambers and rusts, the bridge, two small figures walking
        // away — with the ordinary little ghost standing where the screamer
        // stood.
        $a  = (int) round(127 * (1 - $develop));
        $fa = max(0.0, min(1.0, ($develop - 0.55) / 0.45));
        ['c' => $c, 'poly' => $poly, 'ell' => $ell, 'line' => $line, 'X' => $X, 'Y' => $Y, 'k' => $k] = ig_dr_tools($S, $ix1, $iy1, $a, 1.25, 56, 58);
        imagesetclip($S, $ix1, $iy1, $ix2, $iy2);
        foreach ([[0, 0x5A, 0x24, 0x18], [8, 0xB3, 0x3B, 0x1E], [17, 0xE0, 0x8A, 0x3E], [26, 0xF2, 0xB0, 0x4A], [35, 0xE0, 0x8A, 0x3E], [44, 0xB3, 0x3B, 0x1E], [52, 0x7A, 0x2A, 0x16], [60, 0x2A, 0x1E, 0x16]] as $bi => [$y0, $r, $g, $b]) {
            $pts = [];
            for ($x = -30; $x <= 140; $x += 4) $pts[] = [$x, $y0 + 4.5 * sin($x / 11.0 + $bi * 1.3)];
            $pts[] = [140, 140]; $pts[] = [-30, 140];
            $poly($pts, $c($r, $g, $b));
        }
        for ($i = 0; $i < 3; $i++) {
            for ($x = -30; $x <= 140; $x += 4) $line($x, 68 + $i * 7 + 2 * sin($x / 7.0 + $i), $x + 4, 68 + $i * 7 + 2 * sin(($x + 4) / 7.0 + $i), $c(0x4A, 0x32, 0x20));
        }
        // the bridge, running up to the right, with its railing
        $dark = $c(0x1A, 0x12, 0x0C);
        $poly([[-30, 98], [140, 53], [140, 67], [-30, 116]], $dark);
        $line(-30, 88, 140, 43, $dark, 2);
        foreach ([-20, -2, 16, 34, 52, 70, 88, 106, 124] as $px) $line($px, 88 - 0.268 * ($px + 30), $px, 98 - 0.268 * ($px + 30), $dark);
        foreach ([86, 94] as $bx) {
            $by = 98 - 0.268 * ($bx + 30);
            $poly([[$bx - 2, $by - 14], [$bx + 2, $by - 14], [$bx + 2.5, $by], [$bx - 2.5, $by]], $dark);
            $ell($bx, $by - 16, 2.3, 2.3, $dark); $poly([[$bx - 4, $by - 17], [$bx + 4, $by - 17], [$bx + 2, $by - 20], [$bx - 2, $by - 20]], $dark);
        }
        // the ghost: the original cute one, standing on the bridge. Its eyes
        // come up last.
        $ghostEye = imagecolorallocatealpha($S, 0x1B, 0x12, 0x0C, (int) round(127 * (1 - $fa)));
        ig_ghost_shape($S, $X(38), $Y(97), 1.15 * $k, $c(0xF0, 0xE6, 0xD8), $ghostEye);
        imagesetclip($S, 0, 0, imagesx($S) - 1, imagesy($S) - 1);
    } elseif ($develop > 0.02 && $subject === 'sonofman') {
        // Magritte's Son of Man, with the pumpkin for the apple: a man in a
        // dark overcoat, red tie and bowler hat before a low wall, a cloudy
        // sky and the sea behind him, a pumpkin hanging where his face
        // should be. Its carved face lights up last.
        $a  = (int) round(127 * (1 - $develop));
        $fa = max(0.0, min(1.0, ($develop - 0.55) / 0.45));
        ['c' => $c, 'poly' => $poly, 'ell' => $ell, 'rect' => $rect, 'line' => $line, 'X' => $X, 'Y' => $Y, 'k' => $k] = ig_dr_tools($S, $ix1, $iy1, $a, 1.25, 54, 60);
        imagesetclip($S, $ix1, $iy1, $ix2, $iy2);
        foreach ([[0, 0x4A, 0x3A, 0x2E], [14, 0x5E, 0x48, 0x34], [28, 0x76, 0x5A, 0x3C], [42, 0x92, 0x70, 0x46], [56, 0xA8, 0x84, 0x52]] as [$y0, $r, $g, $b]) $rect(-40, $y0 - ($y0 === 0 ? 40 : 0), 150, 74, $c($r, $g, $b));
        foreach ([[22, 34, 14, 5], [30, 38, 12, 4], [80, 24, 16, 5], [90, 48, 12, 4], [70, 58, 14, 4]] as [$x, $y, $rx, $ry]) $ell($x, $y, $rx, $ry, $c(0xC8, 0xA8, 0x78, min($a + 40, 127)));
        $rect(-40, 72, 150, 82, $c(0x2A, 0x20, 0x18));
        $rect(-40, 82, 150, 150, $c(0x4A, 0x36, 0x26));
        $line(-40, 82, 150, 82, $c(0x6E, 0x52, 0x38), 2);
        for ($x = -30; $x < 140; $x += 18) $line($x, 83, $x, 150, $c(0x38, 0x28, 0x1C));
        // the man
        $coat = $c(0x0C, 0x09, 0x07);
        $poly([[10, 118], [18, 86], [54, 74], [90, 86], [98, 118]], $coat);
        $poly([[44, 74], [54, 90], [64, 74]], $c(0xE6, 0xDE, 0xCC));
        $poly([[54, 82], [50, 90], [54, 114], [58, 90]], $c(0xB3, 0x3B, 0x1E)); $ell(54, 82.5, 3, 2.4, $c(0xB3, 0x3B, 0x1E));
        $poly([[44, 74], [48, 82], [40, 84]], $coat); $poly([[64, 74], [60, 82], [68, 84]], $coat);
        // the pumpkin where the face should be
        foreach ([[44.5, 56, 14, 17, 0xC8, 0x66, 0x1A], [63.5, 56, 14, 17, 0xC8, 0x66, 0x1A], [54, 56, 15, 18, 0xE0, 0x7A, 0x22]] as [$px, $py, $rx, $ry, $r, $g, $b]) $ell($px, $py, $rx, $ry, $c($r, $g, $b));
        foreach ([44.5, 63.5] as $px) imageellipse($S, $X($px), $Y(56), (int) round(28 * $k), (int) round(34 * $k), $c(0x8A, 0x3A, 0x0C));
        // the bowler hat
        $poly([[40, 40], [68, 40], [66, 22], [54, 17], [42, 22]], $coat); $ell(54, 26, 14.5, 10, $coat);
        $ell(54, 39, 22, 4.2, $coat); $line(40, 36, 68, 36, $c(0x2A, 0x21, 0x18), 2);
        // the leaf, in front of the brim
        $poly([[60, 41], [68, 38], [66, 45]], $c(0x5E, 0x6B, 0x2A)); $line(57, 42, 62, 41, $c(0x5E, 0x6B, 0x2A), 2);
        if ($fa > 0.02) {
            for ($g = 4; $g >= 1; $g--) imagefilledellipse($S, $X(54), $Y(56), (int) round((38 + $g * 6) * $k), (int) round((44 + $g * 6) * $k), imagecolorallocatealpha($S, 0xFF, 0xC0, 0x40, (int) round(127 - (127 - (121 - $g)) * $fa)));
            $lit = imagecolorallocatealpha($S, 0xFF, 0xDC, 0x6A, (int) round(127 * (1 - $fa)));
            foreach ([-1, 1] as $m) $poly([[54 + $m * 14, 52], [54 + $m * 5, 52], [54 + $m * 9.5, 41]], $lit);
            $poly([[51, 58], [57, 58], [54, 53]], $lit);
            $poly([[40, 63], [47, 70], [51, 64], [54, 71], [57, 64], [61, 70], [68, 63]], $lit);
        }
        imagesetclip($S, 0, 0, imagesx($S) - 1, imagesy($S) - 1);
    } elseif ($develop > 0.02 && $subject === 'psycho') {
        // Psycho: the Bates house on its hill under the moon — mansard roof,
        // steep gable, a porch — with a figure standing in the lit upstairs
        // window. The windows and the door light last.
        $a   = (int) round(127 * (1 - $develop));
        $fa  = max(0.0, min(1.0, ($develop - 0.55) / 0.45));
        ['c' => $c, 'poly' => $poly, 'ell' => $ell, 'rect' => $rect, 'line' => $line] = ig_dr_tools($S, $ix1, $iy1, $a);
        imagesetclip($S, $ix1, $iy1, $ix2, $iy2);
        for ($g = 4; $g >= 1; $g--) $ell(26, 24, 15 + $g * 4, 15 + $g * 4, imagecolorallocatealpha($S, 0xFF, 0xE2, 0xA0, (int) round(127 - (127 - (118 - $g)) * $develop)));
        $ell(26, 24, 15, 15, $c(0xF6, 0xE6, 0xB8));
        $blk = $c(0x0A, 0x07, 0x05);
        foreach ([[40, 18, 9], [50, 28, 6]] as [$bx, $by, $bs]) ig_bat_silhouette($S, $ix1 + $bx, $iy1 + $by, $bs, $blk);
        if ($fa > 0.02) {
            for ($g = 4; $g >= 1; $g--) $ell(54, 62, 40 + $g * 9, 50 + $g * 9, imagecolorallocatealpha($S, 0xFF, 0xB8, 0x40, (int) round(127 - (127 - (120 - $g)) * $fa)));
        }
        $poly([[-2, 102], [18, 94], [54, 88], [90, 94], [110, 100], [110, 118], [-2, 118]], $blk);
        $rect(30, 58, 78, 94, $blk);
        $poly([[26, 58], [32, 44], [76, 44], [82, 58]], $blk); $rect(32, 40, 76, 44, $blk);
        $poly([[44, 58], [54, 26], [64, 58]], $blk); $line(54, 26, 54, 17, $blk, 2);
        $poly([[36, 46], [40, 38], [44, 46]], $blk); $poly([[64, 46], [68, 38], [72, 46]], $blk);
        $rect(72, 34, 77, 46, $blk);
        $rect(78, 74, 94, 94, $blk); $poly([[76, 74], [86, 64], [96, 74]], $blk);
        imagesetthickness($S, 2);
        foreach ([[10, 100, 10, 66], [10, 82, 2, 70], [10, 88, 18, 74], [10, 72, 14, 62], [10, 76, 4, 62]] as [$x1, $y1, $x2, $y2]) imageline($S, $ix1 + $x1, $iy1 + $y1, $ix1 + $x2, $iy1 + $y2, $blk);
        imagesetthickness($S, 1);
        if ($fa > 0.02) {
            $lit = imagecolorallocatealpha($S, 0xFF, 0xD8, 0x62, (int) round(127 * (1 - $fa)));
            $rect(49, 42, 59, 58, $lit);                           // the gable window...
            $rect(35, 62, 40, 74, $lit); $rect(68, 62, 73, 74, $lit); $rect(50, 82, 58, 94, $lit); $rect(82, 80, 88, 88, $lit);
            $fig = imagecolorallocatealpha($S, 0x0A, 0x07, 0x05, (int) round(127 * (1 - $fa)));
            $ell(54, 48.5, 2.8, 3.4, $fig); $ell(54, 44.6, 1.7, 1.5, $fig); $poly([[50, 58], [51, 53.5], [57, 53.5], [58, 58]], $fig);  // ...and who is standing in it
        }
        imagesetclip($S, 0, 0, imagesx($S) - 1, imagesy($S) - 1);
    } elseif ($develop > 0.02 && $subject === 'gothic') {
        // American Gothic, with a skeleton for the farmer (and his pitchfork)
        // and a zombie for his daughter, in front of the farmhouse's pointed
        // window. Drawn in a 108 x 116 design space, then zoomed in $k times
        // about ($fx, $fy) so the faces fill the little picture: every point,
        // radius and line weight goes through that. The bodies come up first;
        // the window lights and the zombie's eyes catch last.
        $a  = (int) round(127 * (1 - $develop));
        $fa = max(0.0, min(1.0, ($develop - 0.55) / 0.45));
        $ox = $ix1; $oy = $iy1; $k = 1.45; $fx = 54; $fy = 60;
        $X = fn($x) => (int) round($ox + $fx + ($x - $fx) * $k);
        $Y = fn($y) => (int) round($oy + $fy + ($y - $fy) * $k);
        $c   = fn($r, $g, $b, $al = null) => imagecolorallocatealpha($S, $r, $g, $b, $al ?? $a);
        $poly = function (array $pts, $col) use ($S, $X, $Y) { $o = []; foreach ($pts as [$x, $y]) { $o[] = $X($x); $o[] = $Y($y); } imagefilledpolygon($S, $o, $col); };
        $ell  = fn($x, $y, $rx, $ry, $col) => imagefilledellipse($S, $X($x), $Y($y), (int) round($rx * 2 * $k), (int) round($ry * 2 * $k), $col);
        $rect = fn($x1, $y1, $x2, $y2, $col) => imagefilledrectangle($S, $X($x1), $Y($y1), $X($x2), $Y($y2), $col);
        $line = function ($x1, $y1, $x2, $y2, $col, $t = 1) use ($S, $X, $Y, $k) { imagesetthickness($S, max(1, (int) round($t * $k))); imageline($S, $X($x1), $Y($y1), $X($x2), $Y($y2), $col); imagesetthickness($S, 1); };
        $wall = $c(0x22, 0x17, 0x0E); $plank = $c(0x2C, 0x1E, 0x12); $black = $c(0x0C, 0x09, 0x07);
        $bone = $c(0xF0, 0xE6, 0xD0); $boneSh = $c(0xB8, 0xAC, 0x92); $fork = $c(0xC8, 0xBE, 0xA6);
        $skin = $c(0x8F, 0xA6, 0x7A); $skinD = $c(0x62, 0x7A, 0x52); $hair = $c(0x1E, 0x18, 0x14); $dress = $c(0x1D, 0x18, 0x15);
        $blood = $c(0x6E, 0x1E, 0x1A); $white = $c(0xE6, 0xDE, 0xCC); $trim = $c(0x3A, 0x32, 0x2A);
        imagesetclip($S, $ix1, $iy1, $ix2, $iy2);
        // the farmhouse wall and its pointed window (dark until it lights)
        $rect(4, 8, 104, 116, $wall);
        for ($y = 12; $y < 116; $y += 6) $line(4, $y, 104, $y, $plank);
        $win = [[48, 66], [48, 40], [55, 26], [62, 40], [62, 66]];
        $poly($win, $c(0x3A, 0x2A, 0x1C));
        if ($fa > 0.02) {
            for ($g = 4; $g >= 1; $g--) imagefilledellipse($S, $X(55), $Y(46), (int) round((14 + $g * 9) * $k), (int) round((40 + $g * 9) * $k), imagecolorallocatealpha($S, 0xFF, 0xB8, 0x40, (int) round(127 - (127 - (124 - $g)) * $fa)));
            $poly($win, imagecolorallocatealpha($S, 0xFF, 0xC2, 0x4A, (int) round(127 * (1 - $fa))));
            $mun = imagecolorallocatealpha($S, 0xC9, 0x84, 0x20, (int) round(127 * (1 - $fa)));
            $line(55, 26, 55, 66, $mun); $line(48, 52, 62, 52, $mun); $line(48, 40, 62, 40, $mun);
        }
        // him: the skeleton
        $poly([[18, 116], [19, 76], [29, 66], [38, 68], [47, 66], [57, 76], [58, 116]], $black);
        $poly([[32, 66], [38, 82], [44, 66]], $white);
        $line(32, 66, 36, 70, $boneSh); $line(44, 66, 40, 70, $boneSh);
        $rect(36, 56, 40, 66, $bone); foreach ([58, 61, 64] as $vy) $line(35, $vy, 41, $vy, $boneSh);
        $ell(38, 45, 10, 11, $bone); $rect(32, 50, 44, 59, $bone);
        $ell(33, 44, 3, 3.6, $black); $ell(43, 44, 3, 3.6, $black);
        $poly([[38, 48], [36, 52], [40, 52]], $black);
        $line(32, 55, 44, 55, $black);
        foreach ([34, 36, 38, 40, 42] as $tx) $line($tx, 55, $tx, 59, $boneSh);
        $line(26, 30, 26, 116, $fork, 2);
        foreach ([20, 26, 32] as $px) $line($px, 20, $px, 36, $fork, 2);
        $line(20, 36, 32, 36, $fork, 2);
        $rect(23, 84, 29, 88, $bone); foreach ([24, 26, 28] as $hx) $line($hx, 88, $hx, 91, $bone);
        // her: the zombie
        $poly([[56, 116], [58, 88], [64, 80], [72, 82], [80, 80], [86, 88], [88, 116]], $dress);
        $line(68, 84, 68, 116, $trim); $line(76, 84, 76, 116, $trim);
        $rect(69, 70, 75, 80, $skinD);
        $ell(72, 80, 8, 3.4, $white); $ell(72, 83, 1.6, 1.6, $c(0xD8, 0xC8, 0x9C));
        $ell(72, 46, 10, 8, $hair); $ell(72, 36, 4.5, 3.5, $hair);
        $ell(72, 57, 9.5, 12, $skin);
        $ell(72, 47, 10.2, 6.5, $hair); $line(72, 40, 72, 47, $c(0x3A, 0x30, 0x28));
        $ell(66, 60, 3, 4, $skinD);
        $ell(67, 57, 2.4, 2.8, $black); $ell(77, 57, 2.4, 2.8, $black);
        $line(68, 66, 76, 66, $black);
        foreach ([69, 72, 75] as $sx) $line($sx, 64, $sx, 68, $black);
        $ell(78, 62, 2.2, 3.2, $blood); $line(77, 59, 79, 65, $black);
        if ($fa > 0.02) {
            $glint = imagecolorallocatealpha($S, 0xC8, 0xE0, 0x7A, (int) round(127 * (1 - $fa)));
            $ell(67, 57, 0.9, 0.9, $glint); $ell(77, 57, 0.9, 0.9, $glint);
        }
        imagesetclip($S, 0, 0, imagesx($S) - 1, imagesy($S) - 1);
    } elseif ($develop > 0.02 && $subject === 'cinema') {
        // A haunted cinema against a big moon: a black silhouette with a
        // peaked tower whose two slanted windows are eyes and whose lit marquee
        // is a jagged grin, a gabled wing either side, a bare tree and two
        // bats. The lit parts (eyes, grin, windows, door) come up last.
        $a   = (int) round(127 * (1 - $develop));
        $blk = imagecolorallocatealpha($S, 0x0A, 0x07, 0x05, $a);
        $gb  = $iy2 - 6;
        imagesetclip($S, $ix1, $iy1, $ix2, $iy2);
        // moon, with a halo
        $mx = $gx - 30; $my = $iy1 + 28;
        for ($g = 4; $g >= 1; $g--) imagefilledellipse($S, $mx, $my, 34 + $g * 7, 34 + $g * 7, imagecolorallocatealpha($S, 0xFF, 0xE2, 0xA0, (int) round(127 - (127 - (118 - $g)) * $develop)));
        imagefilledellipse($S, $mx, $my, 34, 34, imagecolorallocatealpha($S, 0xF6, 0xE6, 0xB8, (int) round(127 - 127 * min(1.0, $develop * 1.2))));
        foreach ([[$mx + 4, $my - 22, 9], [$mx + 14, $my - 14, 6]] as [$bx, $by, $bs]) ig_bat_silhouette($S, $bx, $by, $bs, $blk);
        // the lit windows' glow, behind the building so only a rim of it shows
        $fa = max(0.0, min(1.0, ($develop - 0.55) / 0.45));
        if ($fa > 0.02) {
            for ($g = 4; $g >= 1; $g--) imagefilledellipse($S, $gx, $gb - 34, 56 + $g * 11, 70 + $g * 11, imagecolorallocatealpha($S, 0xFF, 0xB8, 0x40, (int) round(127 - (127 - (119 - $g)) * $fa)));
        }
        // building
        imagefilledrectangle($S, $ix1, $gb, $ix2, $iy2, $blk);
        imagefilledrectangle($S, $gx - 52, $gb - 34, $gx - 20, $gb, $blk);
        imagefilledpolygon($S, [$gx - 54, $gb - 34, $gx - 36, $gb - 52, $gx - 18, $gb - 34], $blk);
        imagefilledrectangle($S, $gx + 20, $gb - 30, $gx + 52, $gb, $blk);
        imagefilledpolygon($S, [$gx + 18, $gb - 30, $gx + 36, $gb - 44, $gx + 54, $gb - 30], $blk);
        imagefilledrectangle($S, $gx + 42, $gb - 52, $gx + 47, $gb - 38, $blk);
        imagefilledrectangle($S, $gx - 20, $gb - 56, $gx + 20, $gb, $blk);
        imagefilledpolygon($S, [$gx - 24, $gb - 56, $gx, $gb - 86, $gx + 24, $gb - 56], $blk);
        imagesetthickness($S, 2); imageline($S, $gx, $gb - 86, $gx, $gb - 97, $blk); imagesetthickness($S, 1);
        imagefilledpolygon($S, [$gx - 25, $gb - 22, $gx + 25, $gb - 22, $gx + 21, $gb - 8, $gx - 21, $gb - 8], $blk);
        // bare tree, foreground left
        imagefilledpolygon($S, [$ix1 + 5, $gb, $ix1 + 8, $gb - 40, $ix1 + 13, $gb], $blk);
        imagesetthickness($S, 2);
        foreach ([[8, 28, 0, 46], [9, 33, 20, 52], [8, 40, 10, 62], [3, 40, -3, 52], [16, 46, 24, 56]] as [$x1, $y1, $x2, $y2]) imageline($S, $ix1 + $x1, $gb - $y1, $ix1 + $x2, $gb - $y2, $blk);
        imagesetthickness($S, 1);
        // the lights
        if ($fa > 0.02) {
            $lit = imagecolorallocatealpha($S, 0xFF, 0xD8, 0x62, (int) round(127 * (1 - $fa)));
            imagefilledpolygon($S, [$gx - 16, $gb - 45, $gx - 5, $gb - 38, $gx - 7, $gb - 32, $gx - 16, $gb - 35], $lit);
            imagefilledpolygon($S, [$gx + 16, $gb - 45, $gx + 5, $gb - 38, $gx + 7, $gb - 32, $gx + 16, $gb - 35], $lit);
            imagefilledpolygon($S, [$gx - 19, $gb - 21, $gx + 19, $gb - 21, $gx + 17, $gb - 16, $gx + 12, $gb - 10, $gx + 6, $gb - 16, $gx, $gb - 10, $gx - 6, $gb - 16, $gx - 12, $gb - 10, $gx - 17, $gb - 16], $lit);
            imagefilledrectangle($S, $gx - 4, $gb - 7, $gx + 4, $gb, $lit);
            foreach ([[-46, -26], [-33, -26], [28, -22], [40, -22]] as [$wx, $wy]) imagefilledrectangle($S, $gx + $wx, $gb + $wy, $gx + $wx + 5, $gb + $wy + 7, $lit);
        }
        imagesetclip($S, 0, 0, imagesx($S) - 1, imagesy($S) - 1);
    } elseif ($develop > 0.02 && $subject === 'pumpkin') {
        // A jack-o'-lantern: the carved face lights up last, once the pumpkin
        // itself has come up out of the dark.
        $a  = (int) round(127 * (1 - $develop));
        $pc = $iy2 - 38;
        imagesetclip($S, $ix1, $iy1, $ix2, $iy2);
        foreach ([[-15, 38, 52, 0xC8, 0x66, 0x1A], [15, 38, 52, 0xC8, 0x66, 0x1A], [0, 40, 56, 0xE0, 0x7A, 0x22]] as [$dx, $lw, $lh, $r, $g, $b]) {
            imagefilledellipse($S, $gx + $dx, $pc, $lw, $lh, imagecolorallocatealpha($S, $r, $g, $b, $a));
        }
        foreach ([-15, 15] as $dx) imageellipse($S, $gx + $dx, $pc, 38, 52, imagecolorallocatealpha($S, 0x8A, 0x3A, 0x0C, $a));
        imagefilledpolygon($S, [$gx - 3, $pc - 26, $gx - 5, $pc - 40, $gx + 6, $pc - 42, $gx + 4, $pc - 26], imagecolorallocatealpha($S, 0x5E, 0x6B, 0x2A, $a));
        $fa = max(0.0, min(1.0, ($develop - 0.55) / 0.45));
        if ($fa > 0.02) {
            for ($g = 4; $g >= 1; $g--) imagefilledellipse($S, $gx, $pc + 2, 40 + $g * 9, 34 + $g * 8, imagecolorallocatealpha($S, 0xFF, 0xC0, 0x40, (int) round(127 - (127 - (118 - $g * 2)) * $fa)));
            $lit = imagecolorallocatealpha($S, 0xFF, 0xDC, 0x6A, (int) round(127 * (1 - $fa)));
            foreach ([-1, 1] as $m) imagefilledpolygon($S, [$gx + $m * 17, $pc - 6, $gx + $m * 6, $pc - 6, $gx + $m * 12, $pc - 19], $lit);
            imagefilledpolygon($S, [$gx - 3, $pc + 1, $gx + 3, $pc + 1, $gx, $pc - 5], $lit);
            imagefilledpolygon($S, [$gx - 19, $pc + 9, $gx - 12, $pc + 17, $gx - 6, $pc + 11, $gx, $pc + 18, $gx + 6, $pc + 11, $gx + 12, $pc + 17, $gx + 19, $pc + 9], $lit);
        }
        imagesetclip($S, 0, 0, imagesx($S) - 1, imagesy($S) - 1);
    } elseif ($develop > 0.02) {
        $a = fn($full) => (int) round(127 - (127 - $full) * $develop);   // alpha of the ghost at $full, scaled in
        for ($g = 3; $g >= 1; $g--) ig_ghost_shape($S, $gx, $gb + 2, 1.45 + $g * 0.14, imagecolorallocatealpha($S, 0xF0, 0xE6, 0xD8, $a(118)), imagecolorallocatealpha($S, 0, 0, 0, 127));
        ig_ghost_shape($S, $gx, $gb, 1.45, imagecolorallocatealpha($S, 0xF0, 0xE6, 0xD8, $a(25)), imagecolorallocate($S, 0x1B, 0x12, 0x0C));
    }
    mt_srand(5);
    for ($i = 0; $i < 600; $i++) imagesetpixel($S, mt_rand($ix1, $ix2), mt_rand($iy1, $iy2), imagecolorallocatealpha($S, 0xE0, 0xC0, 0x90, mt_rand(90, 120)));
    if ($milk > 0.01) {
        // The cloud: opaque milky white at 1, clear at 0, over the picture only.
        imagefilledrectangle($S, $ix1, $iy1, $ix2, $iy2, imagecolorallocatealpha($S, 0xF3, 0xEE, 0xE2, (int) round(127 * (1 - min(1.0, $milk)))));
    }
    imagettftext($S, 9, 0, 15 + 11, 15 + $H - 11, imagecolorallocate($S, 0x5A, 0x44, 0x30), IG_FONT_BODY, $caption);
    $R = imagerotate($S, $angle, $clear);
    imagesavealpha($R, true);
    imagecopy($im, $R, (int) ($cx - imagesx($R) / 2), (int) ($cy - imagesy($R) / 2), 0, 0, imagesx($R), imagesy($R));
}

// A pair of amber slit-pupilled eyes looking out of a sprocket hole centred
// at ($x, $cy). $level (0..1) fades them in and out of the dark, $open
// (0..1) is the lid (a blink is a frame or two near 0), $look (-2..2) slides
// the pupils sideways.
function ig_sprocket_eyes($im, $x, $cy, $level = 1.0, $open = 1.0, $look = 0) {
    if ($level < 0.05) return;
    $hh = max(1, (int) round(7 * $open));
    foreach ([-7, 7] as $dx) {
        for ($g = 3; $g >= 1; $g--) {
            imagefilledellipse($im, $x + $dx, $cy, 8 + $g * 5, $hh + $g * 5, imagecolorallocatealpha($im, 0xE0, 0x8A, 0x3E, (int) round(127 - (127 - (118 - $g * 3)) * $level)));
        }
        imagefilledellipse($im, $x + $dx, $cy, 9, $hh, imagecolorallocatealpha($im, 0xFF, 0xB0, 0x5C, (int) round(127 * (1 - $level))));
        if ($open > 0.4) imagefilledellipse($im, $x + $dx + $look, $cy, 2, $hh, imagecolorallocatealpha($im, 0x12, 0x0D, 0x0A, (int) round(127 * (1 - $level))));
    }
}

// Drawing tools for one darkroom picture, all in its own 108 x 116 design
// space (origin at the picture's top-left corner at ($ox, $oy)) and all at
// colour alpha $a unless a colour says otherwise — which is how a picture
// comes up out of the developer: $a falls from 127 (nothing) to 0 (solid).
// $k zooms the whole picture in about the design-space point ($fx, $fy):
// every point, radius and line weight goes through it, so a picture drawn at
// 1x can be framed tighter without being redrawn. 'X'/'Y' map a point for
// the odd call that has to talk to GD directly.
function ig_dr_tools($S, $ox, $oy, $a, $k = 1.0, $fx = 54, $fy = 58) {
    $X = fn($x) => (int) round($ox + $fx + ($x - $fx) * $k);
    $Y = fn($y) => (int) round($oy + $fy + ($y - $fy) * $k);
    return [
        'c'    => fn($r, $g, $b, $al = null) => imagecolorallocatealpha($S, $r, $g, $b, $al ?? $a),
        'X'    => $X, 'Y' => $Y, 'k' => $k,
        'poly' => function (array $pts, $col) use ($S, $X, $Y) { $o = []; foreach ($pts as [$x, $y]) { $o[] = $X($x); $o[] = $Y($y); } imagefilledpolygon($S, $o, $col); },
        'ell'  => fn($x, $y, $rx, $ry, $col) => imagefilledellipse($S, $X($x), $Y($y), (int) round($rx * 2 * $k), (int) round($ry * 2 * $k), $col),
        'rect' => fn($x1, $y1, $x2, $y2, $col) => imagefilledrectangle($S, $X($x1), $Y($y1), $X($x2), $Y($y2), $col),
        'line' => function ($x1, $y1, $x2, $y2, $col, $th = 1) use ($S, $X, $Y, $k) { imagesetthickness($S, max(1, (int) round($th * $k))); imageline($S, $X($x1), $Y($y1), $X($x2), $Y($y2), $col); imagesetthickness($S, 1); },
    ];
}

// ── The film in the footer ───────────────────────────────────────────────────
// A loop of film eight frames long: a four-frame countdown leader (4, 3, 2, 1),
// then a hallway with a ghost coming down it, bigger in each of the next four
// frames — and then, the film being a loop, the countdown again. The strip
// shows whichever four are in view.
const IG_REEL_FRAMES = 8;

// The hallway, in one-point perspective, in an 88 x 52 frame at ($ox, $fy):
// $flicker (0..1) is how bright the lights are, $o (0..1) how far the door at the
// far end is open.
function ig_reel_hall($im, $ox, $fy, $flicker, $o) {
    $col = fn($r, $g, $b, $al = 0) => imagecolorallocatealpha($im, (int) $r, (int) $g, (int) $b, $al);
    $d   = fn($r, $g, $b) => $col(round($r * $flicker), round($g * $flicker), round($b * $flicker));
    $poly = function (array $pts, $c) use ($im, $ox, $fy) { $q = []; foreach ($pts as [$x, $y]) { $q[] = (int) round($ox + $x); $q[] = (int) round($fy + $y); } imagefilledpolygon($im, $q, $c); };
    $poly([[0, 0], [34, 12], [34, 30], [0, 52]], $d(0x4A, 0x32, 0x1C));
    $poly([[88, 0], [54, 12], [54, 30], [88, 52]], $d(0x4A, 0x32, 0x1C));
    $poly([[0, 0], [88, 0], [54, 12], [34, 12]], $d(0x2A, 0x1C, 0x10));
    $poly([[34, 12], [54, 12], [54, 30], [34, 30]], $d(0x36, 0x24, 0x16));
    $poly([[34, 30], [54, 30], [88, 52], [0, 52]], $d(0x66, 0x44, 0x26));
    foreach ([8, 28, 44, 60, 80] as $lx) imageline($im, (int) round($ox + 44), $fy + 30, (int) round($ox + $lx), $fy + 52, $d(0x80, 0x58, 0x32));
    $poly([[39, 16], [49, 16], [49, 30], [39, 30]], $col(0x0F, 0x0A, 0x07));
    if ($o > 0) {
        imagefilledellipse($im, (int) round($ox + 44), $fy + 23, (int) round(16 + 16 * $o), (int) round(18 + 16 * $o), $col(0xFF, 0xB8, 0x40, (int) round(127 - 60 * $o)));
        $poly([[44 - 4 * $o, 30], [44 + 4 * $o, 30], [44 + 22 * $o, 52], [44 - 22 * $o, 52]], $col(0xFF, 0xB8, 0x40, 104));
        $poly([[44 - 5 * $o, 16], [44 + 5 * $o, 16], [44 + 5 * $o, 30], [44 - 5 * $o, 30]], $col(0xFF, 0xC2, 0x4A));
    }
}

// Frame $idx of the film, drawn into the $W x $H box at ($fx, $fy) (clipped
// to $clipX1..$clipX2 horizontally, the part of it that is on the strip).
function ig_reel_frame($im, $fx, $fy, $W, $H, $idx, $clipX1, $clipX2) {
    $col  = fn($r, $g, $b, $al = 0) => imagecolorallocatealpha($im, (int) $r, (int) $g, (int) $b, $al);
    $x1 = max($fx, $clipX1); $x2 = min($fx + $W - 1, $clipX2);
    if ($x2 <= $x1) return;
    imagesetclip($im, $x1, $fy, $x2, $fy + $H - 1);
    imagefilledrectangle($im, $fx, $fy, $fx + $W - 1, $fy + $H - 1, $col(0x0A, 0x07, 0x05));
    $cream = $col(0xF0, 0xE6, 0xD8);
    if ($idx >= 0 && $idx <= 3) {
        // The countdown leader: a ring, crosshairs and a big numeral.
        $cx = $fx + $W / 2; $cy = $fy + $H / 2; $amber = $col(0xE0, 0x8A, 0x3E);
        imageline($im, $fx, (int) $cy, $fx + $W - 1, (int) $cy, $col(0x5A, 0x3A, 0x1E));
        imageline($im, (int) $cx, $fy, (int) $cx, $fy + $H - 1, $col(0x5A, 0x3A, 0x1E));
        imagesetthickness($im, 2); imageellipse($im, (int) $cx, (int) $cy, 42, 42, $amber); imagesetthickness($im, 1);
        imageellipse($im, (int) $cx, (int) $cy, 34, 34, $col(0x7A, 0x4E, 0x26));
        $n = (string) (4 - $idx);
        $b = imagettfbbox(26, 0, IG_FONT_DARKROOM_TITLE, $n);
        imagettftext($im, 26, 0, (int) round($cx - ($b[2] - $b[0]) / 2 - $b[0]), (int) round($cy + ($b[1] - $b[7]) / 2 - $b[1]), $cream, IG_FONT_DARKROOM_TITLE, $n);
    } elseif ($idx >= 4 && $idx <= 7) {
        // The ghost, coming down the hall: small and far, then closer, then
        // right up at the lens with its eyes lit.
        $k = $idx - 4;
        ig_reel_hall($im, $fx, $fy, 0.85, 1.0);
        $sc = [0.22, 0.5, 1.05, 2.4][$k];
        $by = $sc <= 1.0 ? 30 + 22 * $sc : 52 + ($sc - 1) * 30;
        $glow = $k >= 2;
        if ($glow) imagefilledellipse($im, (int) round($fx + $W / 2), (int) round($fy + $by - 20 * $sc), (int) round(34 * $sc + 14), (int) round(44 * $sc + 14), $col(0xFF, 0xB8, 0x40, 118));
        ig_ghost_shape($im, $fx + $W / 2, $fy + $by, $sc, $cream, $glow ? $col(0xFF, 0xC2, 0x4A) : $col(0x1B, 0x12, 0x0C));
    }
    imagesetclip($im, 0, 0, imagesx($im) - 1, imagesy($im) - 1);
}

// The strip itself: four frames visible, the film running through. $p is the
// (fractional) index of the film frame sitting in the gate — the second slot,
// outlined in amber, the other three dimmed — so the countdown reads as
// 4, 3, 2, 1 passing through it. The film is a loop, so $p and $p + 8 look
// the same. The sprocket holes travel with the film.
// The still shows the film caught with the ghost close.
function ig_darkroom_reel($im, $x1, $y1, $x2, $y2, $p = 6.0) {
    $bar = imagecolorallocate($im, 0x1D, 0x16, 0x11); $hole = imagecolorallocate($im, 0x12, 0x0D, 0x0A);
    imagefilledrectangle($im, $x1, $y1, $x2, $y2, $bar);
    $fw = ($x2 - $x1 - 10) / 4; $sp = $fw / 6;
    imagesetclip($im, $x1, $y1, $x2, $y2);
    $kmin = (int) floor((-14 - (1 - $p) * $fw) / $sp);
    for ($k = $kmin; $k < $kmin + 48; $k++) {
        $hx = (int) round($x1 + 6 + $k * $sp + (1 - $p) * $fw);
        if ($hx < $x1 - 8 || $hx > $x2) continue;
        imagefilledrectangle($im, $hx, $y1 + 4, $hx + 7, $y1 + 9, $hole);
        imagefilledrectangle($im, $hx, $y2 - 9, $hx + 7, $y2 - 4, $hole);
    }
    $fy1 = $y1 + 13; $fy2 = $y2 - 13; $W = (int) round($fw - 4); $H = $fy2 - $fy1 + 1;
    for ($m = (int) floor($p) - 2; $m <= (int) floor($p) + 3; $m++) {
        $X = $x1 + 5 + ($m - $p + 1) * $fw;
        ig_reel_frame($im, (int) round($X + 2), $fy1, $W, $H, (($m % IG_REEL_FRAMES) + IG_REEL_FRAMES) % IG_REEL_FRAMES, $x1 + 4, $x2 - 4);
    }
    imagesetclip($im, $x1, $y1, $x2, $y2);
    $dim = imagecolorallocatealpha($im, 0x0A, 0x07, 0x05, 70);
    foreach ([0, 2, 3] as $slot) imagefilledrectangle($im, (int) round($x1 + 5 + $slot * $fw), $fy1 - 1, (int) round($x1 + 5 + ($slot + 1) * $fw), $fy2 + 1, $dim);
    $amber = imagecolorallocate($im, 0xE0, 0x8A, 0x3E);
    imagesetthickness($im, 2);
    imagerectangle($im, (int) round($x1 + 5 + $fw) + 1, $fy1 - 2, (int) round($x1 + 5 + 2 * $fw) - 1, $fy2 + 2, $amber);
    imagesetthickness($im, 1);
    imagesetclip($im, 0, 0, imagesx($im) - 1, imagesy($im) - 1);
}

// ── October's zine accents ───────────────────────────────────────────────────
// Cut-and-paste, photocopied, taped: a ransom-note headline, blood running
// from the masthead bar, and a xerox skull on a black card.

// imagerotate(), but never called with an angle of zero (or, if $guardSmall, a
// hair off it): this GD build crashes the whole process on those, and a
// rotation that small is no rotation, so the image comes back as it is.
function ig_rotate($img, $ang, $clear, $guardSmall = false) {
    if ($ang == 0.0 || ($guardSmall && abs($ang) < 0.5)) return $img;
    $R = imagerotate($img, $ang, $clear); imagesavealpha($R, true);
    return $R;
}

// Text as a ransom note: every letter its own cut-out tile, in a different
// font and tile colour, at its own size and tilt with a slightly ragged cut.
// Runs left to right from $x with the tiles' baseline near $y; $seed makes the
// jumble the same every time. Returns the x the note ends at.
// $frame (a video frame, or null for the still) re-poses every tile every other
// frame — a nudge of tilt and position each, as if someone were moving the
// paper between shots of a stop-motion film. The jitter comes from a hash, not
// from mt_rand(), so the jumble itself is identical with or without it.
function ig_zine_ransom($im, $x, $y, $text, $size, $seed, $frame = null, $jit = 1.0) {
    $dir = dirname(__DIR__) . '/assets/fonts/';
    $fonts = ['Anton-Regular.ttf', 'Fraunces-Bold.ttf', 'RobotoSlab-Bold.ttf', 'SpecialElite-Regular.ttf', 'Baloo2-Bold.ttf', 'AllertaStencil-Regular.ttf', 'InstrumentSans-SemiBold.ttf'];
    $tiles = [['FBF9F2', '161513'], ['161513', 'F0EEE4'], ['FF3D8A', '161513'], ['E3D8BC', '161513'], ['FBF9F2', 'FF3D8A'], ['2A2724', 'FBF9F2']];
    $hex = fn($T, $h) => imagecolorallocate($T, hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2)));
    mt_srand($seed);
    $cx = $x; $li = 0;
    $f2 = $frame === null ? 0 : intdiv($frame, 2);
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        if ($ch === ' ') { $cx += $size * 0.55; continue; }
        $li++;
        $font = $dir . $fonts[mt_rand(0, count($fonts) - 1)];
        $sz = (int) round($size * (0.78 + mt_rand(0, 40) / 100));
        $b = imagettfbbox($sz, 0, $font, $ch);
        $tw = $b[2] - $b[0]; $th = $b[1] - $b[7]; $pad = 7; $W = $tw + 2 * $pad; $H = $th + 2 * $pad;
        $T = imagecreatetruecolor($W + 10, $H + 10); imagesavealpha($T, true); imagealphablending($T, false);
        $clear = imagecolorallocatealpha($T, 0, 0, 0, 127); imagefill($T, 0, 0, $clear); imagealphablending($T, true);
        [$bg, $fg] = $tiles[mt_rand(0, count($tiles) - 1)];
        $j = fn() => mt_rand(-2, 2);
        imagefilledpolygon($T, [5 + $j(), 5 + $j(), 5 + $W + $j(), 5 + $j(), 5 + $W + $j(), 5 + $H + $j(), 5 + $j(), 5 + $H + $j()], $hex($T, $bg));
        imagettftext($T, $sz, 0, 5 + $pad - $b[0], 5 + $pad - $b[7], $hex($T, $fg), $font, $ch);
        $jr = fn($k) => (crc32("$seed:$li:$f2:$k") % 1000) / 1000 - 0.5;
        $ja = $frame === null ? 0.0 : 5 * $jit * $jr(0);
        $ang = mt_rand(-70, 70) / 10 + $ja;
        $R = ig_rotate($T, $ang, $clear, $frame !== null);
        $oy = mt_rand(-6, 6);
        $jx = $frame === null ? 0 : (int) round(3 * $jit * $jr(1)); $jy = $frame === null ? 0 : (int) round(3 * $jit * $jr(2));
        imagecopy($im, $R, (int) $cx + $jx, (int) ($y - $H + $oy) + $jy, 0, 0, imagesx($R), imagesy($R));
        $cx += $W + mt_rand(-1, 4);
    }
    return $cx;
}

// Blood running down from the edge of the masthead bar ($y0 is the bar's
// bottom): a ragged pooled edge hanging off it, and runs of every width and
// length that flare out of the pool, narrow to a neck, lean a little and end in
// a small bulb — thick and thin, some long, a few with a drop that has let go.
// A darker edge down one side and a glossy streak down the other give them
// volume. Runs in the chip's column and the headline's stay short so they do
// not run over either. Returns the runs ([x, length, lean] each) so
// ig_zine_blood_drops() can let the beads go.
// $wind (0..1) leans the runs to the right. $phase (0..1 round a cycle, or null for the still): each run's bulb swells
// over the first part of its own turn of the cycle (they are staggered by the
// golden ratio so they do not all go at once), stretching the run a few pixels,
// then pinches off and snaps back small to start again.
function ig_zine_blood($im, $x1, $x2, $y0, $seed, $clearUpTo = 0, $maxNearChip = 0, $maxNearText = 80, $phase = null, $wind = 0.0) {
    mt_srand($seed);
    $red  = imagecolorallocate($im, 0xB0, 0x12, 0x1A);
    $dark = imagecolorallocate($im, 0x78, 0x0A, 0x10);
    $gloss = imagecolorallocatealpha($im, 0xFF, 0x9A, 0x9A, 78);
    // the pool
    $pool = [[$x1 - 5, $y0 - 3]];
    for ($x = $x1 - 5; $x <= $x2 + 5; $x += 5) $pool[] = [$x, $y0 + 7 + 5 * sin($x / 17) + 3 * sin($x / 6.5 + 1) + mt_rand(0, 3)];
    $pool[] = [$x2 + 5, $y0 - 3];
    $flat = []; foreach ($pool as [$px, $py]) { $flat[] = (int) round($px); $flat[] = (int) round($py); }
    imagefilledpolygon($im, $flat, $red);
    // the runs
    $x = $x1 + mt_rand(14, 40);
    $runs = [];
    while ($x < $x2 - 12) {
        $w = mt_rand(5, 12);
        $long = mt_rand(0, 3) === 0;
        $len = $long ? mt_rand(85, 140) : mt_rand(22, 64);
        if ($x < 330) $len = min($len, $maxNearChip);                    // the chip's column
        elseif ($x < $clearUpTo) $len = min($len, $maxNearText);         // the headline's column
        $lean = mt_rand(-4, 4);
        $runs[] = [$x, $len, $lean];
        $lean += (int) round(8 * $wind);                                   // the gust leans every run to the right
        $phi = $phase === null ? null : fmod($phase + (count($runs) - 1) * 0.381966, 1.0);
        $bs = 1.0;                                                         // the bulb's size, as a fraction of full
        if ($phi !== null) {
            if ($phi < 0.6) { $e = ig_ease($phi / 0.6); $bs = 0.55 + 0.45 * $e; $len += 6 * $e; }
            else            { $bs = max(0.55, 1 - ($phi - 0.6) / 0.06 * 0.45); }
        }
        $at = fn($t) => $x + $lean * $t;                                   // the run's centre line at fraction $t down it
        $pts = [];
        for ($k = 0; $k <= 6; $k++) { $t = $k / 6; $pts[] = [$at(0) - $w / 2 - 1.3 * $w * (1 - $t) ** 2, $y0 + 4 + $t * 18]; }   // left side, flaring out of the pool
        $pts[] = [$at(1) - $w / 2, $y0 + $len - $w * 0.4];
        for ($a = 180; $a >= 0; $a -= 30) $pts[] = [$at(1) + ($w / 2 + 2) * $bs * cos(deg2rad($a)), $y0 + $len + ($w / 2 + 3) * $bs * sin(deg2rad($a))];   // the bulb, left to right
        $pts[] = [$at(1) + $w / 2, $y0 + $len - $w * 0.4];
        for ($k = 6; $k >= 0; $k--) { $t = $k / 6; $pts[] = [$at(0) + $w / 2 + 1.3 * $w * (1 - $t) ** 2, $y0 + 4 + $t * 18]; }   // right side
        $flat = []; foreach ($pts as [$px, $py]) { $flat[] = (int) round($px); $flat[] = (int) round($py); }
        imagefilledpolygon($im, $flat, $red);
        // shading: dark down the right edge, a glossy streak down the left, a shine on the bulb
        imagesetthickness($im, 2); imageline($im, (int) round($at(0.18) + $w / 2 - 1), (int) round($y0 + 0.18 * $len), (int) round($at(1) + $w / 2 - 1), (int) round($y0 + $len - 2), $dark); imagesetthickness($im, 1);
        imageline($im, (int) round($at(0.2) - $w / 2 + 2), (int) round($y0 + 0.2 * $len), (int) round($at(0.9) - $w / 2 + 2), (int) round($y0 + 0.9 * $len), $gloss);
        imagefilledellipse($im, (int) round($at(1) - 2), (int) round($y0 + $len + 1), 3, 4, imagecolorallocatealpha($im, 0xFF, 0xC0, 0xC0, 40));
        $dropAt = mt_rand(16, 28);
        if (mt_rand(0, 2) === 0 && $x >= $clearUpTo && $phase === null) imagefilledellipse($im, (int) round($at(1)), (int) round($y0 + $len + $dropAt), 5, 8, $red);   // a drop that has let go (only where it has clear space; animated, the beads really fall)
        $x += mt_rand(30, 96);
    }
    return $runs;
}

// The beads that pinch off the blood's runs and fall: for each run at or beyond
// $dropFrom (clear of the date and the headline), the bead leaves the bulb at
// 60% of that run's turn of the cycle and falls with gravity for a stretch of
// the page, over the listings, fading out before it stops. Drawn last, so it
// passes over everything. Same staggering as ig_zine_blood().
function ig_zine_blood_drops($im, array $runs, $y0, $phase, $dropFrom, $wind = 0.0) {
    foreach ($runs as $i => [$x, $len, $lean]) {
        if ($x < $dropFrom) continue;
        $phi = fmod($phase + $i * 0.381966, 1.0);
        if ($phi < 0.6 || $phi >= 0.97) continue;
        $u = ($phi - 0.6) / 0.37;
        $bx = $x + $lean + 1.5 * sin($u * 9 + $i) + 70 * $wind * $u;
        $by = $y0 + $len + 8 + 400 * $u * $u;
        $a = $u < 0.8 ? 0 : (int) round(127 * ($u - 0.8) / 0.2);
        $red = imagecolorallocatealpha($im, 0xB0, 0x12, 0x1A, $a);
        imagefilledellipse($im, (int) round($bx), (int) round($by), 7, 10, $red);
        imagefilledpolygon($im, [(int) round($bx) - 3, (int) round($by) - 2, (int) round($bx) + 3, (int) round($by) - 2, (int) round($bx), (int) round($by) - 10], $red);
        imagefilledellipse($im, (int) round($bx) - 1, (int) round($by) + 1, 2, 3, imagecolorallocatealpha($im, 0xFF, 0xC0, 0xC0, min(127, 40 + $a)));
    }
}

// A strip of translucent tape at ($cx, $cy), $w x $h, turned $ang degrees.
function ig_zine_tape($im, $cx, $cy, $w, $h, $ang) {
    $w = (int) round($w); $h = (int) round($h);
    $ang = fmod($ang, 360.0);
    if (abs(abs($ang) - 360) < 0.5) $ang = 0.0;
    $T = imagecreatetruecolor($w + 4, $h + 4); imagesavealpha($T, true); imagealphablending($T, false);
    $clear = imagecolorallocatealpha($T, 0, 0, 0, 127); imagefill($T, 0, 0, $clear); imagealphablending($T, true);
    imagefilledrectangle($T, 2, 2, $w + 1, $h + 1, imagecolorallocatealpha($T, 0xE8, 0xDC, 0xA8, 40));
    $R = ig_rotate($T, $ang, $clear, true);
    imagecopy($im, $R, (int) round($cx - imagesx($R) / 2), (int) round($cy - imagesy($R) / 2), 0, 0, imagesx($R), imagesy($R));
}

// The photocopied skull's card as an image (before it is turned or scaled): a
// roughly cut black card with a white skull and the photocopier's grain.
function ig_zine_skull_front() {
    $W = 170; $H = 200;
    $T = imagecreatetruecolor($W + 40, $H + 40); imagesavealpha($T, true); imagealphablending($T, false);
    $clear = imagecolorallocatealpha($T, 0, 0, 0, 127); imagefill($T, 0, 0, $clear); imagealphablending($T, true);
    $blk = imagecolorallocate($T, 0x16, 0x15, 0x13); $wh = imagecolorallocate($T, 0xF4, 0xF1, 0xE6);
    mt_srand(9);
    $pts = []; foreach ([[20, 20], [20 + $W, 22], [18 + $W, 20 + $H], [22, 18 + $H]] as [$px, $py]) { $pts[] = $px + mt_rand(-3, 3); $pts[] = $py + mt_rand(-3, 3); }
    imagefilledpolygon($T, $pts, $blk);
    $x0 = 20 + $W / 2; $y0 = 20 + $H / 2 - 8;
    imagefilledellipse($T, (int) $x0, (int) ($y0 - 14), 112, 104, $wh);
    imagefilledrectangle($T, (int) ($x0 - 34), (int) ($y0 + 20), (int) ($x0 + 34), (int) ($y0 + 62), $wh);
    imagefilledellipse($T, (int) ($x0 - 24), (int) ($y0 - 6), 34, 38, $blk); imagefilledellipse($T, (int) ($x0 + 24), (int) ($y0 - 6), 34, 38, $blk);
    imagefilledpolygon($T, [(int) $x0, (int) ($y0 + 8), (int) $x0 - 9, (int) ($y0 + 28), (int) $x0 + 9, (int) ($y0 + 28)], $blk);
    foreach ([-24, -12, 0, 12, 24] as $dx) imageline($T, (int) ($x0 + $dx), (int) ($y0 + 42), (int) ($x0 + $dx), (int) ($y0 + 62), $blk);
    imageline($T, (int) ($x0 - 34), (int) ($y0 + 42), (int) ($x0 + 34), (int) ($y0 + 42), $blk);
    for ($i = 0; $i < 1100; $i++) imagesetpixel($T, mt_rand(22, $W + 16), mt_rand(22, $H + 14), mt_rand(0, 1) ? $wh : $blk);
    return $T;
}

// A card's blank canvas (transparent, 210 x 240) and its roughly cut outline
// (170 x 200 with a little wobble at each corner, from the seed).
function ig_zine_card_blank($seed) {
    $T = imagecreatetruecolor(210, 240); imagesavealpha($T, true); imagealphablending($T, false);
    imagefill($T, 0, 0, imagecolorallocatealpha($T, 0, 0, 0, 127)); imagealphablending($T, true);
    mt_srand($seed);
    $pts = []; foreach ([[20, 20], [190, 22], [188, 220], [22, 218]] as [$px, $py]) { $pts[] = $px + mt_rand(-3, 3); $pts[] = $py + mt_rand(-3, 3); }
    return [$T, $pts];
}

// Poster two: a photocopied eye. Cream paper, a heavy black outline, a pink
// iris with the lines of an ink drawing, bloodshot veins creeping in from the
// corners, lashes — and the photocopier's grain.
function ig_zine_eye_front() {
    [$T, $pts] = ig_zine_card_blank(13);
    $paper = imagecolorallocate($T, 0xF4, 0xF1, 0xE6); $blk = imagecolorallocate($T, 0x16, 0x15, 0x13);
    $pink = imagecolorallocate($T, 0xFF, 0x3D, 0x8A); $red = imagecolorallocate($T, 0xB0, 0x12, 0x1A); $white = imagecolorallocate($T, 0xFB, 0xF9, 0xF2);
    imagefilledpolygon($T, $pts, $paper);
    $cx = 105; $cy = 120;
    $eye = [];
    for ($i = 0; $i <= 20; $i++) { $u = $i / 20; $eye[] = 34 + $u * 142; $eye[] = $cy - 52 * pow(sin(M_PI * $u), 0.85); }
    for ($i = 20; $i >= 0; $i--) { $u = $i / 20; $eye[] = 34 + $u * 142; $eye[] = $cy + 42 * pow(sin(M_PI * $u), 0.85); }
    imagefilledpolygon($T, $eye, $white);
    // veins, drawn before the iris so it sits over their ends
    imagesetthickness($T, 2);
    foreach ([[40, 119, 78, 112], [44, 123, 76, 130], [60, 108, 82, 118], [170, 119, 132, 113], [166, 124, 134, 130], [150, 106, 128, 117]] as [$x1, $y1, $x2, $y2]) imageline($T, $x1, $y1, $x2, $y2, $red);
    imagesetthickness($T, 1);
    imagefilledellipse($T, $cx, $cy, 78, 78, $pink);
    imagesetthickness($T, 5); imageellipse($T, $cx, $cy, 78, 78, $blk); imagesetthickness($T, 1);
    for ($a = 0; $a < 360; $a += 18) imageline($T, (int) round($cx + 16 * cos(deg2rad($a))), (int) round($cy + 16 * sin(deg2rad($a))), (int) round($cx + 36 * cos(deg2rad($a))), (int) round($cy + 36 * sin(deg2rad($a))), $blk);
    imagefilledellipse($T, $cx, $cy, 32, 32, $blk);
    imagefilledellipse($T, $cx - 11, $cy - 13, 13, 15, $white);
    imagesetthickness($T, 6); imagepolygon($T, $eye, count($eye) / 2, $blk);
    imagesetthickness($T, 4);
    for ($i = 1; $i <= 9; $i++) { $u = $i / 10; $x = 34 + $u * 142; $y = $cy - 52 * pow(sin(M_PI * $u), 0.85); $a = deg2rad(-90 + ($u - 0.5) * 100); imageline($T, (int) $x, (int) $y, (int) round($x + 22 * cos($a)), (int) round($y + 22 * sin($a)), $blk); }
    imagesetthickness($T, 1);
    mt_srand(31);
    for ($i = 0; $i < 700; $i++) imagesetpixel($T, mt_rand(24, 184), mt_rand(24, 216), mt_rand(0, 3) ? imagecolorallocate($T, 0xD6, 0xD0, 0xBE) : $blk);
    return $T;
}

// Poster three: a hot-pink flier, the page's one spot colour, with a heavy
// black frame and the cinema's etiquette in Anton — the last line reversed out
// of a black bar.
function ig_zine_rules_front() {
    [$T, $pts] = ig_zine_card_blank(17);
    $pink = imagecolorallocate($T, 0xFF, 0x3D, 0x8A); $blk = imagecolorallocate($T, 0x16, 0x15, 0x13);
    imagefilledpolygon($T, $pts, $pink);
    imagesetthickness($T, 5); imagerectangle($T, 32, 32, 178, 208, $blk); imagesetthickness($T, 1);
    $font = dirname(__DIR__) . '/assets/fonts/Anton-Regular.ttf';
    $fit = function ($text, $maxW) use ($font) { for ($sz = 44; $sz > 8; $sz--) { $b = imagettfbbox($sz, 0, $font, $text); if ($b[2] - $b[0] <= $maxW) return [$sz, $b]; } return [8, imagettfbbox(8, 0, $font, $text)]; };
    $lines = [['NO PHONES.', 78, false], ['NO TALKING.', 128, false], ['NO SURVIVORS.', 182, true]];
    foreach ($lines as [$text, $base, $inv]) {
        [$sz, $b] = $fit($text, 126);
        $tw = $b[2] - $b[0]; $x = 105 - $tw / 2 - $b[0];
        if ($inv) { imagefilledrectangle($T, 38, $base - $sz - 8, 172, $base + 10, $blk); imagettftext($T, $sz, 0, (int) round($x), $base, $pink, $font, $text); }
        else imagettftext($T, $sz, 0, (int) round($x), $base, $blk, $font, $text);
    }
    mt_srand(41);
    for ($i = 0; $i < 600; $i++) imagesetpixel($T, mt_rand(24, 184), mt_rand(24, 216), mt_rand(0, 2) ? imagecolorallocate($T, 0xE8, 0x2E, 0x7A) : $blk);
    return $T;
}

// Poster $i of the three the flier is replaced with.
function ig_zine_poster_front($i) {
    return [ig_zine_skull_front(), ig_zine_eye_front(), ig_zine_rules_front()][$i % 3];
}

// The back of that card: plain paper, same cut.
function ig_zine_skull_back() {
    $W = 170; $H = 200;
    $T = imagecreatetruecolor($W + 40, $H + 40); imagesavealpha($T, true); imagealphablending($T, false);
    $clear = imagecolorallocatealpha($T, 0, 0, 0, 127); imagefill($T, 0, 0, $clear); imagealphablending($T, true);
    mt_srand(9);
    $pts = []; foreach ([[20, 20], [20 + $W, 22], [18 + $W, 20 + $H], [22, 18 + $H]] as [$px, $py]) { $pts[] = $px + mt_rand(-3, 3); $pts[] = $py + mt_rand(-3, 3); }
    imagefilledpolygon($T, $pts, imagecolorallocate($T, 0xD9, 0xD2, 0xBA));
    imagesetthickness($T, 3); imagepolygon($T, $pts, imagecolorallocate($T, 0x9C, 0x95, 0x80)); imagesetthickness($T, 1);
    mt_srand(11);
    for ($i = 0; $i < 500; $i++) imagesetpixel($T, mt_rand(26, $W + 12), mt_rand(26, $H + 10), imagecolorallocate($T, 0xC4, 0xBD, 0xA4));
    return $T;
}

// A photocopied skull: the card turned $ang degrees and scaled by $k (1 is
// 170 x 200), taped down at two corners.
function ig_zine_skull_card($im, $cx, $cy, $k, $ang) {
    $T = ig_zine_skull_front();
    $clear = imagecolorallocatealpha($T, 0, 0, 0, 127);
    $R = imagerotate($T, $ang, $clear); imagesavealpha($R, true);
    $sw = (int) round(imagesx($R) * $k); $sh = (int) round(imagesy($R) * $k);
    imagecopyresampled($im, $R, (int) round($cx - $sw / 2), (int) round($cy - $sh / 2), 0, 0, $sw, $sh, imagesx($R), imagesy($R));
    ig_zine_tape($im, $cx - 68 * $k, $cy - 100 * $k, 64 * $k, 24 * $k, -32);
    ig_zine_tape($im, $cx + 68 * $k, $cy + 102 * $k, 64 * $k, 24 * $k, -28);
}

// The card in flight: $sx (0..1) squashes it sideways (a turn about its
// vertical axis), $back shows the plain reverse, $ang turns it in the plane.
function ig_zine_card_blit($im, $front, $back, $cx, $cy, $k, $ang, $sx, $showBack) {
    $src = $showBack ? $back : $front;
    $ang = fmod($ang, 360.0);
    if (abs(abs($ang) - 360) < 0.5) $ang = 0.0;
    $w = max(2, (int) round(imagesx($src) * $sx));
    $tmp = imagecreatetruecolor($w, imagesy($src)); imagesavealpha($tmp, true); imagealphablending($tmp, false);
    $clear = imagecolorallocatealpha($tmp, 0, 0, 0, 127); imagefill($tmp, 0, 0, $clear);
    imagecopyresampled($tmp, $src, 0, 0, 0, 0, $w, imagesy($src), imagesx($src), imagesy($src));
    $R = ig_rotate($tmp, $ang, $clear, true);
    $sw = (int) round(imagesx($R) * $k); $sh = (int) round(imagesy($R) * $k);
    if ($sw < 1 || $sh < 1) return;
    imagealphablending($im, true);
    imagecopyresampled($im, $R, (int) round($cx - $sw / 2), (int) round($cy - $sh / 2), 0, 0, $sw, $sh, imagesx($R), imagesy($R));
}

// The wind (0..1) at video frame $f of the 96-frame loop: it rises over frames
// 52-60, blows through 66, and dies away by 78.
function ig_zine_wind($f) {
    if ($f < 52 || $f >= 78) return 0.0;
    if ($f < 60) return ig_ease(($f - 52) / 8);
    if ($f <= 66) return 1.0;
    return 1 - ig_ease(($f - 66) / 12);
}

// The flier on its tape at ($cx, $cy), scale $k, at frame $f of the 96-frame
// cycle $cyc: a poster sits and wobbles (on threes), the flutter builds and the
// top-left tape peels (50-57), then the card tears free and sails off to the
// right, spinning and flipping to show its blank back, and both strips of tape
// go with it (58-70) — and at frame 74 the next poster is slapped on with fresh
// tape, a little oversized and settling in four frames, and wobbles on.
// Poster number $cyc is the one on the wall at the start of cycle $cyc, so the
// last cycle of the video hands the first poster back and the loop closes.
function ig_zine_flier_anim($im, $cx, $cy, $k, $f, $cyc) {
    $nowIdx = $f >= 74 ? ($cyc + 1) % 3 : $cyc;
    $front = ig_zine_poster_front($nowIdx); $back = ig_zine_skull_back();
    $poses = [[0, 0, 5.0], [1, -1, 6.4], [-1, 1, 3.8]];
    $tlx = $cx - 68 * $k; $tly = $cy - 100 * $k; $brx = $cx + 68 * $k; $bry = $cy + 102 * $k;
    if ($f < 58 || $f >= 74) {
        $p = $poses[intdiv($f, 3) % 3]; $m = 1.0; $peel = 0.0; $sc = 1.0; $ang0 = 0.0;
        if ($f >= 50 && $f < 58) { $q = ($f - 50) / 7; $m = 1 + 2.5 * $q; $peel = ig_ease($q); }
        if ($f >= 74 && $f < 77) { $sc = [1.15, 1.07, 1.0][$f - 74]; $ang0 = [4.0, 2.0, 0.0][$f - 74]; }
        $ang = 5.0 + ($p[2] - 5.0) * $m + 5 * $peel + $ang0;
        ig_zine_card_blit($im, $front, $back, $cx + $p[0] * $m, $cy + $p[1] * $m, $k * $sc, $ang, 1.0, false);
        ig_zine_tape($im, $brx, $bry, 64 * $k, 24 * $k, -28);
        ig_zine_tape($im, $tlx - 6 * $peel, $tly - 10 * $peel, 64 * $k * (1 - 0.25 * $peel), 24 * $k, -32 + 65 * $peel);
        return;
    }
    if ($f < 71) {
        $t = ($f - 58) / 12;
        $x = $cx + 400 * pow($t, 1.8); $y = $cy - 140 * $t + 35 * sin(2 * M_PI * 1.3 * $t);
        $ang = 5.0 + 560 * $t; $flip = 2 * M_PI * 1.4 * $t; $c = cos($flip);
        if ($x < 1080 + 160) ig_zine_card_blit($im, $front, $back, $x, $y, $k * (1 - 0.1 * $t), $ang, max(0.12, abs($c)), $c < 0);
        // the two strips of tape go too, each on its own path and spin: the
        // top-left one carries on from where it had peeled to
        $tx1 = $tlx - 6 + 430 * pow($t, 1.7); $ty1 = $tly - 10 - 170 * $t + 40 * sin(2 * M_PI * 1.7 * $t);
        if ($tx1 < 1080 + 60) ig_zine_tape($im, $tx1, $ty1, 48 * $k, 24 * $k, 33 + 700 * $t);
        $tx2 = $brx + 390 * pow($t, 1.9); $ty2 = $bry - 90 * $t + 50 * sin(2 * M_PI * 1.2 * $t + 1);
        if ($tx2 < 1080 + 60) ig_zine_tape($im, $tx2, $ty2, 64 * $k, 24 * $k, -28 + 520 * $t);
    }
    // frames 71-73: bare wall, nothing left to see, for a beat
}

// Marker whoosh lines trailing to the right of where the card was, sweeping
// off to the right between frames 56 and 70.
function ig_zine_whoosh($im, $f) {
    if ($f < 56 || $f > 70) return;
    $ink = imagecolorallocatealpha($im, 0x16, 0x15, 0x13, (int) round(127 - 105 * ig_zine_wind($f)));
    imagesetthickness($im, 3);
    foreach ([[690, 1188, 150], [740, 1232, 180], [700, 1276, 140], [790, 1160, 120]] as $i => [$x0, $y0, $len]) {
        $ox = 14 * ($f - 56) + $i * 6;
        $px = null;
        for ($u = 0; $u <= 1.001; $u += 0.1) {
            $x = $x0 + $ox + $u * $len; $y = $y0 + 5 * sin($u * 6 + $i * 1.5);
            if ($px !== null) imageline($im, (int) round($px[0]), (int) round($px[1]), (int) round($x), (int) round($y), $ink);
            $px = [$x, $y];
        }
    }
    imagesetthickness($im, 1);
}

// A final overlay pass — faint horizontal lines the full width of the
// card — the one texture that has to be drawn last, over everything else,
// since a real CRT's scanlines sit in front of the whole picture.
function ig_scanlines($im, $w, $h, $color, $spacing = 4) {
    for ($y = 0; $y < $h; $y += $spacing) {
        imageline($im, 0, $y, $w, $y, $color);
    }
}

// A row-by-row interpolated vertical gradient — GD has no linear-gradient
// primitive, so every theme so far has hand-rolled a single-purpose alpha
// fade for one hardcoded pair of colors (the hero-to-background fades,
// ig_neon_text()'s glow). This is the general form: any two RGB colors,
// top to bottom, for anything that needs an actual gradient rather than a
// fade-to-one-color.
function ig_gradient_fill($im, $x1, $y1, $x2, $y2, $topColor, $bottomColor) {
    $h = max(1, $y2 - $y1);
    $top = imagecolorsforindex($im, $topColor);
    $bot = imagecolorsforindex($im, $bottomColor);
    for ($i = 0; $i <= $h; $i++) {
        $t = $i / $h;
        $r = (int) round($top['red']   + ($bot['red']   - $top['red'])   * $t);
        $g = (int) round($top['green'] + ($bot['green'] - $top['green']) * $t);
        $b = (int) round($top['blue']  + ($bot['blue']  - $top['blue'])  * $t);
        $row = imagecolorallocate($im, $r, $g, $b);
        imagefilledrectangle($im, $x1, $y1 + $i, $x2, $y1 + $i, $row);
    }
}

// A soft radial glow — concentric circles fading in alpha from center to
// edge, the same halo trick ig_neon_text() uses for type, applied to a
// point instead: a sun, a moon, a firefly, anything that should read as a
// light source rather than a flat drawn shape.
function ig_glow_circle($im, $cx, $cy, $r, $color, $glowColor) {
    $rgb = imagecolorsforindex($im, $glowColor);
    $steps = 5;
    for ($i = $steps; $i >= 1; $i--) {
        $rr = (int) round($r * (1 + $i * 0.35));
        $alpha = (int) round(115 - ($steps - $i) * 6);
        $c = imagecolorallocatealpha($im, $rgb['red'], $rgb['green'], $rgb['blue'], max(70, $alpha));
        imagefilledellipse($im, $cx, $cy, $rr * 2, $rr * 2, $c);
    }
    imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $color);
}

// A softer, more natural glow than ig_glow_circle()'s flat-color rings —
// finer-grained falloff (16 steps, not 5, so the rings blend rather than
// band) and a color shift from a bright near-white core out to a warm edge
// tone, the way a real setting sun actually reads hot at the center rather
// than one flat color throughout.
function ig_glow_sun($im, $cx, $cy, $r, $coreColor, $edgeColor) {
    $core = imagecolorsforindex($im, $coreColor);
    $edge = imagecolorsforindex($im, $edgeColor);
    $steps = 16;
    for ($i = $steps; $i >= 1; $i--) {
        $t  = $i / $steps;
        $rr = (int) round($r * (1 + $t * 2.4));
        $alpha = (int) round(118 * $t);
        $rC = (int) round($edge['red']   + ($core['red']   - $edge['red'])   * (1 - $t));
        $gC = (int) round($edge['green'] + ($core['green'] - $edge['green']) * (1 - $t));
        $bC = (int) round($edge['blue']  + ($core['blue']  - $edge['blue'])  * (1 - $t));
        $c  = imagecolorallocatealpha($im, $rC, $gC, $bC, $alpha);
        imagefilledellipse($im, $cx, $cy, $rr * 2, $rr * 2, $c);
    }
    imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $coreColor);
}

// Text with manual letter-spacing — GD's imagettftext has no tracking
// parameter, so wide-tracked labels (the one unmistakable "airport board"
// typographic tell) are drawn one character at a time, each advanced by its
// own width plus $tracking.
function ig_tracked_text($im, $size, $x, $y, $font, $text, $color, $tracking = 6) {
    $cx = $x;
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        imagettftext($im, $size, 0, (int) $cx, $y, $color, $font, $ch);
        $box = imagettfbbox($size, 0, $font, $ch);
        $cx += ($box[2] - $box[0]) + $tracking;
    }
    return $cx - $tracking;
}

// Small square indicator lights evenly spaced around a frame — the Terminal
// theme's border, playing the same role Marquee's round glowing bulbs do,
// but square and dim/monochrome: an information-display bezel rather than a
// theatre marquee.
function ig_led_border($im, $x1, $y1, $x2, $y2, $spacing, $color) {
    $top   = $x2 - $x1;
    $side  = $y2 - $y1;
    $perim = 2 * $top + 2 * $side;
    $n     = max(4, (int) round($perim / $spacing));

    for ($i = 0; $i < $n; $i++) {
        $d = ($i / $n) * $perim;
        if ($d < $top) {
            $x = $x1 + $d; $y = $y1;
        } elseif ($d < $top + $side) {
            $x = $x2; $y = $y1 + ($d - $top);
        } elseif ($d < 2 * $top + $side) {
            $x = $x2 - ($d - $top - $side); $y = $y2;
        } else {
            $x = $x1; $y = $y2 - ($d - 2 * $top - $side);
        }
        imagefilledrectangle($im, (int) $x - 2, (int) $y - 2, (int) $x + 2, (int) $y + 2, $color);
    }
}

// A torn-paper edge instead of a ruled line — short segments alternating up
// and down rather than one straight stroke.
function ig_torn_line($im, $x1, $x2, $y, $color, $amplitude = 4, $segment = 14) {
    $x = $x1;
    $py = $y;
    $up = true;
    while ($x < $x2) {
        $nx = min($x2, $x + $segment);
        $ny = $y + ($up ? -$amplitude : $amplitude);
        imageline($im, (int) $x, (int) $py, (int) $nx, (int) $ny, $color);
        $x  = $nx;
        $py = $ny;
        $up = !$up;
    }
}

// One bat in flight — two shallow arcs meeting at a center point, the same
// minimalist shorthand real bat-silhouette icons use. Dusk is when they
// actually fly, so this is only ever called on the after-dark tone.
function ig_bat_mark($im, $x, $y, $size, $color) {
    imagesetthickness($im, 2);
    imagearc($im, (int) ($x - $size * 0.4), (int) $y, $size, $size, 200, 340, $color);
    imagearc($im, (int) ($x + $size * 0.4), (int) $y, $size, $size, 200, 340, $color);
    imagesetthickness($im, 1);
}

// A 35mm filmstrip's sprocket-hole edge — a side margin the full height of
// the card, punched with evenly spaced perforations, the same rect+circle-
// ends construction ig_pill() uses for a rounded shape, just oriented as a
// short vertical stadium instead of a wide horizontal one.
function ig_sprocket_edge($im, $x, $h, $stripW, $stripColor, $holeColor, $spacing = 64) {
    imagefilledrectangle($im, $x, 0, $x + $stripW, $h, $stripColor);

    $holeW = (int) round($stripW * 0.5);
    $holeH = (int) round($holeW * 1.5);
    $cx    = $x + (int) round($stripW / 2);
    $r     = (int) round($holeW / 2);

    for ($cy = (int) round($spacing / 2); $cy < $h; $cy += $spacing) {
        $top = $cy - (int) round($holeH / 2) + $r;
        $bot = $cy + (int) round($holeH / 2) - $r;
        imagefilledrectangle($im, $cx - $r, $top, $cx + $r, $bot, $holeColor);
        imagefilledellipse($im, $cx, $top, $holeW, $holeW, $holeColor);
        imagefilledellipse($im, $cx, $bot, $holeW, $holeW, $holeColor);
    }
}

// The circle-and-tick countdown leader a 35mm print used to open with — a
// small decorative corner mark, the same role Neon's REC dot or Marquee's
// bulb corner plays: one unmistakable "this is film" flourish. Four ticks
// read as a dial without trying to be a literal stopwatch face.
function ig_leader_mark($im, $cx, $cy, $r, $color) {
    imagesetthickness($im, 2);
    imageellipse($im, $cx, $cy, $r * 2, $r * 2, $color);
    imageellipse($im, $cx, $cy, (int) ($r * 1.15), (int) ($r * 1.15), $color);
    for ($i = 0; $i < 4; $i++) {
        $angle = $i * (M_PI / 2);
        $x1 = $cx + ($r * 0.55) * cos($angle);
        $y1 = $cy + ($r * 0.55) * sin($angle);
        $x2 = $cx + $r * cos($angle);
        $y2 = $cy + $r * sin($angle);
        imageline($im, (int) $x1, (int) $y1, (int) $x2, (int) $y2, $color);
    }
    imagesetthickness($im, 1);
}

// A splice mark instead of a ruled line — the dashed cut-guide a film
// editor's tape splicer prints across the frame line, not a printer's rule.
function ig_dashed_line($im, $x1, $x2, $y, $color, $dash = 10, $gap = 8) {
    for ($x = $x1; $x < $x2; $x += $dash + $gap) {
        imageline($im, (int) $x, (int) $y, (int) min($x2, $x + $dash), (int) $y, $color);
    }
}

// Downloads a poster (TMDB, w300) once and caches it — the same handful of
// repertory titles recur night after night, no reason to refetch. Returns a
// cropped-to-cover GD image sized exactly $w x $h, or null on any miss/failure
// so the caller can fall back to a placeholder rather than breaking the row.
//
// $vBias controls where the vertical crop window sits when the source is
// taller than the target (the common case: a portrait poster against a
// landscape spotlight hero) — 0.0 is the top of the poster, 1.0 the bottom,
// 0.5 (the default) is dead center, i.e. today's exact prior behavior for
// every caller that doesn't pass one. Horizontal cropping always stays
// centered; posters are essentially always portrait, so that axis was never
// the problem ig_poster_crop_bias() exists to fix.
function ig_fetch_thumb($url, $w, $h, $vBias = 0.5) {
    if (!$url) return null;

    $cacheDir = dirname(__DIR__) . '/list/poster_cache';
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0775, true);
    $cacheFile = $cacheDir . '/' . md5($url) . '.jpg';

    if (!file_exists($cacheFile)) {
        // A site-root-relative path — an admin-uploaded event poster (see
        // _admin/events.php, list/fetch_screenings.php's events query) —
        // is already a file on this box. Reading it directly is simpler
        // and more reliable than a self-referential HTTP fetch, and
        // doesn't depend on the box being able to reach its own public
        // URL. Every scraped/TMDB poster is a genuine absolute URL and
        // still goes through curl exactly as before.
        if ($url[0] === '/' && ($url[1] ?? '') !== '/') {
            $data = @file_get_contents(dirname(__DIR__) . $url);
        } else {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_USERAGENT      => 'CinemaTX/1.0 (+https://cinematx.net)',
                // TMDB's own poster URLs never redirect, so this never
                // mattered until HYPERREAL_LOGO — Squarespace's static asset
                // host 301s to its actual CDN URL, and without this the
                // fetch silently returns an empty body and every Hyperreal
                // fallback card fell through to the plain initial-letter
                // placeholder.
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $data = curl_exec($ch);
            curl_close($ch);
        }
        if (!$data) return null;
        file_put_contents($cacheFile, $data);
    }

    $src = @imagecreatefromstring(file_get_contents($cacheFile));
    if (!$src) { @unlink($cacheFile); return null; }

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    $thumb = imagecreatetruecolor($w, $h);

    // Every scraped/TMDB poster is an opaque JPEG, so this never mattered
    // before — but a transparent-background PNG fallback (HYPERREAL_LOGO in
    // scraper_hyperreal.php) needs its alpha preserved through the resize
    // rather than pre-blended onto imagecreatetruecolor()'s default opaque
    // black canvas. Every caller already paints $im with its own card
    // background before pasting a thumb into it, and a truecolor image's
    // alpha blending defaults to on — so a $thumb with real per-pixel alpha
    // composites correctly wherever it lands with no change at any call site.
    imagealphablending($thumb, false);
    imagesavealpha($thumb, true);

    // Cover-crop to the target ratio rather than squashing the poster.
    if ($srcW / $srcH > $w / $h) {
        $cropW = (int) round($srcH * $w / $h);
        imagecopyresampled($thumb, $src, 0, 0, (int) (($srcW - $cropW) / 2), 0, $w, $h, $cropW, $srcH);
    } else {
        $cropH = (int) round($srcW * $h / $w);
        $offsetY = (int) (($srcH - $cropH) * $vBias);
        imagecopyresampled($thumb, $src, 0, 0, 0, $offsetY, $w, $h, $srcW, $cropH);
    }
    imagedestroy($src);
    return $thumb;
}

// Where today's admin has manually repositioned a poster's spotlight-hero
// crop — keyed by the hero URL itself (md5, same identity ig_fetch_thumb()'s
// own cache file uses) rather than by date or film, since the preference
// belongs to the poster image and should carry forward every time that
// film's poster is used again, not just for today's screening of it.
// Absent key returns 0.5 (dead center, today's prior behavior) so a poster
// nobody has touched renders pixel-identical to before this existed.
function ig_poster_crop_path() {
    return dirname(__DIR__) . '/uploads/social/poster-crops.json';
}

function ig_poster_crop_read() {
    $file = ig_poster_crop_path();
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function ig_poster_crop_bias($posterUrl) {
    if (!$posterUrl) return 0.5;
    $crops = ig_poster_crop_read();
    $key = md5($posterUrl);
    return isset($crops[$key]) ? max(0, min(1, (float) $crops[$key])) : 0.5;
}

function ig_poster_crop_write($posterUrl, $bias) {
    if (!$posterUrl) return;
    $dir = dirname(__DIR__) . '/uploads/social';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $crops = ig_poster_crop_read();
    $crops[md5($posterUrl)] = round(max(0, min(1, (float) $bias)), 3);
    file_put_contents(ig_poster_crop_path(), json_encode($crops));
}

// ── Pagination ───────────────────────────────────────────────────────────

/**
 * Splits $films into ceil(n / $maxPerPage) pages, sized as evenly as
 * possible (no page differs from another by more than one film). Used by
 * Auto and Manual composition modes only — Default mode never paginates, it
 * keeps today's single-card "+N more" behaviour via ig_build_list_page().
 *
 * Capped at 10 pages, Instagram's carousel limit — not a real-world case for
 * a single day's screenings, but keeps the resulting API call legal.
 */
function ig_paginate(array $films, $maxPerPage) {
    $n = count($films);
    if ($n === 0) return [];

    $pages = min(10, max(1, (int) ceil($n / max(1, $maxPerPage))));
    $base  = intdiv($n, $pages);
    $rem   = $n % $pages;

    $out = [];
    $i = 0;
    for ($p = 0; $p < $pages; $p++) {
        $size = $base + ($p < $rem ? 1 : 0);
        $out[] = array_slice($films, $i, $size);
        $i += $size;
    }
    return $out;
}

// A list panel with 2 screenings on it shouldn't use the same tiny row a
// panel with 10 needs — rows get larger as the list gets shorter and
// smaller as it grows, on 4 steps. Deliberately the one exception to "no
// shared abstraction" below: palette and decoration stay fully independent
// per theme, but duplicating this table by hand in all 8 would be a real
// maintenance hazard for zero benefit, since it's pure size math with
// nothing theme-specific in it.
//
// Row height can't just track the thumbnail — the text block (title +
// venue + time) has a legibility floor that shrinks slower than the
// thumbnail does, so past Tier 2 the text block, not the thumbnail, is
// actually the taller thing in the row (Tier 4's own $timeOffsetY sits
// below its $thumbH on purpose). rowHeight is sized to whichever is
// taller, plus a gap, not to the thumbnail alone.
//
// All four tiers fit within 876px — Terminal's own available height
// before its footer zone, the tightest of the 8 themes — so every other
// theme has a little extra room to spare rather than being the binding
// constraint. First-draft pixel values, tuned against real rendered
// output before this shipped (see the Film Forecast session that added
// this — the same "render, look, adjust" discipline as everywhere else
// in this codebase).
function ig_list_row_geometry($count) {
    static $tiers = [
        // items => [thumbW, thumbH, rowHeight, titleSize, metaSize, titleOffsetY, metaOffsetY, timeOffsetY]
        4  => [113, 170, 215, 36, 24, 41, 77, 111],
        6  => [79,  118, 145, 30, 21, 35, 67, 96],
        8  => [56,  84,  108, 24, 18, 28, 55, 80],
        10 => [41,  62,  85,  20, 16, 23, 47, 69],
    ];
    $count = max(1, (int) $count);
    $tier = 1;
    foreach ($tiers as $maxItems => $t) {
        if ($count <= $maxItems) break;
        $tier++;
    }
    return [
        'tier'         => $tier,
        'thumbW'       => $t[0],
        'thumbH'       => $t[1],
        'rowHeight'    => $t[2],
        'titleSize'    => $t[3],
        'metaSize'     => $t[4],
        'titleOffsetY' => $t[5],
        'metaOffsetY'  => $t[6],
        'timeOffsetY'  => $t[7],
    ];
}

// ── Themes ───────────────────────────────────────────────────────────────
//
// Every theme draws its own complete list page and feature page — no shared
// "layout with a color map" abstraction. The two designs are meant to look
// like different things, not the same skeleton recolored, and a self-
// contained function per theme is easier to read and safer to touch than
// unpicking a color parameter passed through several drawing calls. Row
// geometry no longer stays fixed across themes (see ig_list_row_geometry()
// above) — each theme now computes it from that page's own row count — but
// palette and decoration remain fully independent per theme.
const IG_THEMES = [
    'paper'     => 'Paper',
    'marquee'   => 'Marquee',
    'zine'      => 'Zine',
    'newsprint' => 'Newsprint',
    'neon'      => 'Neon',
    'terminal'  => 'Terminal',
    'darkroom'  => 'Darkroom',
    'austin'    => 'Evening in Austin',
];

// Each day of the week has its own standing theme — chosen editorially, well
// ahead of any particular day's admin session. date('w') is 0 (Sunday)
// through 6 (Saturday), already timezone-correct for whatever midnight
// timestamp a caller passes in (ig_admin_target_date()/strtotime('today')
// are both already resolved in America/Chicago). Terminal isn't part of the
// weekly rotation; it just stays reachable from the theme picker like always.
const IG_THEME_SCHEDULE = [
    0 => 'neon',       // Sunday
    1 => 'newsprint',  // Monday
    2 => 'darkroom',   // Tuesday — "Film Reel"
    3 => 'zine',       // Wednesday
    4 => 'austin',     // Thursday — "Night in Austin"
    5 => 'marquee',    // Friday
    6 => 'paper',      // Saturday
];

function ig_theme_for_date($date) {
    return IG_THEME_SCHEDULE[(int) date('w', $date)] ?? 'paper';
}

// $moreCount is screenings that exist on a *later* list page — not this
// page's own row-capacity overflow, which each renderer still tracks
// internally. Only Auto/Manual multi-page days ever pass a nonzero value;
// it's what turns the quiet "+N more" line into a "keep swiping" one.
function ig_build_list_page(array $films, $date, $theme = 'paper', $moreCount = 0, $anim = null) {
    switch ($theme) {
        // $anim (one frame of a looping version) is only honoured by themes
        // that animate — see ig_theme_animates(); the rest ignore it.
        case 'marquee':   return ig_build_list_page_marquee($films, $date, $moreCount, $anim);
        case 'zine':      return ig_build_list_page_zine($films, $date, $moreCount, $anim);
        case 'newsprint': return ig_build_list_page_newsprint($films, $date, $moreCount, $anim);
        case 'neon':      return ig_build_list_page_neon($films, $date, $moreCount, $anim);
        case 'terminal':  return ig_build_list_page_terminal($films, $date, $moreCount);
        case 'darkroom':  return ig_build_list_page_darkroom($films, $date, $moreCount, $anim);
        case 'austin':    return ig_build_list_page_austin($films, $date, $moreCount);
        default:          return ig_build_list_page_paper($films, $date, $moreCount, $anim);
    }
}

function ig_build_feature_page(array $film, $date, $theme = 'paper') {
    switch ($theme) {
        case 'marquee':   return ig_build_feature_page_marquee($film, $date);
        case 'zine':      return ig_build_feature_page_zine($film, $date);
        case 'newsprint': return ig_build_feature_page_newsprint($film, $date);
        case 'neon':      return ig_build_feature_page_neon($film, $date);
        case 'terminal':  return ig_build_feature_page_terminal($film, $date);
        case 'darkroom':  return ig_build_feature_page_darkroom($film, $date);
        case 'austin':    return ig_build_feature_page_austin($film, $date);
        default:          return ig_build_feature_page_paper($film, $date);
    }
}

// Evenly spaced "bulb" dots walking the perimeter of a rect, each a soft
// glow behind a bright core — GD has no light-bleed primitive, so the glow
// is just a larger, low-alpha circle drawn first. The marquee theme's
// signature: every page gets framed by it, list and feature alike.
function ig_marquee_bulbs($im, $x1, $y1, $x2, $y2, $spacing, $glow, $bulb, $haunted = false, $anim = null) {
    $top   = $x2 - $x1;
    $side  = $y2 - $y1;
    $perim = 2 * $top + 2 * $side;
    $n     = max(4, (int) round($perim / $spacing));
    // Animated: a marquee chase — one bulb in four at full strength,
    // stepping around the frame in distinct beats rather than gliding. The
    // count is rounded to a multiple of four so the pattern joins up at the
    // seam instead of leaving one odd gap where the loop wraps. $anim = a
    // frame spec (see ig_build_list_page_marquee()); a still render passes
    // null and is unaffected.
    if ($anim !== null) {
        $n = 4 * max(2, (int) round($n / 4));
        // One step every six frames (12 fps: two beats a second).
        $step = intdiv($anim['frame'], 6) % 4;
    }

    // Haunted: a fixed, index-keyed handful of bulbs are burnt out and a
    // few more flicker at half strength. Keyed on the bulb's own position
    // rather than rand(), so the same card always renders identically — a
    // regenerated preview must match what posts.
    if ($haunted) {
        $rgb      = imagecolorsforindex($im, $glow);
        $dimGlow  = imagecolorallocatealpha($im, $rgb['red'], $rgb['green'], $rgb['blue'], 122);
        $deadBulb = imagecolorallocate($im, 0x3A, 0x36, 0x2E);
    }

    // The still's pattern is kept as it is (it is what already posts).
    // The animation uses a hash chosen for balance: an arbitrary one
    // clusters the burnt-out bulbs on some phases of the chase (the
    // original crc32('bulb'.$i) % 7 put 14 of 39 on one phase against
    // 5-6 on the others), and the sweep then visibly pulses as it
    // travels. This key was searched for at the 116-bulb count: exactly
    // four burnt-out bulbs on each of the four phases.
    $stateOf = function ($i) use ($haunted, $anim) {
        return $haunted
            ? ($anim !== null ? (crc32('mq32:' . $i) >> 7) % 7 : crc32('bulb' . $i) % 7)
            : 99;
    };

    // Weak bulbs (animation only): a few live bulbs that stay on all the
    // time but dimly, outside the chase, as if on their last legs — and the
    // first one on each phase also gutters. Searched for like the burnt-out
    // key above: eight of them, exactly two per phase and none adjacent, so
    // taking them out of the chase leaves it just as even.
    // $weakFlicker maps bulb index => whether it flickers.
    $weakFlicker = [];
    if ($anim !== null && $haunted) {
        $seenPhase = [];
        for ($i = 0; $i < $n; $i++) {
            if ($stateOf($i) === 0) continue;
            if (((crc32('wk33:' . $i) >> 8) % 14) !== 0) continue;
            $p = $i % 4;
            $weakFlicker[$i] = !isset($seenPhase[$p]);
            $seenPhase[$p] = true;
        }
    }

    for ($i = 0; $i < $n; $i++) {
        $state = $stateOf($i);
        $d = ($i / $n) * $perim;
        if ($d < $top) {
            $x = $x1 + $d; $y = $y1;
        } elseif ($d < $top + $side) {
            $x = $x2; $y = $y1 + ($d - $top);
        } elseif ($d < 2 * $top + $side) {
            $x = $x2 - ($d - $top - $side); $y = $y2;
        } else {
            $x = $x1; $y = $y2 - ($d - 2 * $top - $side);
        }
        if ($anim !== null) {
            if ($state === 0) {
                imagefilledellipse($im, (int) $x, (int) $y, 7, 7, $deadBulb);
                continue;
            }
            if (isset($weakFlicker[$i])) {
                // Steady weak bulbs sit at a low level; the flickering ones
                // wander between nearly out and a little brighter, changing
                // every other frame.
                $level = $weakFlicker[$i]
                    ? [0.10, 0.35, 0.55, 0.30][crc32('wf' . $i . ':' . intdiv($anim['frame'], 2)) % 4]
                    : 0.38;
                $rgbG = imagecolorsforindex($im, $glow);
                $weakGlow = imagecolorallocatealpha($im, $rgbG['red'], $rgbG['green'], $rgbG['blue'], 127 - (int) round(32 * $level));
                $rgbB = imagecolorsforindex($im, $bulb);
                $weakCore = imagecolorallocate(
                    $im,
                    (int) round(0x3A + ($rgbB['red']   - 0x3A) * $level * 1.2),
                    (int) round(0x36 + ($rgbB['green'] - 0x36) * $level * 1.2),
                    (int) round(0x2E + ($rgbB['blue']  - 0x2E) * $level * 1.2)
                );
                imagefilledellipse($im, (int) $x, (int) $y, 15, 15, $weakGlow);
                imagefilledellipse($im, (int) $x, (int) $y, 6, 6, $weakCore);
                continue;
            }
            // Every live bulb follows the chase, so the sweep stays regular
            // with only the burnt-out ones leaving gaps; the flickerers
            // additionally drop out now and then when it reaches them.
            $lit = ((($i - $step) % 4) + 4) % 4 === 0;
            if ($lit && $state === 1 && crc32('f' . $i . ':' . intdiv($anim['frame'], 6)) % 3 === 0) {
                $lit = false;
            }
            if ($lit) {
                imagefilledellipse($im, (int) $x, (int) $y, 20, 20, $glow);
                imagefilledellipse($im, (int) $x, (int) $y, 8, 8, $bulb);
            } else {
                imagefilledellipse($im, (int) $x, (int) $y, 12, 12, $haunted ? $dimGlow : $glow);
                imagefilledellipse($im, (int) $x, (int) $y, 5, 5, $deadBulb ?? $bulb);
            }
            continue;
        }
        if ($state === 0) {
            imagefilledellipse($im, (int) $x, (int) $y, 7, 7, $deadBulb);
        } elseif ($state === 1) {
            imagefilledellipse($im, (int) $x, (int) $y, 16, 16, $dimGlow);
            imagefilledellipse($im, (int) $x, (int) $y, 6, 6, $bulb);
        } else {
            imagefilledellipse($im, (int) $x, (int) $y, 16, 16, $glow);
            imagefilledellipse($im, (int) $x, (int) $y, 7, 7, $bulb);
        }
    }
}

// October's seasonal accents (fall + Halloween as one look, since the
// theatres around town run horror all month). Keyed on the post's own date,
// not today's, so tomorrow's preview and a regenerated card agree with what
// will actually post, and every accent drops out on its own on Nov 1.
function ig_halloween_season($date) {
    return (int) date('n', $date) === 10;
}

// A corner cobweb: spokes fanning from ($cx,$cy) across a quarter turn,
// joined by sagging rings. $sx/$sy (+1/-1) pick which corner it hangs in.
// $wobble (about -0.4..0.4) deepens or relaxes every strand's sag, so
// animating it from frame to frame makes the web shiver as if in a breeze;
// 0, the default, is the still web.
function ig_cobweb($im, $cx, $cy, $len, $color, $sx = 1, $sy = 1, $wobble = 0.0) {
    $angles = [0, 22.5, 45, 67.5, 90];
    $pt = function ($deg, $r) use ($cx, $cy, $sx, $sy) {
        $rad = deg2rad($deg);
        return [$cx + $sx * $r * cos($rad), $cy + $sy * $r * sin($rad)];
    };
    foreach ($angles as $a) {
        [$x, $y] = $pt($a, $len);
        imageline($im, (int) $cx, (int) $cy, (int) $x, (int) $y, $color);
    }
    foreach ([0.26, 0.46, 0.68, 0.92] as $f) {
        for ($k = 0; $k < count($angles) - 1; $k++) {
            [$x1, $y1] = $pt($angles[$k], $len * $f);
            [$x2, $y2] = $pt($angles[$k + 1], $len * $f);
            // Each strand is a short polyline bowed toward the corner — a
            // chord with a sine-shaped dip, so it hangs in a smooth curve
            // instead of kinking at a single pulled-in midpoint.
            $sag = 0.09 * $len * $f * (1 + $wobble);
            $px = $x1; $py = $y1;
            for ($s = 1; $s <= 6; $s++) {
                $t  = $s / 6;
                $bx = $x1 + ($x2 - $x1) * $t;
                $by = $y1 + ($y2 - $y1) * $t;
                $dx = $cx - $bx; $dy = $cy - $by;
                $d  = max(1.0, sqrt($dx * $dx + $dy * $dy));
                $dip = $sag * sin(M_PI * $t);
                $qx = $bx + $dx / $d * $dip;
                $qy = $by + $dy / $d * $dip;
                imageline($im, (int) round($px), (int) round($py), (int) round($qx), (int) round($qy), $color);
                $px = $qx; $py = $qy;
            }
        }
    }
}

// A small storm cloud centred on ($cx,$cy), about 100px wide: rain streaks
// under it and a lightning bolt. A still shows the cloud with one bolt
// frozen mid-strike; an animation ($anim = ['frame', 'frames']) strikes
// twice per loop — the cloud and a halo flare for the frames the bolt is
// up — while the rain falls eight cycles per loop so it closes cleanly.
function ig_storm_cloud($im, $cx, $cy, $anim = null) {
    $t = $anim !== null ? $anim['frame'] / $anim['frames'] : 0.0;
    // Rain falls at a 4-second loop's pace however long the loop is (see $k
    // in ig_build_list_page_marquee()); the strikes are timed separately.
    $k = $anim !== null ? ($anim['frames'] / $anim['fps']) / 4 : 1;

    // Which bolt is up, if any: 0 none, 1 first strike, 2 second.
    $bolt = 0;
    if ($anim === null)                   $bolt = 1;
    // Two strikes, each up for exactly 3 frames (a quarter-second): frames
    // 20-22 and 62-64 of the 96-frame loop — slow weather, quick flash.
    elseif ($t >= 20 / 96 && $t < 23 / 96) $bolt = 1;
    elseif ($t >= 62 / 96 && $t < 65 / 96) $bolt = 2;
    $flash = $anim !== null && $bolt > 0;

    if ($flash) {
        foreach ([46, 34, 22] as $rad) {
            imagefilledellipse($im, $cx + ($bolt === 2 ? 30 : 8), $cy + 46, $rad * 2, $rad * 2, imagecolorallocatealpha($im, 0xFF, 0xF0, 0xB0, 122));
        }
    }

    $body  = $flash ? imagecolorallocate($im, 0x8A, 0x91, 0xB0) : imagecolorallocate($im, 0x3B, 0x40, 0x52);
    $under = $flash ? imagecolorallocate($im, 0x6A, 0x71, 0x90) : imagecolorallocate($im, 0x2B, 0x2F, 0x3E);
    $rim   = imagecolorallocatealpha($im, 0x9A, 0xA2, 0xC4, 90);

    // Flat-bottomed cumulus: a shaded base, then overlapping puffs on top.
    imagefilledellipse($im, $cx + 6, $cy + 12, 100, 26, $under);
    foreach ([[-28, 6, 40], [-6, -6, 52], [22, -2, 44], [40, 8, 32]] as [$dx, $dy, $d]) {
        imagefilledellipse($im, $cx + $dx, $cy + $dy, $d, $d, $body);
    }
    imagefilledellipse($im, $cx + 6, $cy + 10, 98, 22, $body);
    foreach ([[-6, -6, 52], [22, -2, 44]] as [$dx, $dy, $d]) {
        imagearc($im, $cx + $dx, $cy + $dy, $d, $d, 200, 340, $rim);
    }

    // Rain: six short streaks that wrap from the cloud's base down to just
    // above the date headline.
    $rain = imagecolorallocatealpha($im, 0x9F, 0xB4, 0xE0, 60);
    $yTop = $cy + 26;
    $span = 44;
    foreach ([-34, -17, 0, 17, 34, 50] as $k => $dx) {
        $phase = fmod($k * 0.23, 1.0);
        $y = $yTop + (fmod($t * 8 * $k + $phase, 1.0)) * $span;
        imageline($im, $cx + $dx, (int) $y, $cx + $dx - 3, (int) $y + 10, $rain);
    }

    if ($bolt > 0) {
        $shape = $bolt === 1
            ? [[10, 26], [-4, 46], [5, 46], [-10, 70], [16, 40], [6, 40], [18, 26]]
            : [[40, 26], [54, 44], [45, 44], [58, 68], [32, 40], [42, 40], [30, 26]];
        $pts = [];
        foreach ($shape as [$dx, $dy]) { $pts[] = $cx + $dx; $pts[] = $cy + $dy; }
        imagefilledpolygon($im, $pts, imagecolorallocate($im, 0xFF, 0xF3, 0xB0));
    }
}

// A spider seen from above, head up: a small thorax, a larger abdomen below it
// and eight two-jointed legs (knees raised, feet planted). $size is roughly the
// width of the abdomen; the legs reach about 1.4x that to either side.
// ($x,$y) is where a hanging spider's thread ends and the body begins — pass
// $thread > 0 for a line from ($x,$y-$thread) down to it, 0 for a spider
// sitting or crawling on something. $mark, when given, paints a black widow's
// hourglass on the abdomen in that colour.
// Animation hooks, both off by default (the still is unchanged): $anchorX
// moves the top of the thread to a different x than the body, so a swinging
// spider hangs from a fixed point on an angled line; $legPhase (radians)
// makes the legs twitch, each at its own offset around that phase.
function ig_spider($im, $x, $y, $thread, $size, $color, $mark = null, $anchorX = null, $legPhase = null) {
    $s  = $size;
    $tx = $x;            $ty = $y + 0.22 * $s;   // thorax centre
    $ax = $x;            $ay = $y + 0.80 * $s;   // abdomen centre

    if ($thread > 0) {
        imageline($im, (int) round($anchorX ?? $x), (int) round($y - $thread), (int) round($x), (int) round($ty), $color);
    }

    // [root, knee, foot] per leg, in units of $s from the thorax centre,
    // mirrored for the other side. Front legs reach up and out, the rear
    // pair sweeps back and down.
    $legs = [
        [[0.12, -0.08], [0.70, -0.64], [1.22, -0.26]],
        [[0.18,  0.00], [0.86, -0.26], [1.42,  0.26]],
        [[0.18,  0.08], [0.86,  0.30], [1.34,  0.86]],
        [[0.14,  0.14], [0.62,  0.72], [0.94,  1.42]],
    ];
    imagesetthickness($im, max(1, (int) round($s / 11)));
    foreach ([1, -1] as $m) {
        foreach ($legs as $li => [$r, $k, $f]) {
            $rx = $tx + $m * $r[0] * $s; $ry = $ty + $r[1] * $s;
            $kx = $tx + $m * $k[0] * $s; $ky = $ty + $k[1] * $s;
            $fx = $tx + $m * $f[0] * $s; $fy = $ty + $f[1] * $s;
            if ($legPhase !== null) {
                // Opposite sides twitch in opposition and each pair a beat
                // apart, so the legs ripple rather than all bobbing together.
                $ph  = $legPhase + $li * 1.1 + ($m < 0 ? M_PI : 0);
                $ky += 0.05 * $s * sin($ph);
                $fy += 0.11 * $s * sin($ph + 0.7);
            }
            imageline($im, (int) round($rx), (int) round($ry), (int) round($kx), (int) round($ky), $color);
            imageline($im, (int) round($kx), (int) round($ky), (int) round($fx), (int) round($fy), $color);
        }
    }
    imagesetthickness($im, 1);

    imagefilledellipse($im, (int) round($ax), (int) round($ay), (int) round(0.95 * $s), (int) round(1.15 * $s), $color);
    imagefilledellipse($im, (int) round($tx), (int) round($ty), (int) round(0.6 * $s), (int) round(0.58 * $s), $color);

    if ($mark !== null) {
        $hw = 0.15 * $s; $hh = 0.24 * $s;
        imagefilledpolygon($im, [
            (int) round($ax - $hw), (int) round($ay - $hh), (int) round($ax + $hw), (int) round($ay - $hh), (int) round($ax), (int) round($ay),
        ], $mark);
        imagefilledpolygon($im, [
            (int) round($ax - $hw), (int) round($ay + $hh), (int) round($ax + $hw), (int) round($ay + $hh), (int) round($ax), (int) round($ay),
        ], $mark);
    }
}

// Smoothstep: 0 -> 1 with zero slope at both ends, for motion that eases in
// and out.
function ig_ease($x) {
    $x = max(0.0, min(1.0, $x));
    return $x * $x * (3 - 2 * $x);
}

// A four-point sparkle at ($x,$y), $size px from centre to tip: two thin
// diamonds crossed. $color should already carry whatever alpha it needs.
function ig_glint($im, $x, $y, $size, $color) {
    $n = 0.2 * $size;
    imagefilledpolygon($im, [(int) round($x), (int) round($y - $size), (int) round($x + $n), (int) round($y),
                             (int) round($x), (int) round($y + $size), (int) round($x - $n), (int) round($y)], $color);
    imagefilledpolygon($im, [(int) round($x - $size), (int) round($y), (int) round($x), (int) round($y - $n),
                             (int) round($x + $size), (int) round($y), (int) round($x), (int) round($y + $n)], $color);
}

// The point where a cobweb's spoke at $deg meets its ring at fraction $f of
// $len — the same geometry ig_cobweb() uses, so a glint placed here sits on
// a real junction of the web it was asked about.
function ig_web_junction($cx, $cy, $len, $sx, $sy, $deg, $f) {
    $rad = deg2rad($deg);
    return [$cx + $sx * $len * $f * cos($rad), $cy + $sy * $len * $f * sin($rad)];
}

// A tiny spider facing $heading (radians; 0 is up, increasing clockwise on
// screen), seen from above like ig_spider() but turned to face where it is
// going. Drawn as rotated polygons rather than ig_spider()'s axis-aligned
// ellipses, which is why it is its own function: ig_spider() has to stay
// byte-for-byte what the stills were drawn with. $legPhase (radians) runs the
// legs and $scuttle (0..1) says how hard — full when walking, a gentle
// paddle when turning on the spot.
function ig_spider_crawl($im, $x, $y, $size, $color, $heading, $legPhase, $scuttle) {
    $co = cos($heading); $si = sin($heading); $s = $size;
    $P = fn($lx, $ly) => [$x + ($lx * $co - $ly * $si) * $s, $y + ($lx * $si + $ly * $co) * $s];
    $ellipse = function ($lx, $ly, $rx, $ry) use ($im, $P, $color) {
        $pts = [];
        for ($i = 0; $i < 20; $i++) { $a = 2 * M_PI * $i / 20; [$px, $py] = $P($lx + $rx * cos($a), $ly + $ry * sin($a)); $pts[] = (int) round($px); $pts[] = (int) round($py); }
        imagefilledpolygon($im, $pts, $color);
    };
    $legs = [
        [[0.12, -0.08], [0.70, -0.64], [1.22, -0.26]],
        [[0.18,  0.00], [0.86, -0.26], [1.42,  0.26]],
        [[0.18,  0.08], [0.86,  0.30], [1.34,  0.86]],
        [[0.14,  0.14], [0.62,  0.72], [0.94,  1.42]],
    ];
    imagesetthickness($im, max(1, (int) round($s / 9)));
    foreach ([1, -1] as $m) {
        foreach ($legs as $li => [$r, $k, $f]) {
            // Alternate legs lift in turn (the tripod gait): each leg's foot
            // swings fore and aft on its own beat.
            $ph = $legPhase + $li * 1.6 + ($m < 0 ? M_PI : 0);
            $swing = $scuttle * 0.28 * sin($ph);
            [$ax, $ay] = $P($m * $r[0], $r[1]);
            [$bx, $by] = $P($m * $k[0], $k[1] + 0.5 * $swing);
            [$cx2, $cy2] = $P($m * $f[0], $f[1] + $swing);
            imageline($im, (int) round($ax), (int) round($ay), (int) round($bx), (int) round($by), $color);
            imageline($im, (int) round($bx), (int) round($by), (int) round($cx2), (int) round($cy2), $color);
        }
    }
    imagesetthickness($im, 1);
    $ellipse(0, 0.58, 0.48, 0.58);
    $ellipse(0, 0.0, 0.30, 0.29);
}

// A plain pumpkin sitting on $baseY, $r px from its centre to either side
// (so 2*$r wide, about 1.75*$r tall): overlapping lobes for the ribs, a
// darker outline on each, and a short stem. Drawn bottom-anchored so two
// different sizes line up on the same ground without hand-tuning each cy.
function ig_pumpkin($im, $cx, $baseY, $r, $face = false, $anim = null) {
    $shade = imagecolorallocate($im, 0xB0, 0x4A, 0x0E);
    $mid   = imagecolorallocate($im, 0xD9, 0x6A, 0x18);
    $base  = imagecolorallocate($im, 0xEC, 0x7E, 0x22);
    $light = imagecolorallocate($im, 0xF5, 0x95, 0x38);
    $stem  = imagecolorallocate($im, 0x5E, 0x6B, 0x2A);

    $hMax = (int) round(1.75 * $r);
    $cy   = (int) round($baseY - $hMax / 2);

    // [dx, width, height, fill] — outermost lobes first so the centre rib
    // lands on top.
    $lobes = [
        [-0.64, 0.96, 1.52, $mid],  [0.64, 0.96, 1.52, $mid],
        [-0.34, 1.20, 1.68, $base], [0.34, 1.20, 1.68, $base],
        [0.0,   1.10, 1.75, $light],
    ];
    foreach ($lobes as [$dx, $wf, $hf, $fill]) {
        $x = (int) round($cx + $dx * $r);
        $h = (int) round($hf * $r);
        imagefilledellipse($im, $x, $cy, (int) round($wf * $r), $h, $fill);
        imageellipse($im, $x, $cy, (int) round($wf * $r), $h, $shade);
    }

    // The face is cut out as solid black shapes: they sit against the
    // orange rind, so they read as holes even on the near-black card. (A
    // glowing face was tried first; every amber shade either washed into
    // the rind or needed a halo.) $glow keeps its name from that version.
    if ($face) {
        $glow = imagecolorallocate($im, 0x0B, 0x0A, 0x08);
        $poly = function (array $rel, $color, $scale = 1.0, $center = null) use ($im, $cx, $cy, $r) {
            // Scaled about the vertex average unless told otherwise; the
            // jagged grin's vertex average sits well off its visual centre,
            // so it passes its own.
            [$mx, $my] = $center ?? [array_sum(array_column($rel, 0)) / count($rel), array_sum(array_column($rel, 1)) / count($rel)];
            $pts = [];
            foreach ($rel as [$px, $py]) {
                $pts[] = (int) round($cx + ($mx + ($px - $mx) * $scale) * $r);
                $pts[] = (int) round($cy + ($my + ($py - $my) * $scale) * $r);
            }
            imagefilledpolygon($im, $pts, $color);
        };
        $features = [
            'eyeL'  => [[-0.66, -0.02], [-0.20, -0.02], [-0.43, -0.46]],
            'eyeR'  => [[ 0.20, -0.02], [ 0.66, -0.02], [ 0.43, -0.46]],
            'nose'  => [[-0.10,  0.20], [ 0.10,  0.20], [ 0.00,  0.03]],
            'grin'  => [
                [-0.66, 0.30], [-0.42, 0.44], [-0.26, 0.30], [-0.10, 0.46], [0.06, 0.30],
                [ 0.22, 0.46], [ 0.40, 0.30], [ 0.66, 0.28], [0.50, 0.60], [0.20, 0.70],
                [-0.20, 0.70], [-0.50, 0.60],
            ],
        ];

        if ($anim === null) {
            foreach ($features as $rel) $poly($rel, $glow);
        } else {
            // Candle-lit: each cutout keeps its black silhouette (so the
            // shape stays readable against the rind — a flat amber fill
            // washes straight into it) with a flame inside that flickers
            // across a narrow band of warm yellows. A slow swell (whole
            // cycles per loop, so it closes) plus a quick per-frame jitter —
            // the jitter is what reads as flame rather than a pulse.
            $t = $anim['frame'] / $anim['frames'];
            // One flame for the whole face — a single brightness shared by
            // every cutout, since it is one candle lighting all of them —
            // kept to a narrow, mostly-bright range so it reads as a gentle
            // flicker rather than a strobe.
            // The swell's cycle counts scale with the loop's length in
            // seconds (0.75 and 1.75 cycles a second), so the flame keeps
            // its speed however long the loop is; the jitter is per frame.
            $secs   = $anim['frames'] / $anim['fps'];
            $swell  = 0.05 * sin(2 * M_PI * (0.75 * $secs * $t)) + 0.03 * sin(2 * M_PI * (1.75 * $secs * $t));
            $jitter = ((crc32('flame:' . $anim['frame']) % 100) / 100 - 0.5) * 0.14;
            $b = max(0.0, min(1.0, 0.80 + $swell + $jitter));
            $lights = [];
            foreach ($features as $rel) $lights[] = [$rel, $b];
            // A faint spill of light onto the rind around each cut, then the
            // black cutouts, then the flames inside them.
            $grinCenter = [0.0, 0.48];
            foreach ($lights as [$rel, $b]) {
                $isGrin = count($rel) > 6;
                $poly($rel, imagecolorallocatealpha($im, 0xFF, 0xC8, 0x50, 125 - (int) round(10 * $b)), 1.3, $isGrin ? $grinCenter : null);
            }
            foreach ($lights as [$rel, $b]) $poly($rel, $glow);
            foreach ($lights as [$rel, $b]) {
                $isGrin = count($rel) > 6;
                $flame = imagecolorallocate(
                    $im,
                    (int) round(0x8A + (0xFF - 0x8A) * $b),
                    (int) round(0x2E + (0xE0 - 0x2E) * $b),
                    (int) round(0x04 + (0x70 - 0x04) * $b)
                );
                $poly($rel, $flame, $isGrin ? 0.82 : 0.68, $isGrin ? $grinCenter : null);
            }
        }
    }

    $stemW = max(4, (int) round(0.30 * $r));
    $stemH = max(6, (int) round(0.40 * $r));
    $top   = $cy - (int) round($hMax / 2);
    imagefilledpolygon($im, [
        $cx - (int) round($stemW / 2) - 1, $top + 2,
        $cx - (int) round($stemW / 2) + 2, $top - $stemH,
        $cx + (int) round($stemW / 2) + 3, $top - $stemH + 2,
        $cx + (int) round($stemW / 2),     $top + 2,
    ], $stem);
}

// A filled bat silhouette, wings spread, ~$size px across half-span. Drawn
// as polygons rather than ig_bat_mark()'s two arcs — those read as distant
// birds, this reads as a bat at close range.
function ig_bat_silhouette($im, $x, $y, $size, $color, $flap = 0.0) {
    $wing = [[0.12, -0.10], [0.55, -0.42], [1.0, -0.30], [0.86, 0.0], [0.76, 0.26], [0.55, 0.06], [0.35, 0.30], [0.15, 0.12]];
    foreach ([1, -1] as $m) {
        $pts = [];
        foreach ($wing as [$wx, $wy]) {
            // $flap (-1..1) swings the wings: raised at 1, lowered at -1,
            // the tips moving most and the root not at all. 0 is the
            // original spread-wing pose.
            $pts[] = (int) round($x + $m * $wx * $size);
            $pts[] = (int) round($y + ($wy - $flap * $wx * 0.7) * $size);
        }
        imagefilledpolygon($im, $pts, $color);
        imagefilledpolygon($im, [
            (int) round($x + $m * 0.10 * $size), (int) round($y - 0.12 * $size),
            (int) round($x + $m * 0.09 * $size), (int) round($y - 0.34 * $size),
            (int) round($x),                     (int) round($y - 0.14 * $size),
        ], $color);
    }
    imagefilledellipse($im, (int) $x, (int) $y, (int) round(0.26 * $size), (int) round(0.46 * $size), $color);
}

// A point a fraction $u (0..1) of the way, by distance, along the smooth
// (Catmull-Rom) curve through the waypoints $pts, with the unit normal there
// (to the right of the direction of travel) — so a flyer can be wobbled
// sideways. Constant speed, which a bat's flight is not far from.
function ig_path_point(array $pts, $u) {
    $n = count($pts);
    $at = fn($i) => $pts[max(0, min($n - 1, $i))];
    $samples = [];
    for ($i = 0; $i < $n - 1; $i++) {
        [$p0, $p1, $p2, $p3] = [$at($i - 1), $at($i), $at($i + 1), $at($i + 2)];
        for ($k = 0; $k < 24; $k++) {
            $t = $k / 24; $t2 = $t * $t; $t3 = $t2 * $t;
            $samples[] = [
                0.5 * (2 * $p1[0] + (-$p0[0] + $p2[0]) * $t + (2 * $p0[0] - 5 * $p1[0] + 4 * $p2[0] - $p3[0]) * $t2 + (-$p0[0] + 3 * $p1[0] - 3 * $p2[0] + $p3[0]) * $t3),
                0.5 * (2 * $p1[1] + (-$p0[1] + $p2[1]) * $t + (2 * $p0[1] - 5 * $p1[1] + 4 * $p2[1] - $p3[1]) * $t2 + (-$p0[1] + 3 * $p1[1] - 3 * $p2[1] + $p3[1]) * $t3),
            ];
        }
    }
    $samples[] = $pts[$n - 1];
    $cum = [0.0];
    for ($i = 1; $i < count($samples); $i++) $cum[] = $cum[$i - 1] + hypot($samples[$i][0] - $samples[$i - 1][0], $samples[$i][1] - $samples[$i - 1][1]);
    $d = $u * $cum[count($cum) - 1];
    $i = 1; while ($i < count($cum) - 1 && $cum[$i] < $d) $i++;
    $f = ($d - $cum[$i - 1]) / max(1e-9, $cum[$i] - $cum[$i - 1]);
    $x = $samples[$i - 1][0] + ($samples[$i][0] - $samples[$i - 1][0]) * $f;
    $y = $samples[$i - 1][1] + ($samples[$i][1] - $samples[$i - 1][1]) * $f;
    $dx = $samples[$i][0] - $samples[$i - 1][0]; $dy = $samples[$i][1] - $samples[$i - 1][1]; $l = max(1e-9, hypot($dx, $dy));
    return [$x, $y, -$dy / $l, $dx / $l];
}

function ig_build_list_page_paper(array $films, $date, $moreCount = 0, $anim = null) {
    $w = 1080;
    $h = 1350;
    $im = imagecreatetruecolor($w, $h);

    // $anim = ['frame', 'frames', 'fps'] renders one frame of the looping
    // October version (see ig_theme_animates()); null is the still, which is
    // unaffected. Every moving part is a whole number of cycles per loop so
    // the last frame leads straight back into the first.
    $t = $anim !== null ? $anim['frame'] / $anim['frames'] : 0.0;

    // In October the paper warms to a parchment and the brand red turns a
    // burnt pumpkin orange (the fall half of the seasonal look; the
    // Halloween half is drawn after the top rule below). The variable keeps
    // its $red name — it is "the accent", whichever hue the season makes it.
    $season  = ig_halloween_season($date);
    $paper   = ig_hex($im, $season ? '#F6EBD9' : '#F4F1EB');
    $red     = ig_hex($im, $season ? '#B8531A' : '#922E32');
    $ink     = ig_hex($im, '#14120F');
    $muted   = ig_hex($im, $season ? '#76654F' : '#6B6659');
    $divider = ig_hex($im, $season ? '#E2D3B8' : '#DED7C7');
    $placeholder = ig_hex($im, $season ? '#EBDCC3' : '#E4DECE');
    // A few points darker than $paper — extremely subtle by design, same
    // idea as Newsprint/Neon's own zebra fill, just tinted for this palette.
    $stripe  = ig_hex($im, $season ? '#EFE1CA' : '#ECE7DD');

    imagefill($im, 0, 0, $paper);

    // Red rule under the header, same visual role as the accent bars in v7.
    imagefilledrectangle($im, 0, 0, $w, 14, $red);

    if ($season) {
        // The Halloween half, and for Paper it is spiders. The header's
        // right-hand band is empty (the kicker and date sit left and lower),
        // so a big web fills the top-right corner with a black widow hanging
        // inside it and a smaller spider off to its left; the bottom-right
        // has a web of its own, drawn later over the list. The widow's
        // hourglass is the accent colour. The top-left stays clear for the
        // wordmark chip, and the left of the footer for its text.
        $web  = imagecolorallocatealpha($im, 0x4A, 0x3B, 0x2E, 78);
        $dark = ig_hex($im, '#1A1511');

        // Animated: both spiders sway on their threads (the top of each
        // thread stays put while the body swings) and lower and raise
        // themselves, legs twitching; the webs shiver. Still: all zeros.
        $wobTop = $wobBot = 0.0;
        $big = ['x' => 925.0, 'len' => 100.0, 'phase' => null];
        $small = ['x' => 800.0, 'len' => 54.0, 'phase' => null];
        if ($anim !== null) {
            $tau = 2 * M_PI;
            $wobTop = 0.30 * sin($tau * 2 * $t);
            $wobBot = 0.30 * sin($tau * 2 * $t + 1.7);
            // Once a loop the widow drops: a fast fall that decelerates to
            // 220px lower (about a third of a second — far enough to hang
            // below the divider and over the top of the list), a beat's
            // pause, then a slow, eased climb back up the thread (two
            // seconds). Zero outside that window, so the loop closes.
            $dropPx = 220;
            $drop = 0.0;
            if ($t >= 0.58 && $t < 0.62)     $drop = $dropPx * (1 - pow(1 - ($t - 0.58) / 0.04, 3));
            elseif ($t >= 0.62 && $t < 0.67) $drop = (float) $dropPx;
            elseif ($t >= 0.67 && $t < 0.93) $drop = $dropPx * (1 - ig_ease(($t - 0.67) / 0.26));
            $big   = ['x' => 925 + 7 * sin($tau * 2 * $t),       'len' => 100 + 8 * sin($tau * $t + 1.0) + $drop, 'phase' => $tau * 8 * $t];
            $small = ['x' => 800 + 4 * sin($tau * 3 * $t + 0.9), 'len' => 54 + 5 * sin($tau * 2 * $t + 0.6),     'phase' => $tau * 12 * $t + 2.0];
        }
        ig_cobweb($im, $w - 40, 30, 210, $web, -1, 1, $wobTop);
        if ($anim !== null) {
            // Glints: the accent colour, twinkling on junctions of the web —
            // each on its own beat, once a loop, so one is catching the light
            // somewhere most of the time. Narrow peaks (sin^8) keep each one
            // brief.
            $accent = imagecolorsforindex($im, $red);
            foreach ([[22.5, 0.46, 0.00], [45, 0.68, 0.43], [67.5, 0.46, 0.71], [45, 0.92, 0.14], [67.5, 0.92, 0.57], [22.5, 0.68, 0.86], [45, 0.26, 0.29]] as [$deg, $f, $ph]) {
                $i = pow(max(0.0, sin(2 * M_PI * ($t + $ph))), 8);
                if ($i < 0.08) continue;
                [$gx, $gy] = ig_web_junction($w - 40, 30, 210, -1, 1, $deg, $f);
                ig_glint($im, $gx, $gy, 4 + 9 * $i, imagecolorallocatealpha($im, $accent['red'], $accent['green'], $accent['blue'], 127 - (int) round(105 * $i)));
            }
        }
        // Thread tops are fixed at y 20 and y 16; the body hangs $len below.
        ig_spider($im, $big['x'],   20 + $big['len'],   $big['len'],   26, $dark, $red, 925, $big['phase']);
        ig_spider($im, $small['x'], 16 + $small['len'], $small['len'], 13, $dark, null, 800, $small['phase']);
    }

    $margin = 80;

    // "Cinema, TX" wordmark chip — the same lockup the site header uses,
    // rendered here since a posted image needs its own brand mark.
    $chipText = 'CINEMA, TX';
    $chipBox  = imagettfbbox(20, 0, IG_FONT_BODY, $chipText);
    $chipW    = $chipBox[2] - $chipBox[0];
    ig_pill($im, $margin, 70, $margin + $chipW + 48, 114, $red);
    imagettftext($im, 20, 0, $margin + 24, 100, $paper, IG_FONT_BODY, $chipText);

    $y = 180;
    imagettftext($im, 28, 0, $margin, $y, $red, IG_FONT_BODY, strtoupper('Today in Austin'));
    $y += 66;
    imagettftext($im, 50, 0, $margin, $y, $ink, IG_FONT_HEADLINE, date('l, F j', $date));
    $y += 50;

    imagefilledrectangle($im, $margin, $y, $w - $margin, $y + 1, $divider);
    $y += 40;

    // Row size adapts to how many are actually on this page — see
    // ig_list_row_geometry(). Capped at 10 outright (its own smallest
    // tier is sized for exactly that many), so there's no longer a
    // separate available-height division to get out of sync with it.
    $geo   = ig_list_row_geometry(min(count($films), 10));
    $thumbW = $geo['thumbW'];
    $thumbH = $geo['thumbH'];
    $textX  = $margin + $thumbW + 28;
    $textMaxWidth = $w - $margin - $textX;

    $rowHeight = $geo['rowHeight'];
    $footerY   = $h - 60;
    $rows      = array_slice($films, 0, 10);
    $extra     = count($films) - count($rows);

    if (empty($films)) {
        imagettftext($im, 28, 0, $margin, $y, $muted, IG_FONT_BODY, 'Nothing scraped for today — check back later.');
    }

    $lastIndex = count($rows) - 1;
    foreach ($rows as $i => $film) {
        // Extremely subtle zebra banding — every other row gets a barely
        // darker strip behind it, same technique Newsprint/Neon already use.
        if ($i % 2 === 1) {
            imagefilledrectangle($im, $margin, $y - 15, $w - $margin, $y + $rowHeight - 15, $stripe);
        }

        $thumb = ig_fetch_thumb($film['poster'], $thumbW, $thumbH);
        if ($thumb) {
            imagecopy($im, $thumb, $margin, $y, 0, 0, $thumbW, $thumbH);
            imagedestroy($thumb);
        } else {
            imagefilledrectangle($im, $margin, $y, $margin + $thumbW, $y + $thumbH, $placeholder);
            $initial = mb_strtoupper(mb_substr($film['title'], 0, 1));
            $initSize = max(20, (int) round($geo['titleSize'] * 1.1));
            $ibox = imagettfbbox($initSize, 0, IG_FONT_HEADLINE, $initial);
            $iw = $ibox[2] - $ibox[0];
            imagettftext($im, $initSize, 0, (int) ($margin + ($thumbW - $iw) / 2), $y + (int) ($thumbH / 2) + (int) round($initSize / 3), $muted, IG_FONT_HEADLINE, $initial);
        }

        $title = ig_fit_text(mb_strtoupper($film['title']), IG_FONT_HEADLINE, $geo['titleSize'], $textMaxWidth);
        imagettftext($im, $geo['titleSize'], 0, $textX, $y + $geo['titleOffsetY'], $ink, IG_FONT_HEADLINE, $title);

        // Flick Clique names the monthly series, not a place — same reasoning
        // as the website's own poster card (list/index.php): the location is
        // what's actually useful on a compact list row, the series name isn't.
        // Every other venue with a location (Alamo, opted in per day) still
        // needs both to disambiguate which of five it is.
        $venue = $film['venue'] === 'Flick Clique' && $film['location']
            ? $film['location']
            : ($film['location'] ? "{$film['venue']} — {$film['location']}" : $film['venue']);
        if ($film['director']) $venue .= '  ·  dir. ' . $film['director'];
        $meta  = ig_fit_text($venue, IG_FONT_BODY, $geo['metaSize'], $textMaxWidth);
        imagettftext($im, $geo['metaSize'], 0, $textX, $y + $geo['metaOffsetY'], $muted, IG_FONT_BODY, $meta);

        $time = ig_fit_text(ig_format_times($film['timestamps'] ?? [$film['timestamp']]), IG_FONT_BODY, $geo['metaSize'], $textMaxWidth);
        imagettftext($im, $geo['metaSize'], 0, $textX, $y + $geo['timeOffsetY'], $red, IG_FONT_BODY, $time);

        $y += $rowHeight;
        if ($i < $lastIndex) {
            imagefilledrectangle($im, $margin, $y - 15, $w - $margin, $y - 14, $divider);
        }
    }

    // The bottom-right web goes down after the rows, not before them, so it
    // lies across the corner of the list itself — over the last row's stripe
    // — rather than being painted over by it. ($web is the October colour
    // allocated with the other Halloween pieces above.)
    if ($season) {
        ig_cobweb($im, $w - 46, $h - 46, 180, $web, -1, -1, $wobBot);

        if ($anim !== null) {
            // Glints on this web too, drawn over the list with it.
            $accent = imagecolorsforindex($im, $red);
            foreach ([[22.5, 0.68, 0.07], [45, 0.46, 0.50], [67.5, 0.68, 0.79], [45, 0.92, 0.21], [22.5, 0.92, 0.64], [67.5, 0.46, 0.36]] as [$deg, $f, $ph]) {
                $i = pow(max(0.0, sin(2 * M_PI * ($t + $ph))), 8);
                if ($i < 0.08) continue;
                [$gx, $gy] = ig_web_junction($w - 46, $h - 46, 180, -1, -1, $deg, $f);
                ig_glint($im, $gx, $gy, 4 + 9 * $i, imagecolorallocatealpha($im, $accent['red'], $accent['green'], $accent['blue'], 127 - (int) round(105 * $i)));
            }

            // A tiny spider patrols this web and the corner of the list: out
            // along a diagonal from the web's corner, a turn on the spot, back
            // again, another turn — facing the way it is going throughout.
            // The timeline is shifted so frame 0 falls in the middle of the
            // turn at the corner, and every segment is eased, so the loop
            // closes with the spider already standing still.
            $ax = 1012.0; $ay = 1292.0; $bx = 940.0; $by = 1219.0;
            $hAB = atan2($bx - $ax, -($by - $ay));
            $u = fmod($t + 0.93, 1.0);
            if ($u < 0.38)      { $s = ig_ease($u / 0.38);                 $hd = $hAB;                                   $sc = 0.2 + 0.8 * sin(M_PI * $u / 0.38); }
            elseif ($u < 0.48)  { $s = 1.0;                                $hd = $hAB + M_PI * ig_ease(($u - 0.38) / 0.10); $sc = 0.3; }
            elseif ($u < 0.86)  { $s = 1 - ig_ease(($u - 0.48) / 0.38);    $hd = $hAB + M_PI;                            $sc = 0.2 + 0.8 * sin(M_PI * ($u - 0.48) / 0.38); }
            else                { $s = 0.0;                                $hd = $hAB + M_PI + M_PI * ig_ease(($u - 0.86) / 0.14); $sc = 0.3; }
            ig_spider_crawl($im, $ax + ($bx - $ax) * $s, $ay + ($by - $ay) * $s, 13, $dark, $hd, 2 * M_PI * 20 * $t, $sc);
        }
    }

    // $extra is this page's own row-capacity overflow (Default mode, or a
    // Manual per-page count set higher than actually fits); $moreCount is
    // screenings waiting on a later carousel page. Either way there's more
    // than what's showing, but only $moreCount means there's somewhere to
    // actually swipe to — that's the only case that earns the arrow.
    $totalMore = $extra + $moreCount;
    if ($totalMore > 0) {
        $label = "+{$totalMore} MORE";
        // Centered in whatever room is actually left below the last row,
        // not hugging it — a page well under its 6-row capacity can leave a
        // lot of that room, and a fixed offset left the line sitting right
        // against the last poster regardless of how much space followed it.
        $midY  = $y + (int) round(($footerY - 30 - $y) / 2);
        $textY = $midY + 8;
        imagettftext($im, 28, 0, $margin, $textY, $red, IG_FONT_BODY, $label);
        if ($moreCount > 0) {
            $box = imagettfbbox(28, 0, IG_FONT_BODY, $label);
            $tw  = $box[2] - $box[0];
            $ax  = $margin + $tw + 22;
            ig_draw_arrow($im, $ax, $textY - 12, $ax + 34, $red);
        }
    }

    imagettftext($im, 22, 0, $margin, $footerY, $muted, IG_FONT_BODY, 'Full schedule at cinematx.net');

    return $im;
}

// A classic theatre marquee, at night: black background, a border of gold
// bulbs, showtimes in the same gold rather than paper's red. Row geometry
// comes from the shared ig_list_row_geometry() — only the palette and the
// bulb frame change, so this stays a drop-in for the same pagination math.
function ig_build_list_page_marquee(array $films, $date, $moreCount = 0, $anim = null) {
    $w = 1080;
    $h = 1350;
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);

    // $anim = ['frame' => int, 'frames' => int, 'fps' => int] renders one
    // frame of the looping version of this panel (96 frames at 12 fps, an
    // 8-second loop); null is the ordinary still and is unchanged. Every
    // moving part is a whole number of cycles over the loop so the last
    // frame leads straight back into the first.
    $t = $anim !== null ? $anim['frame'] / $anim['frames'] : 0.0;
    // The bats, rain and moon move at the pace of a 4-second loop; on a
    // longer one they simply repeat more cycles of it. $k stays a whole
    // number while the loop is a multiple of 4 seconds, so they still close.
    $k = $anim !== null ? ($anim['frames'] / $anim['fps']) / 4 : 1;

    // In October the marquee's gold bulbs and accents turn pumpkin orange
    // (the fall half of the seasonal look); the Halloween half is drawn
    // after the bulbs below. The variable keeps its $gold name — it is
    // "the accent", whichever hue the season makes it.
    $season      = ig_halloween_season($date);
    [$ar, $ag, $ab] = $season ? [0xF2, 0x8A, 0x2E] : [0xF2, 0xC1, 0x4E];

    $bg          = ig_hex($im, '#14120F');
    $ink         = ig_hex($im, '#F2EEE5');
    $muted       = ig_hex($im, '#B5AFA0');
    $divider     = ig_hex($im, '#3A362E');
    $placeholder = ig_hex($im, '#2A2620');
    $gold        = imagecolorallocate($im, $ar, $ag, $ab);
    $goldGlow    = imagecolorallocatealpha($im, $ar, $ag, $ab, 100);
    // A touch lighter than $bg, not darker — this palette is already near-
    // black, so "subtle" here means barely lifting off it rather than
    // deepening a shadow.
    $stripe      = ig_hex($im, $season ? '#211810' : '#1C1912');

    imagefill($im, 0, 0, $bg);
    ig_marquee_bulbs($im, 28, 28, $w - 28, $h - 28, 40, $goldGlow, $gold, $season, $anim);

    if ($season) {
        // A pale moon in the empty band right of the kicker (the date
        // headline sits below it), bats crossing it, and cobwebs in the two
        // right-hand corners — the left ones would sit under the kicker and
        // the footer text.
        $mx = 770; $my = 98; $mr = 46;
        // The moon's halo breathes: its rings brighten and settle once per loop.
        $pulse = $anim === null ? 0 : (int) round(9 * (0.5 + 0.5 * sin(2 * M_PI * $k * $t)));
        foreach ([34, 24, 14] as $grow) {
            imagefilledellipse($im, $mx, $my, ($mr + $grow) * 2, ($mr + $grow) * 2, imagecolorallocatealpha($im, $ar, $ag, $ab, 120 - $pulse));
        }
        imagefilledellipse($im, $mx, $my, $mr * 2, $mr * 2, ig_hex($im, '#EADFC2'));
        // A little storm cloud over the moon's left edge, drawn before the
        // bats so they fly in front of it.
        ig_storm_cloud($im, $mx - 66, $my - 8, $anim);
        $batDark = ig_hex($im, '#0B0A08');
        $batDim  = ig_hex($im, '#6A3A16');
        // Each bat: [base x, base y, size, colour, x-sway, y-sway, phase].
        // Sways are one lap of a loop-closing figure-eight (x once, y twice
        // per loop); wings flap eight times per loop at their own phase.
        $bats = [
            [$mx - 8,  $my + 6,  30, $batDark, 14,  8, 0.0],
            [$mx + 30, $my - 20, 17, $batDark, -12, 7, 1.3],
            [$mx + 92, $my + 14, 20, $batDim,  18, 12, 2.1],
            [$mx - 96, $my - 8,  15, $batDim, -16, 10, 4.0],
            [$mx + 124, $my - 34, 16, $batDim, -14, 9, 5.2],
        ];
        foreach ($bats as [$bx, $by, $bs, $bc, $sx, $sy, $ph]) {
            if ($anim === null) {
                ig_bat_silhouette($im, $bx, $by, $bs, $bc);
                continue;
            }
            ig_bat_silhouette(
                $im,
                $bx + $sx * cos(2 * M_PI * $k * $t + $ph),
                $by + $sy * sin(4 * M_PI * $k * $t + $ph),
                $bs,
                $bc,
                sin(2 * M_PI * (8 * $k * $t + $ph / 6))
            );
        }
        $web = imagecolorallocatealpha($im, 0xB5, 0xAF, 0xA0, 78);
        // Small webs in the two top corners — the left one is kept just
        // short of the kicker beneath it — and a big one in the bottom-right
        // with the pumpkins sitting over its left edge.
        ig_cobweb($im, 46, 46, 80, $web, 1, 1);
        ig_cobweb($im, $w - 46, 46, 80, $web, -1, 1);
        ig_cobweb($im, $w - 46, $h - 46, 180, $web, -1, -1);
        ig_pumpkin($im, $w - 190, $h - 50, 44, true, $anim);
        ig_pumpkin($im, $w - 268, $h - 50, 28);
    }

    $margin = 80;

    $y = 150;
    imagettftext($im, 26, 0, $margin, $y, $gold, IG_FONT_BODY, strtoupper('Showing Tonight'));
    $y += 66;
    imagettftext($im, 50, 0, $margin, $y, $ink, IG_FONT_HEADLINE, date('l, F j', $date));
    $y += 50;

    imagefilledrectangle($im, $margin, $y, $w - $margin, $y + 1, $divider);
    $y += 40;

    $geo   = ig_list_row_geometry(min(count($films), 10));
    $thumbW = $geo['thumbW'];
    $thumbH = $geo['thumbH'];
    $textX  = $margin + $thumbW + 28;
    $textMaxWidth = $w - $margin - $textX;

    $rowHeight = $geo['rowHeight'];
    $footerY   = $h - 60;
    $rows      = array_slice($films, 0, 10);
    $extra     = count($films) - count($rows);

    if (empty($films)) {
        imagettftext($im, 28, 0, $margin, $y, $muted, IG_FONT_BODY, 'Nothing scraped for today — check back later.');
    }

    $lastIndex = count($rows) - 1;
    foreach ($rows as $i => $film) {
        if ($i % 2 === 1) {
            imagefilledrectangle($im, $margin, $y - 15, $w - $margin, $y + $rowHeight - 15, $stripe);
        }

        $thumb = ig_fetch_thumb($film['poster'], $thumbW, $thumbH);
        if ($thumb) {
            imagecopy($im, $thumb, $margin, $y, 0, 0, $thumbW, $thumbH);
            imagedestroy($thumb);
        } else {
            imagefilledrectangle($im, $margin, $y, $margin + $thumbW, $y + $thumbH, $placeholder);
            $initial = mb_strtoupper(mb_substr($film['title'], 0, 1));
            $initSize = max(20, (int) round($geo['titleSize'] * 1.1));
            $ibox = imagettfbbox($initSize, 0, IG_FONT_MARQUEE_TITLE, $initial);
            $iw = $ibox[2] - $ibox[0];
            imagettftext($im, $initSize, 0, (int) ($margin + ($thumbW - $iw) / 2), $y + (int) ($thumbH / 2) + (int) round($initSize / 3), $gold, IG_FONT_MARQUEE_TITLE, $initial);
        }

        $title = ig_fit_text(mb_strtoupper($film['title']), IG_FONT_MARQUEE_TITLE, $geo['titleSize'], $textMaxWidth);
        imagettftext($im, $geo['titleSize'], 0, $textX, $y + $geo['titleOffsetY'], $ink, IG_FONT_MARQUEE_TITLE, $title);

        // Flick Clique names the monthly series, not a place — same reasoning
        // as the website's own poster card (list/index.php): the location is
        // what's actually useful on a compact list row, the series name isn't.
        // Every other venue with a location (Alamo, opted in per day) still
        // needs both to disambiguate which of five it is.
        $venue = $film['venue'] === 'Flick Clique' && $film['location']
            ? $film['location']
            : ($film['location'] ? "{$film['venue']} — {$film['location']}" : $film['venue']);
        if ($film['director']) $venue .= '  ·  dir. ' . $film['director'];
        $meta  = ig_fit_text($venue, IG_FONT_BODY, $geo['metaSize'], $textMaxWidth);
        imagettftext($im, $geo['metaSize'], 0, $textX, $y + $geo['metaOffsetY'], $muted, IG_FONT_BODY, $meta);

        $time = ig_fit_text(ig_format_times($film['timestamps'] ?? [$film['timestamp']]), IG_FONT_BODY, $geo['metaSize'], $textMaxWidth);
        imagettftext($im, $geo['metaSize'], 0, $textX, $y + $geo['timeOffsetY'], $gold, IG_FONT_BODY, $time);

        $y += $rowHeight;
        if ($i < $lastIndex) {
            imagefilledrectangle($im, $margin, $y - 15, $w - $margin, $y - 14, $divider);
        }
    }

    $totalMore = $extra + $moreCount;
    if ($totalMore > 0) {
        $label = "+{$totalMore} MORE";
        $midY  = $y + (int) round(($footerY - 30 - $y) / 2);
        $textY = $midY + 8;
        imagettftext($im, 28, 0, $margin, $textY, $gold, IG_FONT_BODY, $label);
        if ($moreCount > 0) {
            $box = imagettfbbox(28, 0, IG_FONT_BODY, $label);
            $tw  = $box[2] - $box[0];
            $ax  = $margin + $tw + 22;
            ig_draw_arrow($im, $ax, $textY - 12, $ax + 34, $gold);
        }
    }

    imagettftext($im, 22, 0, $margin, $footerY, $muted, IG_FONT_BODY, 'Full schedule at cinematx.net');

    return $im;
}

// A photocopied zine flyer: cream stock, near-black ink, one loud spot
// color standing in for a Risograph's single ink drum, torn-paper dividers
// instead of ruled lines, and a typewriter face for titles. Posters stay
// full color rather than duotoned (an earlier pass tinted them the way a
// real Riso print physically has to — it can't lay down full-color
// photography — but posters are meant to read at a glance, and the wash
// worked against that). Row geometry matches paper/marquee.
function ig_build_list_page_zine(array $films, $date, $moreCount = 0, $anim = null) {
    $w = 1080;
    $h = 1350;
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);

    $paper       = ig_hex($im, '#F0EEE4');
    $pink        = ig_hex($im, '#FF3D8A');
    $ink         = ig_hex($im, '#161513');
    $muted       = ig_hex($im, '#8A8578');
    $divider     = ig_hex($im, '#C9C2AF');
    $placeholder = ig_hex($im, '#E3DDD0');
    $stripe      = ig_hex($im, '#E8E4D6');

    imagefill($im, 0, 0, $paper);

    // A thick masthead bar rather than a thin brand rule, and the wordmark
    // as a flat rectangular stamp rather than a rounded pill — the DIY,
    // cut-and-taped feel a smooth pill wouldn't carry.
    imagefilledrectangle($im, 0, 0, $w, 20, $ink);

    $margin = 80;

    $chipText = 'CINEMA, TX';
    $chipBox  = imagettfbbox(20, 0, IG_FONT_BODY, $chipText);
    $chipW    = $chipBox[2] - $chipBox[0];
    imagefilledrectangle($im, $margin, 70, $margin + $chipW + 48, 114, $pink);
    imagettftext($im, 20, 0, $margin + 24, 100, $ink, IG_FONT_BODY, $chipText);

    // October: "Today in Austin" as a ransom note, and blood running down
    // from the masthead bar (short runs over the chip and the headline, long
    // ones to the right of them).
    //
    // Animated (one 8s loop, on twos like stop-motion): the ransom tiles are
    // re-posed every other frame, each blood run swells and lets a bead go that
    // falls down the right of the page, and a poster wobbles on its tape —
    // until, two-thirds through each 8s cycle, a gust: the tape peels, the card
    // tears free and sails off to the right with its tape, the next of three
    // posters is slapped on, and the blood leans and the ransom tiles flutter
    // while the wind blows. Three cycles make the video, one gust and one new
    // poster each, and the last hands back the poster the first began with.
    $season = ig_halloween_season($date);
    $zf = null; $zph = null; $zwind = 0.0; $bloodRuns = [];
    $zc = 0;
    if ($anim !== null && $season) { $zf = $anim['frame'] % IG_ANIM_FRAMES; $zph = $zf / IG_ANIM_FRAMES; $zwind = ig_zine_wind($zf); $zc = intdiv($anim['frame'], IG_ANIM_FRAMES) % 3; }
    $y = 180;
    if ($season) {
        ig_zine_ransom($im, $margin, $y - 6, 'TODAY IN AUSTIN', 30, 21, $zf, 1.0 + 1.4 * $zwind);
        $bloodRuns = ig_zine_blood($im, 0, $w, 20, 5, 700, 38, 78, $zph, $zwind);
    } else {
        imagettftext($im, 28, 0, $margin, $y, $pink, IG_FONT_BODY, strtoupper('Today in Austin'));
    }
    $y += 66;
    imagettftext($im, 50, 0, $margin, $y, $ink, IG_FONT_ZINE_TITLE, date('l, F j', $date));
    $y += 50;

    ig_torn_line($im, $margin, $w - $margin, $y, $divider);
    $y += 40;

    $geo   = ig_list_row_geometry(min(count($films), 10));
    $thumbW = $geo['thumbW'];
    $thumbH = $geo['thumbH'];
    $textX  = $margin + $thumbW + 28;
    $textMaxWidth = $w - $margin - $textX;

    $rowHeight = $geo['rowHeight'];
    $footerY   = $h - 60;
    $rows      = array_slice($films, 0, 10);
    $extra     = count($films) - count($rows);

    if (empty($films)) {
        imagettftext($im, 28, 0, $margin, $y, $muted, IG_FONT_BODY, 'Nothing scraped for today — check back later.');
    }

    $lastIndex = count($rows) - 1;
    foreach ($rows as $i => $film) {
        if ($i % 2 === 1) {
            imagefilledrectangle($im, $margin, $y - 15, $w - $margin, $y + $rowHeight - 15, $stripe);
        }

        $thumb = ig_fetch_thumb($film['poster'], $thumbW, $thumbH);
        if ($thumb) {
            imagecopy($im, $thumb, $margin, $y, 0, 0, $thumbW, $thumbH);
            imagedestroy($thumb);
        } else {
            imagefilledrectangle($im, $margin, $y, $margin + $thumbW, $y + $thumbH, $placeholder);
            $initial = mb_strtoupper(mb_substr($film['title'], 0, 1));
            $initSize = max(20, (int) round($geo['titleSize'] * 1.1));
            $ibox = imagettfbbox($initSize, 0, IG_FONT_ZINE_TITLE, $initial);
            $iw = $ibox[2] - $ibox[0];
            imagettftext($im, $initSize, 0, (int) ($margin + ($thumbW - $iw) / 2), $y + (int) ($thumbH / 2) + (int) round($initSize / 3), $muted, IG_FONT_ZINE_TITLE, $initial);
        }

        $title = ig_fit_text(mb_strtoupper($film['title']), IG_FONT_ZINE_TITLE, $geo['titleSize'], $textMaxWidth);
        imagettftext($im, $geo['titleSize'], 0, $textX, $y + $geo['titleOffsetY'], $ink, IG_FONT_ZINE_TITLE, $title);

        // Flick Clique names the monthly series, not a place — same reasoning
        // as the website's own poster card (list/index.php): the location is
        // what's actually useful on a compact list row, the series name isn't.
        // Every other venue with a location (Alamo, opted in per day) still
        // needs both to disambiguate which of five it is.
        $venue = $film['venue'] === 'Flick Clique' && $film['location']
            ? $film['location']
            : ($film['location'] ? "{$film['venue']} — {$film['location']}" : $film['venue']);
        if ($film['director']) $venue .= '  ·  dir. ' . $film['director'];
        $meta  = ig_fit_text($venue, IG_FONT_BODY, $geo['metaSize'], $textMaxWidth);
        imagettftext($im, $geo['metaSize'], 0, $textX, $y + $geo['metaOffsetY'], $muted, IG_FONT_BODY, $meta);

        $time = ig_fit_text(ig_format_times($film['timestamps'] ?? [$film['timestamp']]), IG_FONT_BODY, $geo['metaSize'], $textMaxWidth);
        imagettftext($im, $geo['metaSize'], 0, $textX, $y + $geo['timeOffsetY'], $pink, IG_FONT_BODY, $time);

        $y += $rowHeight;
        if ($i < $lastIndex) {
            ig_torn_line($im, $margin, $w - $margin, $y - 15, $divider);
        }
    }

    $totalMore = $extra + $moreCount;
    if ($totalMore > 0) {
        $label = "+{$totalMore} MORE";
        $midY  = $y + (int) round(($footerY - 30 - $y) / 2);
        $textY = $midY + 8;
        imagettftext($im, 28, 0, $margin, $textY, $pink, IG_FONT_BODY, $label);
        if ($moreCount > 0) {
            $box = imagettfbbox(28, 0, IG_FONT_BODY, $label);
            $tw  = $box[2] - $box[0];
            $ax  = $margin + $tw + 22;
            ig_draw_arrow($im, $ax, $textY - 12, $ax + 34, $pink);
        }
    }

    imagettftext($im, 22, 0, $margin, $footerY, $muted, IG_FONT_BODY, 'Full schedule at cinematx.net');

    // October: the xerox skull, taped down in the bottom-right corner (small
    // enough to clear the last row of a ten-film page).
    if ($season) {
        if ($zf === null) ig_zine_skull_card($im, 910, 1252, 0.66, 5.0);
        else {
            ig_zine_flier_anim($im, 910, 1252, 0.66, $zf, $zc);
            ig_zine_whoosh($im, $zf);
            ig_zine_blood_drops($im, $bloodRuns, 20, $zph, 820, $zwind);
        }
    }

    return $im;
}

// A real newspaper listings page: gray newsprint stock, black ink, red used
// once and sparingly (showtimes only) rather than as a running accent.
// Posters run grayscale, no color wash — actual newsprint photo
// reproduction, not a Riso tint. The wordmark is a masthead nameplate with
// a thick/thin double rule beneath it, the way a real paper's name sits
// over its own folio rule, rather than a chip or a stamp. Labels are flat
// rectangles, not rounded pills — newsprint has no rounded corners anywhere.
function ig_build_list_page_newsprint(array $films, $date, $moreCount = 0, $anim = null) {
    $w = 1080;
    $h = 1350;
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);

    $paper       = ig_hex($im, '#E8E4D5');
    $stripe      = ig_hex($im, '#DFDBCC');
    $red         = ig_hex($im, '#922E32');
    $ink         = ig_hex($im, '#1C1B19');
    $muted       = ig_hex($im, '#6B675C');
    $rule        = ig_hex($im, '#B8B2A0');
    $placeholder = ig_hex($im, '#D9D4C3');

    imagefill($im, 0, 0, $paper);

    $margin = 80;

    // The wordmark is a small masthead line sitting on the double rule; the
    // day's headline below it, "Today in Austin", is the loud thing on the page.
    imagettftext($im, 24, 0, $margin, 112, $ink, IG_FONT_NEWSPRINT_TITLE, 'CINEMA, TX');
    imagefilledrectangle($im, $margin, 126, $w - $margin, 130, $ink);
    imagefilledrectangle($im, $margin, 136, $w - $margin, 137, $ink);

    // October: a halftone moon in the header's empty right side with two
    // engraved clouds by it (one across its lower right, a small one at its
    // upper left), and three bats over it. Animated, the clouds drift slowly
    // to and fro and the bats fly across.
    $season = ig_halloween_season($date);
    $moonCx = 905; $moonCy = 201; $moonR = 56;
    if ($season) {
        ig_halftone_moon($im, $moonCx, $moonCy, $moonR, $ink);
        $ph = $anim !== null ? 2 * M_PI * $anim['frame'] / $anim['frames'] : 0.0;
        ig_news_cloud($im, 948 + 14 * sin($ph), 224 + 2 * sin(2 * $ph), 1.0, $ink, $paper);
        ig_news_cloud($im, 818 - 8 * sin($ph + 1.2), 158 + 2 * sin(2 * $ph + 0.7), 0.6, $ink, $paper);
    }

    $y = 188;
    imagettftext($im, 36, 0, $margin, $y, $red, IG_FONT_BODY, strtoupper('Today in Austin'));
    $y += 40;
    imagettftext($im, 30, 0, $margin, $y, $ink, IG_FONT_BODY, date('l, F j', $date));
    $y += 34;
    imagefilledrectangle($im, $margin, $y, $w - $margin, $y + 2, $ink);
    $y += 38;

    $geo   = ig_list_row_geometry(min(count($films), 10));
    $thumbW = $geo['thumbW'];
    $thumbH = $geo['thumbH'];
    $textX  = $margin + $thumbW + 28;
    $textMaxWidth = $w - $margin - $textX;

    $rowHeight = $geo['rowHeight'];
    $footerY   = $h - 60;
    $rows      = array_slice($films, 0, 10);
    $extra     = count($films) - count($rows);

    if (empty($films)) {
        imagettftext($im, 28, 0, $margin, $y, $muted, IG_FONT_BODY, 'Nothing scraped for today — check back later.');
    }

    $lastIndex = count($rows) - 1;
    foreach ($rows as $i => $film) {
        // Zebra banding, same quiet trick as Neon's list page — every other
        // row gets a faintly darker strip behind it.
        if ($i % 2 === 1) {
            imagefilledrectangle($im, $margin, $y - 15, $w - $margin, $y + $rowHeight - 15, $stripe);
        }

        $thumb = ig_fetch_thumb($film['poster'], $thumbW, $thumbH);
        if ($thumb) {
            imagecopy($im, $thumb, $margin, $y, 0, 0, $thumbW, $thumbH);
            imagedestroy($thumb);
        } else {
            imagefilledrectangle($im, $margin, $y, $margin + $thumbW, $y + $thumbH, $placeholder);
            $initial = mb_strtoupper(mb_substr($film['title'], 0, 1));
            $initSize = max(20, (int) round($geo['titleSize'] * 1.1));
            $ibox = imagettfbbox($initSize, 0, IG_FONT_NEWSPRINT_TITLE, $initial);
            $iw = $ibox[2] - $ibox[0];
            imagettftext($im, $initSize, 0, (int) ($margin + ($thumbW - $iw) / 2), $y + (int) ($thumbH / 2) + (int) round($initSize / 3), $muted, IG_FONT_NEWSPRINT_TITLE, $initial);
        }

        $title = ig_fit_text(mb_strtoupper($film['title']), IG_FONT_NEWSPRINT_TITLE, $geo['titleSize'], $textMaxWidth);
        imagettftext($im, $geo['titleSize'], 0, $textX, $y + $geo['titleOffsetY'], $ink, IG_FONT_NEWSPRINT_TITLE, $title);

        // Flick Clique names the monthly series, not a place — same reasoning
        // as the website's own poster card (list/index.php): the location is
        // what's actually useful on a compact list row, the series name isn't.
        // Every other venue with a location (Alamo, opted in per day) still
        // needs both to disambiguate which of five it is.
        $venue = $film['venue'] === 'Flick Clique' && $film['location']
            ? $film['location']
            : ($film['location'] ? "{$film['venue']} — {$film['location']}" : $film['venue']);
        if ($film['director']) $venue .= '  ·  dir. ' . $film['director'];
        $meta  = ig_fit_text($venue, IG_FONT_BODY, $geo['metaSize'], $textMaxWidth);
        imagettftext($im, $geo['metaSize'], 0, $textX, $y + $geo['metaOffsetY'], $muted, IG_FONT_BODY, $meta);

        $time = ig_fit_text(ig_format_times($film['timestamps'] ?? [$film['timestamp']]), IG_FONT_BODY, $geo['metaSize'], $textMaxWidth);
        imagettftext($im, $geo['metaSize'], 0, $textX, $y + $geo['timeOffsetY'], $red, IG_FONT_BODY, $time);

        $y += $rowHeight;
        if ($i < $lastIndex) {
            imagefilledrectangle($im, $margin, $y - 15, $w - $margin, $y - 14, $rule);
        }
    }

    $totalMore = $extra + $moreCount;
    if ($totalMore > 0) {
        $label = "+{$totalMore} MORE";
        $midY  = $y + (int) round(($footerY - 30 - $y) / 2);
        $textY = $midY + 8;
        imagettftext($im, 28, 0, $margin, $textY, $red, IG_FONT_BODY, $label);
        if ($moreCount > 0) {
            $box = imagettfbbox(28, 0, IG_FONT_BODY, $label);
            $tw  = $box[2] - $box[0];
            $ax  = $margin + $tw + 22;
            ig_draw_arrow($im, $ax, $textY - 12, $ax + 34, $red);
        }
    }

    imagettftext($im, 22, 0, $margin, $footerY, $muted, IG_FONT_BODY, 'Full schedule at cinematx.net');

    if ($season) {
        ig_news_classifieds($im, 640, 1196, $w - $margin, 1290, $ink, $red, $paper, ig_news_ads(), $anim);

        // The bats: three of them, over the moon in the still. Animated, the
        // three fly together, once per cycle (three flights a video), drawn last
        // so they sweep over the screenings. They come up from low on the page —
        // in from the left edge or the bottom, a different place each flight —
        // on a curving path across the rows, over the middle of the moon and
        // away off the right side of the card. Each follows the same path a
        // little behind the last, at its own size, offset and wing beat. They
        // are all off the page at the wrap, so the next flight starts with
        // nothing to explain.
        $flock = [[$moonCx - 33, $moonCy - 20, 42, 0.0], [$moonCx + 60, $moonCy - 38, 28, 0.45], [$moonCx - 72, $moonCy + 24, 30, -0.35]];
        if ($anim !== null) {
            $per = (int) ($anim['frames'] / 3);                      // three flights per video (see ig_anim_frames())
            $fl  = intdiv($anim['frame'], $per) % 3;
            $t   = ($anim['frame'] % $per) / $per;
            [$sx, $sy] = [[-80, 1250], [260, 1430], [-80, 760]][$fl];
            // Waypoints: the start, a belly out to the lower right, a point
            // short of the moon at a shallow angle, the middle of the moon, and
            // out of the right side below the forecast box.
            $wx = $moonCx - 150; $wy = $moonCy + 125;
            $dx = $wx - $sx; $dy = $wy - $sy; $L = hypot($dx, $dy);
            $path = [[$sx, $sy], [($sx + $wx) / 2 - $dy / $L * 110, ($sy + $wy) / 2 + $dx / $L * 110], [$wx, $wy], [$moonCx, $moonCy + 6], [$moonCx + 110, $moonCy - 8], [1240, $moonCy - 28]];
            $flock = [];
            foreach ([[0.0, 0, 0, 44, 0.0], [0.06, -34, 26, 38, 1.9], [0.11, 30, 38, 34, 3.7]] as [$dl, $ox, $oy, $sz, $ph]) {
                $u = ($t - $dl) / 0.84;
                if ($u <= 0 || $u >= 1) continue;
                [$bx, $by, $nx, $ny] = ig_path_point($path, $u);
                $wob = 12 * sin(2 * M_PI * 6 * $u + $ph);
                $flock[] = [$bx + $nx * $wob + $ox, $by + $ny * $wob + $oy, $sz, 0.7 * sin(2 * M_PI * (22 * $t + $ph))];
            }
        }
        foreach ($flock as [$bx, $by, $bs, $flap]) ig_bat_silhouette($im, $bx, $by, $bs, $ink, $flap);
    }

    return $im;
}

// A video-store rental card at closing time: deep purple-navy, dual neon
// accents (cyan for identity/titles, pink for the wordmark and call-outs),
// posters left in full color rather than tinted — unlike Zine/Newsprint,
// this is the "watching it on a CRT" theme, not a print-reproduction one.
// The wordmark is glowing letters with no box around them (a real neon
// sign has no chip), every page sits inside a glowing border frame like
// Marquee's bulb border, and scanlines are the final pass over everything.
function ig_build_list_page_neon(array $films, $date, $moreCount = 0, $anim = null) {
    $w = 1080;
    $h = 1350;
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);

    $bg          = ig_hex($im, '#150829');
    $stripe      = ig_hex($im, '#1A0E2E');
    $cyan        = ig_hex($im, '#2DE2E6');
    $pink        = ig_hex($im, '#FF2E9A');
    $ink         = ig_hex($im, '#F5F0FF');
    $muted       = ig_hex($im, '#8B7FA8');
    $placeholder = ig_hex($im, '#241243');

    imagefill($im, 0, 0, $bg);

    $cyanGlow = imagecolorallocatealpha($im, 0x2D, 0xE2, 0xE6, 105);
    ig_neon_border($im, 24, 24, $w - 24, $h - 24, $cyanGlow, $cyan);

    $margin = 80;

    $pinkGlow = imagecolorallocatealpha($im, 0xFF, 0x2E, 0x9A, 100);
    $season = ig_halloween_season($date);

    // Animated (October only): the E sputters back to life in three bursts —
    // each burst a few frames of it flaring up, the M beside it brightening and
    // sparks jumping — while the skull's eyes black out in sympathy, as if the
    // sign were drawing the power. Frames are 12 fps, so each flicker is a
    // twelfth of a second. Outside the bursts the E is dead, as in the still.
    $titleSt = null; $skullSt = null; $recOn = true;
    if ($anim !== null && $season) {
        // Everything but the ghost repeats every IG_ANIM_FRAMES-frame cycle;
        // the video may be several cycles long (see ig_anim_frames()).
        $f = $anim['frame'] % IG_ANIM_FRAMES; $t = $f / IG_ANIM_FRAMES;
        $flash = [14 => 1.0, 16 => 1.0, 19 => 0.8, 52 => 1.0, 53 => 1.0, 55 => 0.8, 58 => 1.0, 80 => 1.0, 82 => 0.7];
        $lit   = $flash[$f] ?? null;
        $after = isset($flash[$f - 1]) && $lit === null;
        $titleSt = [
            'e'     => $lit ?? ($after ? 0.3 : 0.0),
            'm'     => $lit !== null ? 0.95 : 0.54 + 0.10 * sin(2 * M_PI * 3 * $t),
            'spark' => $lit !== null ? $lit : ($after ? 0.5 : 0.0),
            'seed'  => $f,
        ];
        $dark = $lit !== null || $after || in_array($f, [33, 34, 71], true);
        $skullSt = [
            'outline' => $lit !== null ? 0.6 : 0.93 + 0.07 * sin(2 * M_PI * 5 * $t),
            'eyes'    => $dark ? 0.1 : 0.7 + 0.3 * (0.5 + 0.5 * sin(2 * M_PI * 2 * $t - M_PI / 2)),
        ];
        // The REC light blinks: on for just over half of each two seconds.
        $recOn = fmod($t * 4, 1.0) < 0.55;
    }
    if ($season) ig_neon_dying_title($im, 34, $margin, 108, IG_FONT_NEON_TITLE, $pink, $pinkGlow, $titleSt);
    else         ig_neon_text($im, 34, $margin, 108, IG_FONT_NEON_TITLE, 'CINEMA, TX', $pink, $pinkGlow);

    // A recording-light dot — the one place this theme borrows red, since
    // nothing else reads "REC" like it does.
    if ($recOn) imagefilledellipse($im, $w - $margin - 58, 60, 12, 12, ig_hex($im, '#FF3355'));
    imagettftext($im, 20, 0, $w - $margin - 42, 66, $ink, IG_FONT_BODY, 'REC');

    // October: a neon skull in the header's empty right-hand side.
    if ($season) ig_neon_skull($im, $w - $margin - 95, 178, 68, $skullSt);

    $y = 175;
    imagettftext($im, 24, 0, $margin, $y, $cyan, IG_FONT_BODY, strtoupper('Today in Austin'));
    $y += 50;
    imagettftext($im, 40, 0, $margin, $y, $ink, IG_FONT_BODY, date('l, F j', $date));
    $y += 36;

    imagesetthickness($im, 3);
    imageline($im, $margin, $y, $w - $margin, $y, $cyanGlow);
    imagesetthickness($im, 1);
    imagefilledrectangle($im, $margin, $y, $w - $margin, $y + 1, $cyan);
    $y += 40;

    $geo   = ig_list_row_geometry(min(count($films), 10));
    $thumbW = $geo['thumbW'];
    $thumbH = $geo['thumbH'];
    $textX  = $margin + $thumbW + 28;
    $textMaxWidth = $w - $margin - $textX;

    $rowHeight = $geo['rowHeight'];
    $footerY   = $h - 60;
    $rows      = array_slice($films, 0, 10);
    $extra     = count($films) - count($rows);

    if (empty($films)) {
        imagettftext($im, 28, 0, $margin, $y, $muted, IG_FONT_BODY, 'Nothing scraped for today — check back later.');
    }

    foreach ($rows as $i => $film) {
        // Zebra banding — every other row gets a faintly lighter strip
        // behind it, the same trick a spreadsheet uses to keep a dense list
        // readable. No separate rule line between rows any more; the bands
        // themselves are the divider.
        if ($i % 2 === 1) {
            imagefilledrectangle($im, $margin, $y - 15, $w - $margin, $y + $rowHeight - 15, $stripe);
        }

        $thumb = ig_fetch_thumb($film['poster'], $thumbW, $thumbH);
        if ($thumb) {
            imagecopy($im, $thumb, $margin, $y, 0, 0, $thumbW, $thumbH);
            imagedestroy($thumb);
            imagerectangle($im, $margin, $y, $margin + $thumbW, $y + $thumbH, $cyan);
        } else {
            imagefilledrectangle($im, $margin, $y, $margin + $thumbW, $y + $thumbH, $placeholder);
            $initial = mb_strtoupper(mb_substr($film['title'], 0, 1));
            $initSize = max(18, (int) round($geo['titleSize'] * 0.9));
            $ibox = imagettfbbox($initSize, 0, IG_FONT_NEON_TITLE, $initial);
            $iw = $ibox[2] - $ibox[0];
            imagettftext($im, $initSize, 0, (int) ($margin + ($thumbW - $iw) / 2), $y + (int) ($thumbH / 2) + (int) round($initSize / 3), $muted, IG_FONT_NEON_TITLE, $initial);
        }

        // Space for the "w/ Org" tag is reserved before the title is fit,
        // not squeezed in after — otherwise a long title would always fill
        // the row and the tag would silently never have room to appear.
        $presentedWith = ig_presented_with($film['billing'] ?? null);
        $tagText = null;
        $tagW = 0;
        $tagSize = max(14, (int) round($geo['metaSize'] * 0.85));
        if ($presentedWith) {
            $tagText = ig_fit_text($presentedWith, IG_FONT_BODY, $tagSize, 200);
            $tagBox  = imagettfbbox($tagSize, 0, IG_FONT_BODY, $tagText);
            $tagW    = $tagBox[2] - $tagBox[0];
        }

        $title = ig_fit_text(mb_strtoupper($film['title']), IG_FONT_NEON_TITLE, $geo['titleSize'], $textMaxWidth - ($tagText ? $tagW + 14 : 0));
        ig_neon_text($im, $geo['titleSize'], $textX, $y + $geo['titleOffsetY'], IG_FONT_NEON_TITLE, $title, $cyan, $cyanGlow);

        if ($tagText) {
            $titleBox = imagettfbbox($geo['titleSize'], 0, IG_FONT_NEON_TITLE, $title);
            $titleW   = $titleBox[2] - $titleBox[0];
            imagettftext($im, $tagSize, 0, $textX + $titleW + 14, $y + $geo['titleOffsetY'], $muted, IG_FONT_BODY, $tagText);
        }

        // Flick Clique names the monthly series, not a place — same reasoning
        // as the website's own poster card (list/index.php): the location is
        // what's actually useful on a compact list row, the series name isn't.
        // Every other venue with a location (Alamo, opted in per day) still
        // needs both to disambiguate which of five it is.
        $venue = $film['venue'] === 'Flick Clique' && $film['location']
            ? $film['location']
            : ($film['location'] ? "{$film['venue']} — {$film['location']}" : $film['venue']);
        if ($film['director']) $venue .= '  ·  dir. ' . $film['director'];
        $meta  = ig_fit_text($venue, IG_FONT_BODY, $geo['metaSize'], $textMaxWidth);
        imagettftext($im, $geo['metaSize'], 0, $textX, $y + $geo['metaOffsetY'], $muted, IG_FONT_BODY, $meta);

        $time = ig_fit_text(ig_format_times($film['timestamps'] ?? [$film['timestamp']]), IG_FONT_BODY, $geo['metaSize'], $textMaxWidth);
        imagettftext($im, $geo['metaSize'], 0, $textX, $y + $geo['timeOffsetY'], $pink, IG_FONT_BODY, $time);

        $y += $rowHeight;
    }

    $totalMore = $extra + $moreCount;
    if ($totalMore > 0) {
        $label = "+{$totalMore} MORE";
        $midY  = $y + (int) round(($footerY - 30 - $y) / 2);
        $textY = $midY + 8;
        imagettftext($im, 28, 0, $margin, $textY, $pink, IG_FONT_BODY, $label);
        if ($moreCount > 0) {
            $box = imagettfbbox(28, 0, IG_FONT_BODY, $label);
            $tw  = $box[2] - $box[0];
            $ax  = $margin + $tw + 22;
            ig_draw_arrow($im, $ax, $textY - 12, $ax + 34, $pink);
        }
    }

    imagettftext($im, 22, 0, $margin, $footerY, $muted, IG_FONT_BODY, 'Full schedule at cinematx.net');

    // October: a graveyard along the ground, right of the footer line.
    if ($season) ig_neon_tombstones($im, $w - $margin - 340, $w - $margin, $h - 40, $anim);

    ig_scanlines($im, $w, $h, imagecolorallocatealpha($im, 0, 0, 0, 112));

    return $im;
}

// A split-flap departure board: monospaced font, four literal columns —
// TIME / TITLE / VENUE / STATUS — a dim LED-dot bezel instead of a themed
// border, and (unlike a real board) a poster thumbnail riding along in the
// TITLE column, the same thumbnail size every other theme uses, for some
// visual consistency across the picker. STATUS is decorative flavor text
// rather than real operational data (nothing here tracks delays), the same
// license the other themes take with a REC dot or a torn-paper edge —
// every row just reads "ON TIME" in green.
function ig_build_list_page_terminal(array $films, $date, $moreCount = 0) {
    $w = 1080;
    $h = 1350;
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);

    $bg          = ig_hex($im, '#0B0C0E');
    $ink         = ig_hex($im, '#EDEBE2');
    $muted       = ig_hex($im, '#6B6E73');
    $dim         = ig_hex($im, '#3A3D42');
    $divider     = ig_hex($im, '#26282C');
    $placeholder = ig_hex($im, '#1B1D20');
    $green       = ig_hex($im, '#5FD68C');
    $stripe      = ig_hex($im, '#111316');

    imagefill($im, 0, 0, $bg);
    ig_led_border($im, 24, 24, $w - 24, $h - 24, 26, $dim);

    $margin = 80;

    ig_tracked_text($im, 20, $margin, 110, IG_FONT_TERMINAL, 'CINEMA, TX', $muted, 8);
    ig_tracked_text($im, 40, $margin, 168, IG_FONT_TERMINAL, 'SHOWTIMES', $ink, 6);

    $y = 216;
    imagettftext($im, 24, 0, $margin, $y, $muted, IG_FONT_TERMINAL, strtoupper(date('l, F j', $date)));
    $y += 34;

    imagefilledrectangle($im, $margin, $y, $w - $margin, $y + 1, $divider);
    $y += 44;

    // Column x-positions — the header row and every data row share these,
    // so the whole page reads as one aligned table. TITLE's column carries
    // the poster thumbnail too; TIME/VENUE/STATUS stay text-only.
    $geo    = ig_list_row_geometry(min(count($films), 10));
    $thumbW = $geo['thumbW'];
    $thumbH = $geo['thumbH'];

    $colTime   = $margin;
    $colPoster = $margin + 120;
    $colTitle  = $colPoster + $thumbW + 20;
    $colVenue  = $colTitle + 270 + 20;
    $colStatus = $colVenue + 230 + 20;

    ig_tracked_text($im, 16, $colTime,   $y, IG_FONT_TERMINAL, 'TIME',   $dim, 4);
    ig_tracked_text($im, 16, $colTitle,  $y, IG_FONT_TERMINAL, 'TITLE',  $dim, 4);
    ig_tracked_text($im, 16, $colVenue,  $y, IG_FONT_TERMINAL, 'VENUE',  $dim, 4);
    ig_tracked_text($im, 16, $colStatus, $y, IG_FONT_TERMINAL, 'STATUS', $dim, 4);
    $y += 20;

    imagesetthickness($im, 2);
    imageline($im, $margin, $y, $w - $margin, $y, $divider);
    imagesetthickness($im, 1);
    $y += 30;

    $rowHeight = $geo['rowHeight'];
    $footerY   = $h - 60;
    $rows      = array_slice($films, 0, 10);
    $extra     = count($films) - count($rows);

    if (empty($films)) {
        imagettftext($im, 24, 0, $margin, $y + 40, $muted, IG_FONT_TERMINAL, 'NOTHING SCRAPED FOR TODAY — CHECK BACK LATER.');
    }

    // Terminal's columns are fixed-width (a table, not a thumbnail+text
    // block), much narrower than the text column every other theme gets —
    // so it can't just borrow the shared tier's title/meta sizes, which are
    // tuned for a nearly-full-width text block and would overrun these
    // columns and truncate. Its own font scale, indexed off the same tier,
    // stays modest enough to fit TIME (100px)/TITLE (270px)/VENUE (230px).
    static $terminalFonts = [
        1 => ['title' => 26, 'meta' => 18, 'venue' => 15],
        2 => ['title' => 22, 'meta' => 16, 'venue' => 13],
        3 => ['title' => 19, 'meta' => 15, 'venue' => 12],
        4 => ['title' => 17, 'meta' => 13, 'venue' => 11],
    ];
    $tf = $terminalFonts[$geo['tier']];
    $titleSize  = $tf['title'];
    $timeSize   = $tf['meta'];
    $venueSize  = $tf['venue'];
    $statusSize = $tf['meta'];

    $timeMaxWidth   = $colPoster - $colTime - 20;
    $titleMaxWidth  = $colVenue - $colTitle - 20;
    $venueMaxWidth  = $colStatus - $colVenue - 20;
    $statusMaxWidth = ($w - $margin) - $colStatus;

    $lastIndex = count($rows) - 1;
    foreach ($rows as $i => $film) {
        // Zebra banding, same technique as every other theme — a strip a
        // couple RGB points off the background, behind every other row.
        if ($i % 2 === 1) {
            imagefilledrectangle($im, $margin, $y - 15, $w - $margin, $y + $rowHeight - 15, $stripe);
        }

        $textY = $y + (int) round($thumbH / 2) + 8;

        $time = ig_fit_text(implode('/', array_map(fn($t) => date('g:iA', $t), $film['timestamps'] ?? [$film['timestamp']])), IG_FONT_TERMINAL, $timeSize, $timeMaxWidth);
        imagettftext($im, $timeSize, 0, $colTime, $textY, $ink, IG_FONT_TERMINAL, $time);

        $thumb = ig_fetch_thumb($film['poster'], $thumbW, $thumbH);
        if ($thumb) {
            imagecopy($im, $thumb, $colPoster, $y, 0, 0, $thumbW, $thumbH);
            imagedestroy($thumb);
            imagerectangle($im, $colPoster, $y, $colPoster + $thumbW, $y + $thumbH, $dim);
        } else {
            imagefilledrectangle($im, $colPoster, $y, $colPoster + $thumbW, $y + $thumbH, $placeholder);
            $initial = mb_strtoupper(mb_substr($film['title'], 0, 1));
            $initSize = max(18, (int) round($titleSize * 0.85));
            $ibox = imagettfbbox($initSize, 0, IG_FONT_TERMINAL, $initial);
            $iw = $ibox[2] - $ibox[0];
            imagettftext($im, $initSize, 0, (int) ($colPoster + ($thumbW - $iw) / 2), $y + (int) ($thumbH / 2) + (int) round($initSize / 3), $muted, IG_FONT_TERMINAL, $initial);
        }
        $title = ig_fit_text(mb_strtoupper($film['title']), IG_FONT_TERMINAL, $titleSize, $titleMaxWidth);
        imagettftext($im, $titleSize, 0, $colTitle, $textY, $ink, IG_FONT_TERMINAL, $title);

        $venue = ig_fit_text(mb_strtoupper($film['venue']), IG_FONT_TERMINAL, $venueSize, $venueMaxWidth);
        imagettftext($im, $venueSize, 0, $colVenue, $textY, $muted, IG_FONT_TERMINAL, $venue);

        imagettftext($im, $statusSize, 0, $colStatus, $textY, $green, IG_FONT_TERMINAL, ig_fit_text('ON TIME', IG_FONT_TERMINAL, $statusSize, $statusMaxWidth));

        $y += $rowHeight;
        if ($i < $lastIndex) {
            imagefilledrectangle($im, $margin, $y - 15, $w - $margin, $y - 14, $divider);
        }
    }

    $totalMore = $extra + $moreCount;
    if ($totalMore > 0) {
        $label = "+{$totalMore} MORE";
        $midY  = $y + (int) round(($footerY - 30 - $y) / 2);
        $textY = $midY + 8;
        imagettftext($im, 26, 0, $margin, $textY, $ink, IG_FONT_TERMINAL, $label);
        if ($moreCount > 0) {
            $box = imagettfbbox(26, 0, IG_FONT_TERMINAL, $label);
            $tw  = $box[2] - $box[0];
            $ax  = $margin + $tw + 22;
            ig_draw_arrow($im, $ax, $textY - 12, $ax + 34, $ink);
        }
    }

    ig_tracked_text($im, 18, $margin, $footerY, IG_FONT_TERMINAL, 'FULL SCHEDULE AT CINEMATX.NET', $muted, 3);

    return $im;
}

// A darkroom at safelight — near-black and warm rather than neon's cool
// purple-black, sprocket-hole filmstrip edges instead of a bordering frame,
// a stencilled title face like a film can's own lettering, and everything
// in one warm red/amber/cream family rather than a multi-hue palette: a
// real safelight only ever shows one color — but posters stay untinted, full
// color, like Neon's (unlike Zine's pink duotone or Newsprint's grayscale),
// since a printed still isn't what's under the safelight, the film is.
function ig_build_list_page_darkroom(array $films, $date, $moreCount = 0, $anim = null) {
    $w = 1080;
    $h = 1350;
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);

    $bg          = ig_hex($im, '#120D0A');
    $strip       = ig_hex($im, '#1D1611');
    $ink         = ig_hex($im, '#F0E6D8');
    $amber       = ig_hex($im, '#E08A3E');
    $rust        = ig_hex($im, '#B33B1E');
    $muted       = ig_hex($im, '#8A7D6E');
    $placeholder = ig_hex($im, '#241C15');
    $stripe      = ig_hex($im, '#191209');

    imagefill($im, 0, 0, $bg);
    ig_sprocket_edge($im, 0, $h, 56, $strip, $bg);
    ig_sprocket_edge($im, $w - 56, $h, 56, $strip, $bg);

    $margin = 100;

    imagettftext($im, 28, 0, $margin, 110, $ink, IG_FONT_DARKROOM_TITLE, 'CINEMA, TX');
    $stampBox = imagettfbbox(28, 0, IG_FONT_DARKROOM_TITLE, 'CINEMA, TX');
    imagefilledrectangle($im, $margin, 122, $margin + ($stampBox[2] - $stampBox[0]), 124, $amber);

    ig_leader_mark($im, $w - $margin - 30, 70, 28, $amber);

    // October: spirit photography. Prints pile up in the header's empty right
    // side, each landing on the last at its own angle, and slit-eyed pairs watch
    // from a few of the film edges' sprocket holes. Animated (four 8s cycles,
    // one print each): a white flash, the new print dropping onto the pile and
    // coming up in the developer; each pair of eyes opens out of the dark for a
    // stretch, blinks and looks about; and the loop of film in the footer —
    // 4-3-2-1, then a ghost coming down a hall — goes once around every two
    // prints, its speed building and easing off, with no relation to the flashes.
    // (The leader mark stays still.) The video ends on the finished pile of
    // four, which the first flash clears.
    $season = ig_halloween_season($date);
    $eyeSt = []; $reelP = 6.0; $flash = 0.0;
    $eyeSpots = [[28, 224, 6, 40, 22], [28, 608, 28, 70, 50], [28, 992, 52, 90, 74], [1052, 416, 14, 56, 38], [1052, 864, 40, 84, 62]];
    foreach ($eyeSpots as $k => $_) $eyeSt[$k] = ['level' => 1.0, 'open' => 1.0, 'look' => 0];
    // The four pictures, in the order they are printed — each a famous picture
    // with an October character in the lead — and where each one lands on the
    // pile: [dx, dy, degrees].
    $pics = [['scream', 'booooo'], ['sonofman', 'son of pumpkin'], ['psycho', 'vacancy'], ['gothic', 'american ghoulish']];
    $pile = [[-5, 7, -9], [6, -3, 5], [-3, -7, -4], [5, 4, 7]];
    // What is on the pile, bottom to top: [picture, develop, milk, lift, tilt].
    // The still shows all four, finished.
    $stack = [[0, 1.0, 0.0, 0, 0], [1, 1.0, 0.0, 0, 0], [2, 1.0, 0.0, 0, 0], [3, 1.0, 0.0, 0, 0]];
    if ($anim !== null && $season) {
        $f = $anim['frame'] % IG_ANIM_FRAMES; $t = $f / IG_ANIM_FRAMES;
        $cyc = intdiv($anim['frame'], IG_ANIM_FRAMES) % 4;
        $flash = $f === 5 ? 1.0 : ($f === 4 ? 0.4 : ($f === 6 ? 0.5 : 0.0));
        // Until the flash at frame 5 the pile is what the last cycle left —
        // and at the start of the video that is all four finished prints, so
        // the wrap does not pop. At the flash the new print drops onto the pile
        // (the first one of the video replaces the whole pile, behind the
        // glare), a little above and askew and settling over four frames.
        $stack = [];
        if ($f < 5 && $cyc === 0) $stack = [[0, 1.0, 0.0, 0, 0], [1, 1.0, 0.0, 0, 0], [2, 1.0, 0.0, 0, 0], [3, 1.0, 0.0, 0, 0]];
        else for ($i = 0; $i < $cyc; $i++) $stack[] = [$i, 1.0, 0.0, 0, 0];
        // The flash glares across the whole pile as it goes off, fading.
        $glare = $f === 4 ? 0.55 : ($f === 5 ? 0.35 : ($f === 6 ? 0.15 : 0.0));
        foreach ($stack as &$pr) $pr[2] = $glare;
        unset($pr);
        if ($f >= 5) {
            // The print itself, as an instant photo develops: a milky cloud
            // for a second, then the cloud clears and the picture comes up
            // through it, outlines first.
            $milk = $f === 5 ? 1.0 : ($f < 20 ? 0.92 : ($f <= 60 ? 0.92 * (1 - ig_ease(($f - 20) / 40)) : 0.0));
            $dv   = $f < 26 ? 0.0 : ($f <= 60 ? ig_ease(($f - 26) / 34) : 1.0);
            $e    = ig_ease(min(1.0, ($f - 5) / 4));
            $stack[] = [$cyc, $dv, $milk, -24 * (1 - $e), 6 * (1 - $e)];
        }
        foreach ($eyeSpots as $k => [$ex, $ey, $a0, $a1, $blink]) {
            $lv = $f < $a0 || $f > $a1 ? 0.0 : min(1.0, ($f - $a0) / 4, ($a1 - $f) / 4);
            $eyeSt[$k] = ['level' => $lv, 'open' => in_array($f, [$blink, $blink + 1], true) ? 0.1 : 1.0, 'look' => (int) round(2 * sin(2 * M_PI * 2 * $t + $k * 1.3))];
        }
        // The film goes once around its eight frames every two cycles (16s),
        // slowest at the turn of the loop and quickest in the middle of the
        // go-round: speed 0.35 + 0.65 * (1 - cos 2 pi u) / 2, so there is no
        // jerk where one go-round meets the next, and since the film is a loop
        // eight frames on looks like none.
        $ur = fmod($anim['frame'], 2 * IG_ANIM_FRAMES) / (2 * IG_ANIM_FRAMES);
        $reelP = IG_REEL_FRAMES * (0.35 * $ur + 0.65 * ($ur - sin(2 * M_PI * $ur) / (2 * M_PI)));
    }
    if ($season) {
        foreach ($stack as $si => [$pi, $pdv, $pmilk, $plift, $ptilt]) {
            [$jx, $jy, $ja] = $pile[$pi];
            ig_darkroom_print($im, 840 + $jx, 165 + $jy + $plift, $ja + $ptilt, $pdv, $pmilk, $si === count($stack) - 1 ? $flash : 0.0, $pics[$pi][0], $pics[$pi][1]);
        }
        foreach ($eyeSpots as $k => [$ex, $ey]) ig_sprocket_eyes($im, $ex, $ey, $eyeSt[$k]['level'], $eyeSt[$k]['open'], $eyeSt[$k]['look']);
    }

    $y = 175;
    imagettftext($im, 24, 0, $margin, $y, $amber, IG_FONT_BODY, strtoupper("Today's Reel"));
    $y += 50;
    $amberGlow = imagecolorallocatealpha($im, 0xE0, 0x8A, 0x3E, 105);
    ig_neon_text($im, 44, $margin, $y, IG_FONT_DARKROOM_TITLE, date('l, F j', $date), $ink, $amberGlow);
    $y += 36;
    ig_dashed_line($im, $margin, $w - $margin, $y, $rust, 12, 8);
    $y += 40;

    $geo   = ig_list_row_geometry(min(count($films), 10));
    $thumbW = $geo['thumbW'];
    $thumbH = $geo['thumbH'];
    $textX  = $margin + $thumbW + 28;
    $textMaxWidth = $w - $margin - $textX;

    $rowHeight = $geo['rowHeight'];
    $footerY   = $h - 60;
    $rows      = array_slice($films, 0, 10);
    $extra     = count($films) - count($rows);

    if (empty($films)) {
        imagettftext($im, 28, 0, $margin, $y, $muted, IG_FONT_BODY, 'Nothing scraped for today — check back later.');
    }

    $lastIndex = count($rows) - 1;
    foreach ($rows as $i => $film) {
        // Zebra banding, same technique as every other theme — a strip a
        // couple RGB points off the background, behind every other row.
        if ($i % 2 === 1) {
            imagefilledrectangle($im, $margin, $y - 15, $w - $margin, $y + $rowHeight - 15, $stripe);
        }

        $thumb = ig_fetch_thumb($film['poster'], $thumbW, $thumbH);
        if ($thumb) {
            imagecopy($im, $thumb, $margin, $y, 0, 0, $thumbW, $thumbH);
            imagedestroy($thumb);
        } else {
            imagefilledrectangle($im, $margin, $y, $margin + $thumbW, $y + $thumbH, $placeholder);
            $initial = mb_strtoupper(mb_substr($film['title'], 0, 1));
            $initSize = max(20, (int) round($geo['titleSize'] * 1.2));
            $ibox = imagettfbbox($initSize, 0, IG_FONT_DARKROOM_TITLE, $initial);
            $iw = $ibox[2] - $ibox[0];
            imagettftext($im, $initSize, 0, (int) ($margin + ($thumbW - $iw) / 2), $y + (int) ($thumbH / 2) + (int) round($initSize / 3), $muted, IG_FONT_DARKROOM_TITLE, $initial);
        }

        $title = ig_fit_text(mb_strtoupper($film['title']), IG_FONT_DARKROOM_TITLE, $geo['titleSize'], $textMaxWidth);
        imagettftext($im, $geo['titleSize'], 0, $textX, $y + $geo['titleOffsetY'], $amber, IG_FONT_DARKROOM_TITLE, $title);

        // Flick Clique names the monthly series, not a place — same reasoning
        // as the website's own poster card (list/index.php): the location is
        // what's actually useful on a compact list row, the series name isn't.
        // Every other venue with a location (Alamo, opted in per day) still
        // needs both to disambiguate which of five it is.
        $venue = $film['venue'] === 'Flick Clique' && $film['location']
            ? $film['location']
            : ($film['location'] ? "{$film['venue']} — {$film['location']}" : $film['venue']);
        if ($film['director']) $venue .= '  ·  dir. ' . $film['director'];
        $meta  = ig_fit_text($venue, IG_FONT_BODY, $geo['metaSize'], $textMaxWidth);
        imagettftext($im, $geo['metaSize'], 0, $textX, $y + $geo['metaOffsetY'], $muted, IG_FONT_BODY, $meta);

        $time = ig_fit_text(ig_format_times($film['timestamps'] ?? [$film['timestamp']]), IG_FONT_BODY, $geo['metaSize'], $textMaxWidth);
        imagettftext($im, $geo['metaSize'], 0, $textX, $y + $geo['timeOffsetY'], $rust, IG_FONT_BODY, $time);

        $y += $rowHeight;
        if ($i < $lastIndex) {
            ig_dashed_line($im, $margin, $w - $margin, $y - 15, $strip, 12, 8);
        }
    }

    $totalMore = $extra + $moreCount;
    if ($totalMore > 0) {
        $label = "+{$totalMore} MORE";
        $midY  = $y + (int) round(($footerY - 30 - $y) / 2);
        $textY = $midY + 8;
        imagettftext($im, 28, 0, $margin, $textY, $amber, IG_FONT_BODY, $label);
        if ($moreCount > 0) {
            $box = imagettfbbox(28, 0, IG_FONT_BODY, $label);
            $tw  = $box[2] - $box[0];
            $ax  = $margin + $tw + 22;
            ig_draw_arrow($im, $ax, $textY - 12, $ax + 34, $amber);
        }
    }

    imagettftext($im, 22, 0, $margin, $footerY, $muted, IG_FONT_BODY, 'Full schedule at cinematx.net');

    if ($season) {
        ig_darkroom_reel($im, 600, 1226, 980, 1304, $reelP);
    }

    return $im;
}

// Evening in Austin, golden-hour half — the only theme where the list page
// and the spotlight page intentionally use different palettes rather than
// one shared one: this is the light end of a sunset that the spotlight
// pages carry into full dark. A thin sky accent across the top (gradient
// and a setting sun, no buildings — drawn architecture reads as generic
// rather than specifically Austin, so it's just the sky) with no text on
// it; everything below sits on solid warm card stock for guaranteed
// legibility, the same reason Darkroom's spotlight page stopped putting
// its wordmark over the hero.
function ig_build_list_page_austin(array $films, $date, $moreCount = 0) {
    $w = 1080;
    $h = 1350;
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);

    $cream       = ig_hex($im, '#FAF1DE');
    $skyTop      = ig_hex($im, '#F7E1B5');
    $skyBottom   = ig_hex($im, '#E8834E');
    $plum        = ig_hex($im, '#4A2338');
    $red         = ig_hex($im, '#922E32');
    $muted       = ig_hex($im, '#8C7562');
    $terracotta  = ig_hex($im, '#C0562B');
    $divider     = ig_hex($im, '#E4D5BC');
    $placeholder = ig_hex($im, '#EDE1C8');
    $stripe      = ig_hex($im, '#F3E9D4');

    imagefill($im, 0, 0, $cream);

    $margin = 80;
    $skyH   = 110;

    ig_gradient_fill($im, 0, 0, $w - 1, $skyH, $skyTop, $skyBottom);
    ig_glow_sun($im, $w - $margin - 110, $skyH - 20, 20, ig_hex($im, '#FFF3D6'), $skyBottom);
    ig_bat_mark($im, $margin + 60, 34, 18, $plum);
    ig_bat_mark($im, $margin + 130, 22, 15, $plum);
    ig_bat_mark($im, $margin + 100, 56, 13, $plum);

    // The horizon has to occlude the sun, not sit in front of it — the sun
    // is drawn into the sky first, then the ground redrawn on top so
    // anything the glow bled past $skyH gets clipped at a hard edge, the
    // way a real setting sun disappears behind the horizon rather than
    // glowing through it.
    imagefilledrectangle($im, 0, $skyH, $w, $h, $cream);

    $chipText = 'CINEMA, TX';
    $chipBox  = imagettfbbox(20, 0, IG_FONT_BODY, $chipText);
    $chipW    = $chipBox[2] - $chipBox[0];
    ig_pill($im, $margin, $skyH + 18, $margin + $chipW + 48, $skyH + 58, $red);
    imagettftext($im, 20, 0, $margin + 24, $skyH + 45, $cream, IG_FONT_BODY, $chipText);

    $y = $skyH + 100;
    imagettftext($im, 22, 0, $margin, $y, $red, IG_FONT_BODY, strtoupper('Today in Austin'));
    $y += 50;
    imagettftext($im, 46, 0, $margin, $y, $plum, IG_FONT_HEADLINE, date('l, F j', $date));
    $y += 42;

    imagefilledrectangle($im, $margin, $y, $w - $margin, $y + 1, $divider);
    $y += 36;

    $geo    = ig_list_row_geometry(min(count($films), 10));
    $thumbW = $geo['thumbW'];
    $thumbH = $geo['thumbH'];
    $textX  = $margin + $thumbW + 28;
    $textMaxWidth = $w - $margin - $textX;

    $rowHeight = $geo['rowHeight'];
    $footerY   = $h - 60;
    $rows      = array_slice($films, 0, 10);
    $extra     = count($films) - count($rows);

    if (empty($films)) {
        imagettftext($im, 28, 0, $margin, $y, $muted, IG_FONT_BODY, 'Nothing scraped for today — check back later.');
    }

    $lastIndex = count($rows) - 1;
    foreach ($rows as $i => $film) {
        // Zebra banding, same technique as every other theme — a strip a
        // couple RGB points off the cream background, behind every other row.
        if ($i % 2 === 1) {
            imagefilledrectangle($im, $margin, $y - 15, $w - $margin, $y + $rowHeight - 15, $stripe);
        }

        $thumb = ig_fetch_thumb($film['poster'], $thumbW, $thumbH);
        if ($thumb) {
            imagecopy($im, $thumb, $margin, $y, 0, 0, $thumbW, $thumbH);
            imagedestroy($thumb);
        } else {
            imagefilledrectangle($im, $margin, $y, $margin + $thumbW, $y + $thumbH, $placeholder);
            $initial = mb_strtoupper(mb_substr($film['title'], 0, 1));
            $initSize = max(20, (int) round($geo['titleSize'] * 1.2));
            $ibox = imagettfbbox($initSize, 0, IG_FONT_HEADLINE, $initial);
            $iw = $ibox[2] - $ibox[0];
            imagettftext($im, $initSize, 0, (int) ($margin + ($thumbW - $iw) / 2), $y + (int) ($thumbH / 2) + (int) round($initSize / 3), $muted, IG_FONT_HEADLINE, $initial);
        }

        $title = ig_fit_text(mb_strtoupper($film['title']), IG_FONT_HEADLINE, $geo['titleSize'], $textMaxWidth);
        imagettftext($im, $geo['titleSize'], 0, $textX, $y + $geo['titleOffsetY'], $plum, IG_FONT_HEADLINE, $title);

        // Flick Clique names the monthly series, not a place — same reasoning
        // as the website's own poster card (list/index.php): the location is
        // what's actually useful on a compact list row, the series name isn't.
        // Every other venue with a location (Alamo, opted in per day) still
        // needs both to disambiguate which of five it is.
        $venue = $film['venue'] === 'Flick Clique' && $film['location']
            ? $film['location']
            : ($film['location'] ? "{$film['venue']} — {$film['location']}" : $film['venue']);
        if ($film['director']) $venue .= '  ·  dir. ' . $film['director'];
        $meta  = ig_fit_text($venue, IG_FONT_BODY, $geo['metaSize'], $textMaxWidth);
        imagettftext($im, $geo['metaSize'], 0, $textX, $y + $geo['metaOffsetY'], $muted, IG_FONT_BODY, $meta);

        $time = ig_fit_text(ig_format_times($film['timestamps'] ?? [$film['timestamp']]), IG_FONT_BODY, $geo['metaSize'], $textMaxWidth);
        imagettftext($im, $geo['metaSize'], 0, $textX, $y + $geo['timeOffsetY'], $terracotta, IG_FONT_BODY, $time);

        $y += $rowHeight;
        if ($i < $lastIndex) {
            imagefilledrectangle($im, $margin, $y - 15, $w - $margin, $y - 14, $divider);
        }
    }

    $totalMore = $extra + $moreCount;
    if ($totalMore > 0) {
        $label = "+{$totalMore} MORE";
        $midY  = $y + (int) round(($footerY - 30 - $y) / 2);
        $textY = $midY + 8;
        imagettftext($im, 28, 0, $margin, $textY, $red, IG_FONT_BODY, $label);
        if ($moreCount > 0) {
            $box = imagettfbbox(28, 0, IG_FONT_BODY, $label);
            $tw  = $box[2] - $box[0];
            $ax  = $margin + $tw + 22;
            ig_draw_arrow($im, $ax, $textY - 12, $ax + 34, $red);
        }
    }

    imagettftext($im, 22, 0, $margin, $footerY, $muted, IG_FONT_BODY, 'Full schedule at cinematx.net');

    return $im;
}

// The list-thumbnail URL fetch_tmdb() gives every film is sized w300; a
// full-bleed feature-page hero wants more detail. Same image, size segment
// swapped — no change to the TMDB cache fetch_tmdb() already keeps.
function ig_hero_url($poster_url) {
    if (!$poster_url) return null;
    return preg_replace('#/t/p/w\d+#', '/t/p/w780', $poster_url);
}

/**
 * Wraps $text across at most $maxLines lines that each fit $maxWidth,
 * ellipsizing the last line if there's more text than fits. Generalises
 * ig_fit_text()'s single-line truncation to a paragraph.
 */
function ig_wrap_lines($text, $font, $size, $maxWidth, $maxLines) {
    $text = trim((string) $text);
    if ($text === '' || $maxLines < 1) return [];

    $words   = preg_split('/\s+/', $text);
    $lines   = [];
    $current = '';

    foreach ($words as $word) {
        $test = $current === '' ? $word : "$current $word";
        $bbox = imagettfbbox($size, 0, $font, $test);
        if ($current !== '' && $bbox[2] - $bbox[0] > $maxWidth) {
            $lines[] = $current;
            if (count($lines) === $maxLines) { $current = ''; break; }
            $current = $word;
        } else {
            $current = $test;
        }
    }
    if (count($lines) < $maxLines && $current !== '') $lines[] = $current;

    $consumed = 0;
    foreach ($lines as $l) $consumed += count(preg_split('/\s+/', $l));
    if ($consumed < count($words) && $lines) {
        $last     = count($lines) - 1;
        $withDots = $lines[$last] . '…';
        $bbox     = imagettfbbox($size, 0, $font, $withDots);
        $lines[$last] = ($bbox[2] - $bbox[0] <= $maxWidth)
            ? $withDots
            : ig_fit_text($lines[$last], $font, $size, $maxWidth);
    }

    return $lines;
}

// Same wrapping pass as ig_wrap_lines(), but returns null the moment a
// word would overflow $maxLines instead of dropping it — the caller uses
// that to test whether $size is small enough for the whole title to
// survive intact before ever accepting a truncated result.
function ig_wrap_lines_exact($text, $font, $size, $maxWidth, $maxLines) {
    $text = trim((string) $text);
    if ($text === '') return [];

    $words   = preg_split('/\s+/', $text);
    $lines   = [];
    $current = '';

    foreach ($words as $word) {
        $test = $current === '' ? $word : "$current $word";
        $bbox = imagettfbbox($size, 0, $font, $test);
        if ($current !== '' && $bbox[2] - $bbox[0] > $maxWidth) {
            $lines[] = $current;
            if (count($lines) >= $maxLines) return null;
            $current = $word;
        } else {
            $current = $test;
        }
    }
    if ($current !== '') $lines[] = $current;

    return $lines;
}

/**
 * The spotlight page's title used to wrap at a fixed size and lose
 * whatever didn't fit on the second line to an ellipsis — the same
 * problem the list rows had before shrinking replaced their own
 * ig_fit_text() truncation. This shrinks the size first so the full
 * title survives more often; ig_wrap_lines()'s truncation only kicks in
 * once even $minSize doesn't fit within $maxLines. $lineHeight is the
 * caller's leading at its default $size — scaled down with the font so a
 * shrunk two-line title doesn't sit with its original, now-oversized gap.
 */
function ig_fit_title_wrapped($text, $font, $size, $maxWidth, $maxLines, $lineHeight, $minSize = null) {
    $minSize = $minSize ?? max(30, (int) round($size * 0.65));
    for ($try = $size; $try >= $minSize; $try--) {
        $lines = ig_wrap_lines_exact($text, $font, $try, $maxWidth, $maxLines);
        if ($lines !== null) {
            return ['lines' => $lines, 'size' => $try, 'lineHeight' => (int) round($lineHeight * $try / $size)];
        }
    }
    return [
        'lines'      => ig_wrap_lines($text, $font, $minSize, $maxWidth, $maxLines),
        'size'       => $minSize,
        'lineHeight' => (int) round($lineHeight * $minSize / $size),
    ];
}

/**
 * A single-film spotlight page, in the visual language of the homepage's
 * Journal panel (.lead) — hero, kicker, title, deck, faded overview —
 * redrawn with GD since posted images have no HTML/CSS renderer behind them.
 *
 * Used to fill remaining carousel slots after the list page(s), one film at
 * a time. A film missing overview/genres (a real TMDB miss) just omits that
 * line, the same graceful-degradation the list rows already use for a
 * missing poster.
 */
function ig_build_feature_page_paper(array $film, $date) {
    $w = 1080;
    $h = 1350;
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);

    // Same October palette as the list page: warmer paper, the red accent
    // turned burnt orange. $pr/$pg/$pb is the paper colour as numbers, which
    // the hero's fade below needs to blend into it without a seam.
    $season = ig_halloween_season($date);
    [$pr, $pg, $pb] = $season ? [0xF6, 0xEB, 0xD9] : [0xF4, 0xF1, 0xEB];
    $paper       = imagecolorallocate($im, $pr, $pg, $pb);
    $red         = ig_hex($im, $season ? '#B8531A' : '#922E32');
    $ink         = ig_hex($im, '#14120F');
    $muted       = ig_hex($im, $season ? '#76654F' : '#6B6659');
    $divider     = ig_hex($im, $season ? '#E2D3B8' : '#DED7C7');
    $placeholder = ig_hex($im, $season ? '#EBDCC3' : '#E4DECE');

    imagefill($im, 0, 0, $paper);

    $margin = 80;
    $heroH  = 700;

    $heroUrl = ig_hero_url($film['poster']);
    $hero = ig_fetch_thumb($heroUrl, $w, $heroH, ig_poster_crop_bias($heroUrl));
    if ($hero) {
        imagecopy($im, $hero, 0, 0, 0, 0, $w, $heroH);
        imagedestroy($hero);
    } else {
        imagefilledrectangle($im, 0, 0, $w, $heroH, $placeholder);
        $initial = mb_strtoupper(mb_substr($film['title'], 0, 1));
        $ibox = imagettfbbox(120, 0, IG_FONT_HEADLINE, $initial);
        $iw = $ibox[2] - $ibox[0];
        imagettftext($im, 120, 0, (int) (($w - $iw) / 2), (int) ($heroH / 2) + 40, $muted, IG_FONT_HEADLINE, $initial);
    }

    // Fades the hero into the paper background over its last 120px, rather
    // than a hard seam — .lead__fade's job, done with alpha-blended bands
    // since GD has no gradient primitive.
    $fadeH = 120;
    for ($i = 0; $i < $fadeH; $i++) {
        $alpha = (int) round(127 * (1 - $i / $fadeH));
        $band  = imagecolorallocatealpha($im, $pr, $pg, $pb, $alpha);
        imagefilledrectangle($im, 0, $heroH - $fadeH + $i, $w, $heroH - $fadeH + $i + 1, $band);
    }

    // The thin brand rule carries across every page for consistency, but the
    // "CINEMA, TX" wordmark itself only needs to appear once per carousel —
    // the list page (always page 1) already establishes it. Its spot here
    // goes to the thing a spotlight page is actually for: when this is
    // showing. One pill per showtime, stacked, so a repeat screening (two
    // showings of the same film in a day) reads as two pills rather than a
    // single cramped line.
    imagefilledrectangle($im, 0, 0, $w, 14, $red);

    // A spotlight page's Halloween piece — Paper's spider theme, scaled down:
    // a web in the bottom-right corner, clear of the left-aligned footer and
    // director lines. The top corners belong to the hero image and its pills.
    if ($season) {
        ig_cobweb($im, $w - 46, $h - 46, 118, imagecolorallocatealpha($im, 0x4A, 0x3B, 0x2E, 78), -1, -1);
    }

    $pillFont = 30;
    $pillPadX = 30;
    $pillH    = 60;
    $py       = 70;

    foreach (($film['timestamps'] ?? [$film['timestamp'] ?? null]) as $ts) {
        if (!$ts) continue;
        $label = date('g:i A', $ts);
        $box   = imagettfbbox($pillFont, 0, IG_FONT_BODY, $label);
        $tw    = $box[2] - $box[0];
        ig_pill($im, $margin, $py, $margin + $tw + $pillPadX * 2, $py + $pillH, $red);
        imagettftext($im, $pillFont, 0, $margin + $pillPadX, $py + $pillH - 18, $paper, IG_FONT_BODY, $label);
        $py += $pillH + 14;
    }

    $textMaxWidth = $w - $margin * 2;
    $y = $heroH + 50;

    // A spotlight page has the room the compact list rows don't — full venue
    // and location both, unlike ig_build_list_page()'s own Flick Clique
    // carve-out above, which drops the venue name for lack of space.
    $kicker = strtoupper($film['venue'] ?? '');
    if (!empty($film['location'])) $kicker .= ' - ' . strtoupper($film['location']);
    if ($kicker !== '') {
        imagettftext($im, 24, 0, $margin, $y, $red, IG_FONT_BODY, $kicker);
        // 56px caps reach ~52px above their own baseline — a flat leading
        // borrowed from the 24px line above it left the title's top edge
        // drawn back over the kicker. Gapped by the *next* line's ascent,
        // not the current line's, since it's the line about to be drawn
        // that determines how far up the pixels reach.
        $y += 68;
    }

    $titleFit = ig_fit_title_wrapped(mb_strtoupper($film['title']), IG_FONT_HEADLINE, 56, $textMaxWidth, 2, 72);
    foreach ($titleFit['lines'] as $line) {
        imagettftext($im, $titleFit['size'], 0, $margin, $y, $ink, IG_FONT_HEADLINE, $line);
        $y += $titleFit['lineHeight'];
    }

    $deckParts = [];
    if (!empty($film['year']))    $deckParts[] = $film['year'];
    if (!empty($film['genres']))  $deckParts[] = $film['genres'];
    if (!empty($film['runtime'])) $deckParts[] = round($film['runtime']) . ' min';
    if ($deckParts) {
        imagettftext($im, 24, 0, $margin, $y, $muted, IG_FONT_BODY, implode('  ·  ', $deckParts));
        $y += 44;
    }

    $y += 20;
    imagefilledrectangle($im, $margin, $y, $w - $margin, $y + 1, $divider);
    $y += 36;

    if (!empty($film['overview'])) {
        foreach (ig_wrap_lines($film['overview'], IG_FONT_BODY, 26, $textMaxWidth, 4) as $line) {
            imagettftext($im, 26, 0, $margin, $y, $ink, IG_FONT_BODY, $line);
            $y += 38;
        }
    }

    $footerY = $h - 60;
    if (!empty($film['director'])) {
        imagettftext($im, 22, 0, $margin, $footerY - 34, $muted, IG_FONT_BODY, 'dir. ' . $film['director']);
    }
    imagettftext($im, 22, 0, $margin, $footerY, $muted, IG_FONT_BODY, 'Full schedule at cinematx.net');

    return $im;
}

// Marquee's spotlight page: same skeleton as the paper version — hero,
// showtime pills, kicker, title, deck, faded overview — but the hero fades
// to black instead of paper, and the whole card sits inside the same gold
// bulb frame the list page uses, so a single swiped-to page still reads as
// part of the same marquee carousel.
function ig_build_feature_page_marquee(array $film, $date) {
    $w = 1080;
    $h = 1350;
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);

    $bg          = ig_hex($im, '#14120F');
    $ink         = ig_hex($im, '#F2EEE5');
    $muted       = ig_hex($im, '#B5AFA0');
    $divider     = ig_hex($im, '#3A362E');
    $placeholder = ig_hex($im, '#2A2620');
    // Same October swap as the list page (see ig_halloween_season()): the
    // gold accent turns pumpkin orange.
    $season      = ig_halloween_season($date);
    [$ar, $ag, $ab] = $season ? [0xF2, 0x8A, 0x2E] : [0xF2, 0xC1, 0x4E];
    $gold        = imagecolorallocate($im, $ar, $ag, $ab);
    $goldGlow    = imagecolorallocatealpha($im, $ar, $ag, $ab, 100);

    imagefill($im, 0, 0, $bg);

    $margin = 80;
    $heroH  = 700;

    $heroUrl = ig_hero_url($film['poster']);
    $hero = ig_fetch_thumb($heroUrl, $w, $heroH, ig_poster_crop_bias($heroUrl));
    if ($hero) {
        imagecopy($im, $hero, 0, 0, 0, 0, $w, $heroH);
        imagedestroy($hero);
    } else {
        imagefilledrectangle($im, 0, 0, $w, $heroH, $placeholder);
        $initial = mb_strtoupper(mb_substr($film['title'], 0, 1));
        $ibox = imagettfbbox(120, 0, IG_FONT_MARQUEE_TITLE, $initial);
        $iw = $ibox[2] - $ibox[0];
        imagettftext($im, 120, 0, (int) (($w - $iw) / 2), (int) ($heroH / 2) + 40, $gold, IG_FONT_MARQUEE_TITLE, $initial);
    }

    // Fades to black, matching this theme's background, instead of paper's
    // cream — same alpha-band technique, different destination color.
    $fadeH = 120;
    for ($i = 0; $i < $fadeH; $i++) {
        $alpha = (int) round(127 * (1 - $i / $fadeH));
        $band  = imagecolorallocatealpha($im, 0x14, 0x12, 0x0F, $alpha);
        imagefilledrectangle($im, 0, $heroH - $fadeH + $i, $w, $heroH - $fadeH + $i + 1, $band);
    }

    ig_marquee_bulbs($im, 28, 28, $w - 28, $h - 28, 40, $goldGlow, $gold);

    // A spotlight page's Halloween pieces: a cobweb in the bottom-right
    // corner with two pumpkins (a big one and a smaller one) sitting on the
    // same ground just left of it, all clear of the left-aligned footer and
    // director lines. The top corners belong to the hero image and its pills.
    if ($season) {
        ig_cobweb($im, $w - 46, $h - 46, 118, imagecolorallocatealpha($im, 0xB5, 0xAF, 0xA0, 78), -1, -1);
        ig_pumpkin($im, $w - 218, $h - 50, 44, true);
        ig_pumpkin($im, $w - 296, $h - 50, 28);
    }

    $pillFont = 30;
    $pillPadX = 30;
    $pillH    = 60;
    $py       = 70;

    foreach (($film['timestamps'] ?? [$film['timestamp'] ?? null]) as $ts) {
        if (!$ts) continue;
        $label = date('g:i A', $ts);
        $box   = imagettfbbox($pillFont, 0, IG_FONT_BODY, $label);
        $tw    = $box[2] - $box[0];
        ig_pill($im, $margin, $py, $margin + $tw + $pillPadX * 2, $py + $pillH, $gold);
        imagettftext($im, $pillFont, 0, $margin + $pillPadX, $py + $pillH - 18, $bg, IG_FONT_BODY, $label);
        $py += $pillH + 14;
    }

    $textMaxWidth = $w - $margin * 2;
    $y = $heroH + 50;

    // A spotlight page has the room the compact list rows don't — full venue
    // and location both, unlike ig_build_list_page()'s own Flick Clique
    // carve-out above, which drops the venue name for lack of space.
    $kicker = strtoupper($film['venue'] ?? '');
    if (!empty($film['location'])) $kicker .= ' - ' . strtoupper($film['location']);
    if ($kicker !== '') {
        imagettftext($im, 24, 0, $margin, $y, $gold, IG_FONT_BODY, $kicker);
        // Anton reaches noticeably higher above its own baseline than
        // Fraunces at the same size (69px of ascent at 56px vs Fraunces'
        // 52px, measured) — the paper theme's gap would drive the title's
        // top edge back into the kicker here.
        $y += 84;
    }

    $titleFit = ig_fit_title_wrapped(mb_strtoupper($film['title']), IG_FONT_MARQUEE_TITLE, 56, $textMaxWidth, 2, 86);
    foreach ($titleFit['lines'] as $line) {
        imagettftext($im, $titleFit['size'], 0, $margin, $y, $ink, IG_FONT_MARQUEE_TITLE, $line);
        $y += $titleFit['lineHeight'];
    }

    $deckParts = [];
    if (!empty($film['year']))    $deckParts[] = $film['year'];
    if (!empty($film['genres']))  $deckParts[] = $film['genres'];
    if (!empty($film['runtime'])) $deckParts[] = round($film['runtime']) . ' min';
    if ($deckParts) {
        imagettftext($im, 24, 0, $margin, $y, $muted, IG_FONT_BODY, implode('  ·  ', $deckParts));
        $y += 44;
    }

    $y += 20;
    imagefilledrectangle($im, $margin, $y, $w - $margin, $y + 1, $divider);
    $y += 36;

    if (!empty($film['overview'])) {
        foreach (ig_wrap_lines($film['overview'], IG_FONT_BODY, 26, $textMaxWidth, 4) as $line) {
            imagettftext($im, 26, 0, $margin, $y, $ink, IG_FONT_BODY, $line);
            $y += 38;
        }
    }

    $footerY = $h - 60;
    if (!empty($film['director'])) {
        imagettftext($im, 22, 0, $margin, $footerY - 34, $muted, IG_FONT_BODY, 'dir. ' . $film['director']);
    }
    imagettftext($im, 22, 0, $margin, $footerY, $muted, IG_FONT_BODY, 'Full schedule at cinematx.net');

    return $im;
}

// Zine's spotlight page: the poster runs duotoned instead of full color —
// the single biggest cue that this is a Riso print, not a photograph — with
// the same skeleton (hero, showtime pills, kicker, title, deck, overview)
// paper/marquee use. Special Elite's ascent/descent at these sizes measure
// close enough to Fraunces' that this reuses paper's exact gap constants.
function ig_build_feature_page_zine(array $film, $date) {
    $w = 1080;
    $h = 1350;
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);

    $paper       = ig_hex($im, '#F0EEE4');
    $pink        = ig_hex($im, '#FF3D8A');
    $ink         = ig_hex($im, '#161513');
    $muted       = ig_hex($im, '#8A8578');
    $divider     = ig_hex($im, '#C9C2AF');
    $placeholder = ig_hex($im, '#E3DDD0');

    imagefill($im, 0, 0, $paper);

    $margin = 80;
    $heroH  = 700;

    $heroUrl = ig_hero_url($film['poster']);
    $hero = ig_fetch_thumb($heroUrl, $w, $heroH, ig_poster_crop_bias($heroUrl));
    if ($hero) {
        imagecopy($im, $hero, 0, 0, 0, 0, $w, $heroH);
        imagedestroy($hero);
    } else {
        imagefilledrectangle($im, 0, 0, $w, $heroH, $placeholder);
        $initial = mb_strtoupper(mb_substr($film['title'], 0, 1));
        $ibox = imagettfbbox(120, 0, IG_FONT_ZINE_TITLE, $initial);
        $iw = $ibox[2] - $ibox[0];
        imagettftext($im, 120, 0, (int) (($w - $iw) / 2), (int) ($heroH / 2) + 40, $muted, IG_FONT_ZINE_TITLE, $initial);
    }

    $fadeH = 120;
    for ($i = 0; $i < $fadeH; $i++) {
        $alpha = (int) round(127 * (1 - $i / $fadeH));
        $band  = imagecolorallocatealpha($im, 0xF0, 0xEE, 0xE4, $alpha);
        imagefilledrectangle($im, 0, $heroH - $fadeH + $i, $w, $heroH - $fadeH + $i + 1, $band);
    }

    imagefilledrectangle($im, 0, 0, $w, 20, $ink);

    $pillFont = 30;
    $pillPadX = 30;
    $pillH    = 60;
    $py       = 70;

    foreach (($film['timestamps'] ?? [$film['timestamp'] ?? null]) as $ts) {
        if (!$ts) continue;
        $label = date('g:i A', $ts);
        $box   = imagettfbbox($pillFont, 0, IG_FONT_BODY, $label);
        $tw    = $box[2] - $box[0];
        ig_pill($im, $margin, $py, $margin + $tw + $pillPadX * 2, $py + $pillH, $pink);
        imagettftext($im, $pillFont, 0, $margin + $pillPadX, $py + $pillH - 18, $ink, IG_FONT_BODY, $label);
        $py += $pillH + 14;
    }

    $textMaxWidth = $w - $margin * 2;
    $y = $heroH + 50;

    // A spotlight page has the room the compact list rows don't — full venue
    // and location both, unlike ig_build_list_page()'s own Flick Clique
    // carve-out above, which drops the venue name for lack of space.
    $kicker = strtoupper($film['venue'] ?? '');
    if (!empty($film['location'])) $kicker .= ' - ' . strtoupper($film['location']);
    if ($kicker !== '') {
        imagettftext($im, 24, 0, $margin, $y, $pink, IG_FONT_BODY, $kicker);
        $y += 68;
    }

    $titleFit = ig_fit_title_wrapped(mb_strtoupper($film['title']), IG_FONT_ZINE_TITLE, 56, $textMaxWidth, 2, 72);
    foreach ($titleFit['lines'] as $line) {
        imagettftext($im, $titleFit['size'], 0, $margin, $y, $ink, IG_FONT_ZINE_TITLE, $line);
        $y += $titleFit['lineHeight'];
    }

    $deckParts = [];
    if (!empty($film['year']))    $deckParts[] = $film['year'];
    if (!empty($film['genres']))  $deckParts[] = $film['genres'];
    if (!empty($film['runtime'])) $deckParts[] = round($film['runtime']) . ' min';
    if ($deckParts) {
        imagettftext($im, 24, 0, $margin, $y, $muted, IG_FONT_BODY, implode('  ·  ', $deckParts));
        $y += 44;
    }

    $y += 20;
    ig_torn_line($im, $margin, $w - $margin, $y, $divider);
    $y += 36;

    if (!empty($film['overview'])) {
        foreach (ig_wrap_lines($film['overview'], IG_FONT_BODY, 26, $textMaxWidth, 4) as $line) {
            imagettftext($im, 26, 0, $margin, $y, $ink, IG_FONT_BODY, $line);
            $y += 38;
        }
    }

    $footerY = $h - 60;
    if (!empty($film['director'])) {
        imagettftext($im, 22, 0, $margin, $footerY - 34, $muted, IG_FONT_BODY, 'dir. ' . $film['director']);
    }
    imagettftext($im, 22, 0, $margin, $footerY, $muted, IG_FONT_BODY, 'Full schedule at cinematx.net');

    return $im;
}

// Newsprint's spotlight page: showtimes draw as flat rectangles instead of
// ig_pill()'s rounded, shadowed shape — a newspaper doesn't have soft UI
// chrome. Title-line gap is a touch wider than paper/zine use (measured:
// Roboto Slab's ascent at 56px runs to the same 56px, versus Fraunces' 52px).
function ig_build_feature_page_newsprint(array $film, $date) {
    $w = 1080;
    $h = 1350;
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);

    $paper       = ig_hex($im, '#E8E4D5');
    $red         = ig_hex($im, '#922E32');
    $ink         = ig_hex($im, '#1C1B19');
    $muted       = ig_hex($im, '#6B675C');
    $rule        = ig_hex($im, '#B8B2A0');
    $placeholder = ig_hex($im, '#D9D4C3');

    imagefill($im, 0, 0, $paper);

    $margin = 80;
    $heroH  = 700;

    $heroUrl = ig_hero_url($film['poster']);
    $hero = ig_fetch_thumb($heroUrl, $w, $heroH, ig_poster_crop_bias($heroUrl));
    if ($hero) {
        imagecopy($im, $hero, 0, 0, 0, 0, $w, $heroH);
        imagedestroy($hero);
    } else {
        imagefilledrectangle($im, 0, 0, $w, $heroH, $placeholder);
        $initial = mb_strtoupper(mb_substr($film['title'], 0, 1));
        $ibox = imagettfbbox(120, 0, IG_FONT_NEWSPRINT_TITLE, $initial);
        $iw = $ibox[2] - $ibox[0];
        imagettftext($im, 120, 0, (int) (($w - $iw) / 2), (int) ($heroH / 2) + 40, $muted, IG_FONT_NEWSPRINT_TITLE, $initial);
    }

    $fadeH = 120;
    for ($i = 0; $i < $fadeH; $i++) {
        $alpha = (int) round(127 * (1 - $i / $fadeH));
        $band  = imagecolorallocatealpha($im, 0xE8, 0xE4, 0xD5, $alpha);
        imagefilledrectangle($im, 0, $heroH - $fadeH + $i, $w, $heroH - $fadeH + $i + 1, $band);
    }

    imagefilledrectangle($im, 0, 0, $w, 8, $ink);

    $labelFont = 30;
    $labelPadX = 26;
    $labelH    = 56;
    $py        = 70;

    foreach (($film['timestamps'] ?? [$film['timestamp'] ?? null]) as $ts) {
        if (!$ts) continue;
        $label = date('g:i A', $ts);
        $box   = imagettfbbox($labelFont, 0, IG_FONT_BODY, $label);
        $tw    = $box[2] - $box[0];
        imagefilledrectangle($im, $margin, $py, $margin + $tw + $labelPadX * 2, $py + $labelH, $red);
        imagettftext($im, $labelFont, 0, $margin + $labelPadX, $py + $labelH - 16, $paper, IG_FONT_BODY, $label);
        $py += $labelH + 12;
    }

    $textMaxWidth = $w - $margin * 2;
    $y = $heroH + 50;

    // A spotlight page has the room the compact list rows don't — full venue
    // and location both, unlike ig_build_list_page()'s own Flick Clique
    // carve-out above, which drops the venue name for lack of space.
    $kicker = strtoupper($film['venue'] ?? '');
    if (!empty($film['location'])) $kicker .= ' - ' . strtoupper($film['location']);
    if ($kicker !== '') {
        imagettftext($im, 24, 0, $margin, $y, $red, IG_FONT_BODY, $kicker);
        $y += 68;
    }

    $titleFit = ig_fit_title_wrapped(mb_strtoupper($film['title']), IG_FONT_NEWSPRINT_TITLE, 56, $textMaxWidth, 2, 76);
    foreach ($titleFit['lines'] as $line) {
        imagettftext($im, $titleFit['size'], 0, $margin, $y, $ink, IG_FONT_NEWSPRINT_TITLE, $line);
        $y += $titleFit['lineHeight'];
    }

    $deckParts = [];
    if (!empty($film['year']))    $deckParts[] = $film['year'];
    if (!empty($film['genres']))  $deckParts[] = $film['genres'];
    if (!empty($film['runtime'])) $deckParts[] = round($film['runtime']) . ' min';
    if ($deckParts) {
        imagettftext($im, 24, 0, $margin, $y, $muted, IG_FONT_BODY, implode('  ·  ', $deckParts));
        $y += 44;
    }

    $y += 20;
    imagefilledrectangle($im, $margin, $y, $w - $margin, $y + 2, $ink);
    $y += 36;

    if (!empty($film['overview'])) {
        foreach (ig_wrap_lines($film['overview'], IG_FONT_BODY, 26, $textMaxWidth, 4) as $line) {
            imagettftext($im, 26, 0, $margin, $y, $ink, IG_FONT_BODY, $line);
            $y += 38;
        }
    }

    $footerY = $h - 60;
    if (!empty($film['director'])) {
        imagettftext($im, 22, 0, $margin, $footerY - 34, $muted, IG_FONT_BODY, 'dir. ' . $film['director']);
    }
    imagettftext($im, 22, 0, $margin, $footerY, $muted, IG_FONT_BODY, 'Full schedule at cinematx.net');

    // October: the list page's moon and clouds, smaller, in the footer's empty
    // right side. (Small enough to clear the overview's last line, which can
    // run down to about y 1190 when the title takes two lines.)
    if (ig_halloween_season($date)) {
        ig_halftone_moon($im, 900, 1252, 44, $ink);
        ig_news_cloud($im, 940, 1272, 0.8, $ink, $paper);
        ig_news_cloud($im, 846, 1226, 0.5, $ink, $paper);
    }

    return $im;
}

// Neon's spotlight page: hero stays full color (this theme is about
// watching it on a CRT, not a print-reproduction tint); showtimes use
// ig_pill()'s usual rounded, shadowed shape — a lit rounded-corner sign
// is exactly what a neon pill already looks like.
function ig_build_feature_page_neon(array $film, $date) {
    $w = 1080;
    $h = 1350;
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);

    $bg          = ig_hex($im, '#150829');
    $cyan        = ig_hex($im, '#2DE2E6');
    $pink        = ig_hex($im, '#FF2E9A');
    $ink         = ig_hex($im, '#F5F0FF');
    $muted       = ig_hex($im, '#8B7FA8');
    $divider     = ig_hex($im, '#3A2B5C');
    $placeholder = ig_hex($im, '#241243');
    $dark        = $bg;
    $cyanGlow    = imagecolorallocatealpha($im, 0x2D, 0xE2, 0xE6, 105);

    imagefill($im, 0, 0, $bg);

    $margin = 80;
    $heroH  = 700;

    $heroUrl = ig_hero_url($film['poster']);
    $hero = ig_fetch_thumb($heroUrl, $w, $heroH, ig_poster_crop_bias($heroUrl));
    if ($hero) {
        imagecopy($im, $hero, 0, 0, 0, 0, $w, $heroH);
        imagedestroy($hero);
    } else {
        imagefilledrectangle($im, 0, 0, $w, $heroH, $placeholder);
        $initial = mb_strtoupper(mb_substr($film['title'], 0, 1));
        $ibox = imagettfbbox(84, 0, IG_FONT_NEON_TITLE, $initial);
        $iw = $ibox[2] - $ibox[0];
        ig_neon_text($im, 84, (int) (($w - $iw) / 2), (int) ($heroH / 2) + 32, IG_FONT_NEON_TITLE, $initial, $muted, $cyanGlow);
    }

    $fadeH = 120;
    for ($i = 0; $i < $fadeH; $i++) {
        $alpha = (int) round(127 * (1 - $i / $fadeH));
        $band  = imagecolorallocatealpha($im, 0x15, 0x08, 0x29, $alpha);
        imagefilledrectangle($im, 0, $heroH - $fadeH + $i, $w, $heroH - $fadeH + $i + 1, $band);
    }

    ig_neon_border($im, 24, 24, $w - 24, $h - 24, $cyanGlow, $cyan);

    // October: the graveyard from the list page, in the footer's empty right
    // side. (The skull and dying-E sign were tried in the hero's top-right and
    // dropped — the pills own that corner and the photo is the star.)
    $season = ig_halloween_season($date);

    $pillFont = 30;
    $pillPadX = 30;
    $pillH    = 60;
    $py       = 70;

    foreach (($film['timestamps'] ?? [$film['timestamp'] ?? null]) as $ts) {
        if (!$ts) continue;
        $label = date('g:i A', $ts);
        $box   = imagettfbbox($pillFont, 0, IG_FONT_BODY, $label);
        $tw    = $box[2] - $box[0];
        ig_pill($im, $margin, $py, $margin + $tw + $pillPadX * 2, $py + $pillH, $cyan);
        imagettftext($im, $pillFont, 0, $margin + $pillPadX, $py + $pillH - 18, $dark, IG_FONT_BODY, $label);
        $py += $pillH + 14;
    }

    $textMaxWidth = $w - $margin * 2;
    $y = $heroH + 50;

    // A spotlight page has the room the compact list rows don't — full venue
    // and location both, unlike ig_build_list_page()'s own Flick Clique
    // carve-out above, which drops the venue name for lack of space.
    $kicker = strtoupper($film['venue'] ?? '');
    if (!empty($film['location'])) $kicker .= ' - ' . strtoupper($film['location']);
    if ($kicker !== '') {
        imagettftext($im, 24, 0, $margin, $y, $pink, IG_FONT_BODY, $kicker);
        // Baloo 2's ascent at 46px (measured: 42px) runs shorter than
        // Monoton's did at 56px, so this gap shrank along with the title
        // size rather than keeping the old, now-oversized clearance.
        $y += 60;
    }

    $titleFit = ig_fit_title_wrapped(mb_strtoupper($film['title']), IG_FONT_NEON_TITLE, 46, $textMaxWidth, 2, 60);
    foreach ($titleFit['lines'] as $line) {
        ig_neon_text($im, $titleFit['size'], $margin, $y, IG_FONT_NEON_TITLE, $line, $cyan, $cyanGlow);
        $y += $titleFit['lineHeight'];
    }

    // Live-score/presented-with-or-by billing (ctx_billing() in
    // v7/screenings.php) — plain text, no glow, deliberately quiet under the
    // loud glowing title rather than fighting it for attention.
    if (!empty($film['billing'])) {
        imagettftext($im, 20, 0, $margin, $y, $muted, IG_FONT_BODY, ig_fit_text($film['billing'], IG_FONT_BODY, 20, $textMaxWidth));
        $y += 34;
    }

    $deckParts = [];
    if (!empty($film['year']))    $deckParts[] = $film['year'];
    if (!empty($film['genres']))  $deckParts[] = $film['genres'];
    if (!empty($film['runtime'])) $deckParts[] = round($film['runtime']) . ' min';
    if ($deckParts) {
        imagettftext($im, 24, 0, $margin, $y, $muted, IG_FONT_BODY, implode('  ·  ', $deckParts));
        $y += 44;
    }

    $y += 20;
    imagesetthickness($im, 3);
    imageline($im, $margin, $y, $w - $margin, $y, $cyanGlow);
    imagesetthickness($im, 1);
    imagefilledrectangle($im, $margin, $y, $w - $margin, $y + 1, $divider);
    $y += 36;

    if (!empty($film['overview'])) {
        foreach (ig_wrap_lines($film['overview'], IG_FONT_BODY, 26, $textMaxWidth, 4) as $line) {
            imagettftext($im, 26, 0, $margin, $y, $ink, IG_FONT_BODY, $line);
            $y += 38;
        }
    }

    $footerY = $h - 60;
    if (!empty($film['director'])) {
        imagettftext($im, 22, 0, $margin, $footerY - 34, $muted, IG_FONT_BODY, 'dir. ' . $film['director']);
    }
    imagettftext($im, 22, 0, $margin, $footerY, $muted, IG_FONT_BODY, 'Full schedule at cinematx.net');

    if ($season) ig_neon_tombstones($im, $w - $margin - 340, $w - $margin, $h - 40);

    ig_scanlines($im, $w, $h, imagecolorallocatealpha($im, 0, 0, 0, 112));

    return $im;
}

// A "gate card" for one film — no hero photo, same as the list page's no-
// poster rule, so the whole page has to be carried by type: a big title,
// then a row of bordered boarding-stub blocks (one per showtime, plus a
// status block) standing in for the list page's TIME/STATUS columns now
// that there's only one film to describe.
function ig_build_feature_page_terminal(array $film, $date) {
    $w = 1080;
    $h = 1350;
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);

    $bg          = ig_hex($im, '#0B0C0E');
    $ink         = ig_hex($im, '#EDEBE2');
    $muted       = ig_hex($im, '#6B6E73');
    $dim         = ig_hex($im, '#3A3D42');
    $divider     = ig_hex($im, '#26282C');
    $placeholder = ig_hex($im, '#1B1D20');
    $green       = ig_hex($im, '#5FD68C');

    imagefill($im, 0, 0, $bg);

    // The poster rides along the bottom edge instead of the full-bleed top
    // hero every other theme uses — the rest of this page is a printed
    // stub, so the photo reads as something tucked under it rather than
    // the marquee attraction sitting above the type.
    $posterH   = 450;
    $posterTop = $h - $posterH;

    $heroUrl = ig_hero_url($film['poster']);
    $hero = ig_fetch_thumb($heroUrl, $w, $posterH, ig_poster_crop_bias($heroUrl));
    if ($hero) {
        imagecopy($im, $hero, 0, $posterTop, 0, 0, $w, $posterH);
        imagedestroy($hero);
    } else {
        imagefilledrectangle($im, 0, $posterTop, $w, $h, $placeholder);
        $initial = mb_strtoupper(mb_substr($film['title'], 0, 1));
        $ibox = imagettfbbox(84, 0, IG_FONT_TERMINAL, $initial);
        $iw = $ibox[2] - $ibox[0];
        imagettftext($im, 84, 0, (int) (($w - $iw) / 2), $posterTop + (int) ($posterH / 2) + 30, $muted, IG_FONT_TERMINAL, $initial);
    }

    // Fade where the poster meets the text above it — opaque background at
    // the seam, fully transparent fadeH px in, so the photo emerges rather
    // than cutting in on a hard edge.
    $fadeH = 110;
    for ($i = 0; $i < $fadeH; $i++) {
        $alpha = (int) round(127 * ($i / $fadeH));
        $band  = imagecolorallocatealpha($im, 0x0B, 0x0C, 0x0E, $alpha);
        imagefilledrectangle($im, 0, $posterTop + $i, $w, $posterTop + $i + 1, $band);
    }

    // A gradual scrim over the bottom of the photo so the footer stays
    // legible sitting on top of it, regardless of what the poster looks
    // like there — the same problem every theme's hero-image footer solves,
    // just inverted since the image is at the bottom here instead of the top.
    $scrimH   = 140;
    $scrimTop = $h - $scrimH;
    for ($i = 0; $i < $scrimH; $i++) {
        $alpha = 127 - (int) round(64 * ($i / $scrimH));
        $band  = imagecolorallocatealpha($im, 0, 0, 0, $alpha);
        imagefilledrectangle($im, 0, $scrimTop + $i, $w, $scrimTop + $i + 1, $band);
    }

    ig_led_border($im, 24, 24, $w - 24, $h - 24, 26, $dim);

    $margin = 80;

    ig_tracked_text($im, 20, $margin, 110, IG_FONT_TERMINAL, 'CINEMA, TX', $muted, 8);
    ig_tracked_text($im, 40, $margin, 168, IG_FONT_TERMINAL, 'SHOWTIMES', $ink, 6);

    $y = 216;
    imagettftext($im, 24, 0, $margin, $y, $muted, IG_FONT_TERMINAL, strtoupper(date('l, F j', $date)));
    $y += 34;
    imagefilledrectangle($im, $margin, $y, $w - $margin, $y + 1, $divider);
    $y += 60;

    $textMaxWidth = $w - $margin * 2;

    $venue = $film['location'] ? "{$film['venue']} — {$film['location']}" : ($film['venue'] ?? '');
    if ($venue !== '') {
        ig_tracked_text($im, 20, $margin, $y, IG_FONT_TERMINAL, mb_strtoupper($venue), $muted, 4);
        $y += 56;
    }

    $titleFit = ig_fit_title_wrapped(mb_strtoupper($film['title']), IG_FONT_TERMINAL, 54, $textMaxWidth, 2, 66);
    foreach ($titleFit['lines'] as $line) {
        imagettftext($im, $titleFit['size'], 0, $margin, $y, $ink, IG_FONT_TERMINAL, $line);
        $y += $titleFit['lineHeight'];
    }

    $deckParts = [];
    if (!empty($film['year']))    $deckParts[] = $film['year'];
    if (!empty($film['genres']))  $deckParts[] = mb_strtoupper($film['genres']);
    if (!empty($film['runtime'])) $deckParts[] = round($film['runtime']) . ' MIN';
    if ($deckParts) {
        imagettftext($im, 22, 0, $margin, $y, $muted, IG_FONT_TERMINAL, implode('   ·   ', $deckParts));
        $y += 40;
    }

    $y += 24;

    // Boarding-stub blocks — bordered rectangles rather than the shared
    // ig_pill() helper, since a rounded pill reads as software chrome and a
    // square-cornered box reads as a printed stub. One per showtime, plus a
    // status block carrying the same decorative "ON TIME" flavor text the
    // list page's STATUS column uses.
    $drawBlock = function ($im, $x, $y, $bw, $bh, $label, $value, $valueColor) use ($dim) {
        imagesetthickness($im, 2);
        imagerectangle($im, $x, $y, $x + $bw, $y + $bh, $dim);
        imagesetthickness($im, 1);
        ig_tracked_text($im, 13, $x + 16, $y + 28, IG_FONT_TERMINAL, $label, $dim, 3);
        imagettftext($im, 26, 0, $x + 16, $y + $bh - 18, $valueColor, IG_FONT_TERMINAL, $value);
    };

    $blockH = 90;
    $bx = $margin;
    foreach (($film['timestamps'] ?? [$film['timestamp'] ?? null]) as $ts) {
        if (!$ts) continue;
        $value = date('g:i A', $ts);
        $box   = imagettfbbox(26, 0, IG_FONT_TERMINAL, $value);
        $bw    = max(140, ($box[2] - $box[0]) + 32);
        $drawBlock($im, $bx, $y, $bw, $blockH, 'TIME', $value, $ink);
        $bx += $bw + 18;
    }

    $statusValue = 'ON TIME';
    $box = imagettfbbox(26, 0, IG_FONT_TERMINAL, $statusValue);
    $bw  = ($box[2] - $box[0]) + 40;
    $drawBlock($im, $bx, $y, $bw, $blockH, 'STATUS', $statusValue, $green);
    $y += $blockH + 44;

    imagefilledrectangle($im, $margin, $y, $w - $margin, $y + 1, $divider);
    $y += 40;

    if (!empty($film['overview'])) {
        // 3 lines, not the 5 other themes' feature pages use — this page's
        // bottom third is the poster now, not more room for body copy.
        foreach (ig_wrap_lines($film['overview'], IG_FONT_TERMINAL, 22, $textMaxWidth, 3) as $line) {
            imagettftext($im, 22, 0, $margin, $y, $ink, IG_FONT_TERMINAL, $line);
            $y += 34;
        }
    }

    $footerY = $h - 60;
    if (!empty($film['director'])) {
        imagettftext($im, 18, 0, $margin, $footerY - 30, $muted, IG_FONT_TERMINAL, 'DIR. ' . mb_strtoupper($film['director']));
    }
    ig_tracked_text($im, 18, $margin, $footerY, IG_FONT_TERMINAL, 'FULL SCHEDULE AT CINEMATX.NET', $muted, 3);

    return $im;
}

// One film's frame under the safelight — full-bleed hero (same as Paper/
// Zine/Newsprint/Neon, unlike Terminal's bottom-anchored band), sprocket
// edges running the full height on top of everything including the hero,
// and rubber-stamped rectangles for showtimes rather than a pill — a pill
// reads as software chrome, a bordered stamp reads as something pressed
// onto a workprint can.
function ig_build_feature_page_darkroom(array $film, $date) {
    $w = 1080;
    $h = 1350;
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);

    $bg          = ig_hex($im, '#120D0A');
    $strip       = ig_hex($im, '#1D1611');
    $ink         = ig_hex($im, '#F0E6D8');
    $amber       = ig_hex($im, '#E08A3E');
    $rust        = ig_hex($im, '#B33B1E');
    $muted       = ig_hex($im, '#8A7D6E');
    $placeholder = ig_hex($im, '#241C15');
    $amberGlow   = imagecolorallocatealpha($im, 0xE0, 0x8A, 0x3E, 105);

    imagefill($im, 0, 0, $bg);

    $margin = 100;
    $heroH  = 650;

    $heroUrl = ig_hero_url($film['poster']);
    $hero = ig_fetch_thumb($heroUrl, $w, $heroH, ig_poster_crop_bias($heroUrl));
    if ($hero) {
        imagecopy($im, $hero, 0, 0, 0, 0, $w, $heroH);
        imagedestroy($hero);
    } else {
        imagefilledrectangle($im, 0, 0, $w, $heroH, $placeholder);
        $initial = mb_strtoupper(mb_substr($film['title'], 0, 1));
        $ibox = imagettfbbox(90, 0, IG_FONT_DARKROOM_TITLE, $initial);
        $iw = $ibox[2] - $ibox[0];
        ig_neon_text($im, 90, (int) (($w - $iw) / 2), (int) ($heroH / 2) + 32, IG_FONT_DARKROOM_TITLE, $initial, $muted, $amberGlow);
    }

    $fadeH = 130;
    for ($i = 0; $i < $fadeH; $i++) {
        $alpha = (int) round(127 * (1 - $i / $fadeH));
        $band  = imagecolorallocatealpha($im, 0x12, 0x0D, 0x0A, $alpha);
        imagefilledrectangle($im, 0, $heroH - $fadeH + $i, $w, $heroH - $fadeH + $i + 1, $band);
    }

    ig_sprocket_edge($im, 0, $h, 56, $strip, $bg);
    ig_sprocket_edge($im, $w - 56, $h, 56, $strip, $bg);
    // October: a couple of pairs of eyes watching from the sprocket holes.
    $season = ig_halloween_season($date);
    if ($season) foreach ([[28, 32 + 64 * 11], [28, 32 + 64 * 17], [1052, 32 + 64 * 14]] as [$ex, $ey]) ig_sprocket_eyes($im, $ex, $ey);

    // No "CINEMA, TX" wordmark or leader mark here — full-color hero art
    // underneath means no guaranteed contrast for plain text the way the
    // list page's solid background gives it. The list page (always page 1)
    // already establishes the brand mark once per carousel; this page's
    // real estate goes to the stamps, same reasoning Paper's feature page
    // already uses for the same tradeoff.
    $drawStamp = function ($im, $x, $y, $bw, $bh, $label, $color) {
        imagesetthickness($im, 2);
        imagerectangle($im, $x, $y, $x + $bw, $y + $bh, $color);
        imagesetthickness($im, 1);
        imagettftext($im, 24, 0, $x + 18, $y + $bh - 17, $color, IG_FONT_BODY, $label);
    };

    $sx = $margin;
    $sy = $heroH + 30;
    $stampH = 50;

    foreach (($film['timestamps'] ?? [$film['timestamp'] ?? null]) as $ts) {
        if (!$ts) continue;
        $label = date('g:i A', $ts);
        $box   = imagettfbbox(24, 0, IG_FONT_BODY, $label);
        $bw    = ($box[2] - $box[0]) + 36;
        $drawStamp($im, $sx, $sy, $bw, $stampH, $label, $amber);
        $sx += $bw + 16;
    }

    $textMaxWidth = $w - $margin * 2;
    $y = $sy + $stampH + 46;

    // A spotlight page has the room the compact list rows don't — full venue
    // and location both, unlike ig_build_list_page()'s own Flick Clique
    // carve-out above, which drops the venue name for lack of space.
    $kicker = strtoupper($film['venue'] ?? '');
    if (!empty($film['location'])) $kicker .= ' - ' . strtoupper($film['location']);
    if ($kicker !== '') {
        imagettftext($im, 24, 0, $margin, $y, $amber, IG_FONT_BODY, $kicker);
        $y += 66;
    }

    $titleFit = ig_fit_title_wrapped(mb_strtoupper($film['title']), IG_FONT_DARKROOM_TITLE, 50, $textMaxWidth, 2, 62);
    foreach ($titleFit['lines'] as $line) {
        ig_neon_text($im, $titleFit['size'], $margin, $y, IG_FONT_DARKROOM_TITLE, $line, $ink, $amberGlow);
        $y += $titleFit['lineHeight'];
    }

    $deckParts = [];
    if (!empty($film['year']))    $deckParts[] = $film['year'];
    if (!empty($film['genres']))  $deckParts[] = $film['genres'];
    if (!empty($film['runtime'])) $deckParts[] = round($film['runtime']) . ' min';
    if ($deckParts) {
        imagettftext($im, 24, 0, $margin, $y, $muted, IG_FONT_BODY, implode('  ·  ', $deckParts));
        $y += 44;
    }

    $y += 16;
    ig_dashed_line($im, $margin, $w - $margin, $y, $rust, 12, 8);
    $y += 36;

    if (!empty($film['overview'])) {
        foreach (ig_wrap_lines($film['overview'], IG_FONT_BODY, 26, $textMaxWidth, 4) as $line) {
            imagettftext($im, 26, 0, $margin, $y, $ink, IG_FONT_BODY, $line);
            $y += 38;
        }
    }

    $footerY = $h - 60;
    if (!empty($film['director'])) {
        imagettftext($im, 22, 0, $margin, $footerY - 34, $muted, IG_FONT_BODY, 'dir. ' . $film['director']);
    }
    imagettftext($im, 22, 0, $margin, $footerY, $muted, IG_FONT_BODY, 'Full schedule at cinematx.net');

    // October: the little film in the footer's empty right side, caught on a
    // different frame for each film (the countdown, the ghost far off or close)
    // — so swiping through a carousel is, a little, running the reel.
    if ($season) {
        $frames = [1, 2, 3, 4, 5, 6, 7];
        ig_darkroom_reel($im, 600, 1226, 980, 1304, (float) $frames[crc32((string) ($film['title'] ?? '')) % count($frames)]);
    }

    return $im;
}

// Evening in Austin, after-dark half — the same setting sun the list page
// uses, further along the sunset: a full-color poster hero (no tint, same
// call every theme makes now) fades into a two-stage dusk gradient — deep
// indigo down to a thin warm afterglow at the bottom, the last of the
// sunset the list page already showed in full. A few bats fly past the
// sun; they only ever appear here, never on the golden-hour list page —
// dusk is when they fly.
function ig_build_feature_page_austin(array $film, $date) {
    $w = 1080;
    $h = 1350;
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);

    $nightTop    = ig_hex($im, '#160F24');
    $nightMid    = ig_hex($im, '#341A2E');
    $horizonGlow = ig_hex($im, '#E0803F');
    $nightGround = ig_hex($im, '#1B0F1F');
    $ink         = ig_hex($im, '#F5EFE3');
    $muted       = ig_hex($im, '#B29E8C');
    $terracotta  = ig_hex($im, '#E8935A');
    $divider     = ig_hex($im, '#3E2A38');
    $placeholder = ig_hex($im, '#241826');
    $dark        = $nightGround;

    imagefill($im, 0, 0, $nightGround);

    $margin = 80;
    $heroH  = 650;

    $heroUrl = ig_hero_url($film['poster']);
    $hero = ig_fetch_thumb($heroUrl, $w, $heroH, ig_poster_crop_bias($heroUrl));
    if ($hero) {
        imagecopy($im, $hero, 0, 0, 0, 0, $w, $heroH);
        imagedestroy($hero);
    } else {
        imagefilledrectangle($im, 0, 0, $w, $heroH, $placeholder);
        $initial = mb_strtoupper(mb_substr($film['title'], 0, 1));
        $ibox = imagettfbbox(120, 0, IG_FONT_HEADLINE, $initial);
        $iw = $ibox[2] - $ibox[0];
        imagettftext($im, 120, 0, (int) (($w - $iw) / 2), (int) ($heroH / 2) + 40, $muted, IG_FONT_HEADLINE, $initial);
    }

    $fadeH = 130;
    for ($i = 0; $i < $fadeH; $i++) {
        $alpha = (int) round(127 * (1 - $i / $fadeH));
        $band  = imagecolorallocatealpha($im, 0x16, 0x0F, 0x24, $alpha);
        imagefilledrectangle($im, 0, $heroH - $fadeH + $i, $w, $heroH - $fadeH + $i + 1, $band);
    }

    $pillFont = 28;
    $pillPadX = 28;
    $pillH    = 56;
    $py       = 60;
    foreach (($film['timestamps'] ?? [$film['timestamp'] ?? null]) as $ts) {
        if (!$ts) continue;
        $label = date('g:i A', $ts);
        $box   = imagettfbbox($pillFont, 0, IG_FONT_BODY, $label);
        $tw    = $box[2] - $box[0];
        ig_pill($im, $margin, $py, $margin + $tw + $pillPadX * 2, $py + $pillH, $terracotta);
        imagettftext($im, $pillFont, 0, $margin + $pillPadX, $py + $pillH - 16, $dark, IG_FONT_BODY, $label);
        $py += $pillH + 12;
    }

    // The dusk band — an accent strip, not a full section: the same
    // setting sun the list page uses, further along the sunset, indigo at
    // the top warming to a thin amber afterglow at the bottom. Bats only
    // ever appear here, never on the golden-hour list page — dusk is when
    // they fly.
    $bandY1 = $heroH;
    $bandY2 = $heroH + 110;
    $midY   = $bandY1 + 75;
    ig_gradient_fill($im, 0, $bandY1, $w - 1, $midY, $nightTop, $nightMid);
    ig_gradient_fill($im, 0, $midY, $w - 1, $bandY2, $nightMid, $horizonGlow);

    $silhouette = ig_hex($im, '#140A12');
    ig_glow_sun($im, (int) ($w / 2), $bandY2 - 25, 20, ig_hex($im, '#FFF3D6'), $horizonGlow);
    ig_bat_mark($im, (int) ($w / 2) - 120, $bandY1 + 40, 22, $silhouette);
    ig_bat_mark($im, (int) ($w / 2) + 95, $bandY1 + 55, 18, $silhouette);
    ig_bat_mark($im, (int) ($w / 2) - 35, $bandY1 + 24, 15, $silhouette);

    imagefilledrectangle($im, 0, $bandY2, $w, $h, $nightGround);

    $textMaxWidth = $w - $margin * 2;
    $y = $bandY2 + 50;

    // A spotlight page has the room the compact list rows don't — full venue
    // and location both, unlike ig_build_list_page()'s own Flick Clique
    // carve-out above, which drops the venue name for lack of space.
    $kicker = strtoupper($film['venue'] ?? '');
    if (!empty($film['location'])) $kicker .= ' - ' . strtoupper($film['location']);
    if ($kicker !== '') {
        imagettftext($im, 24, 0, $margin, $y, $terracotta, IG_FONT_BODY, $kicker);
        $y += 66;
    }

    $titleFit = ig_fit_title_wrapped(mb_strtoupper($film['title']), IG_FONT_HEADLINE, 52, $textMaxWidth, 2, 58);
    foreach ($titleFit['lines'] as $line) {
        imagettftext($im, $titleFit['size'], 0, $margin, $y, $ink, IG_FONT_HEADLINE, $line);
        $y += $titleFit['lineHeight'];
    }

    // Live-score/presented-with-or-by billing, same quiet treatment Neon's
    // spotlight page established — plain text, no glow, right under the
    // title.
    if (!empty($film['billing'])) {
        imagettftext($im, 20, 0, $margin, $y, $muted, IG_FONT_BODY, ig_fit_text($film['billing'], IG_FONT_BODY, 20, $textMaxWidth));
        $y += 32;
    }

    $deckParts = [];
    if (!empty($film['year']))    $deckParts[] = $film['year'];
    if (!empty($film['genres']))  $deckParts[] = $film['genres'];
    if (!empty($film['runtime'])) $deckParts[] = round($film['runtime']) . ' min';
    if ($deckParts) {
        imagettftext($im, 24, 0, $margin, $y, $muted, IG_FONT_BODY, implode('  ·  ', $deckParts));
        $y += 40;
    }

    $y += 20;
    imagefilledrectangle($im, $margin, $y, $w - $margin, $y + 1, $divider);
    $y += 36;

    if (!empty($film['overview'])) {
        foreach (ig_wrap_lines($film['overview'], IG_FONT_BODY, 26, $textMaxWidth, 4) as $line) {
            imagettftext($im, 26, 0, $margin, $y, $ink, IG_FONT_BODY, $line);
            $y += 38;
        }
    }

    $footerY = $h - 60;
    if (!empty($film['director'])) {
        imagettftext($im, 22, 0, $margin, $footerY - 34, $muted, IG_FONT_BODY, 'dir. ' . $film['director']);
    }
    imagettftext($im, 22, 0, $margin, $footerY, $muted, IG_FONT_BODY, 'Full schedule at cinematx.net');

    return $im;
}

/**
 * Writes one PNG per page as ig-YYYY-MM-DD-1.png, -2.png, … and returns
 * [[filesystem path, root-relative URL], …] in page order — a single-image
 * Default-mode day is just an array of one.
 *
 * Root-relative, deliberately. This used to return an absolute URL built from
 * CTX_SITE_URL, which is https://cinematx.net in every environment because
 * that is what the Graph API has to fetch. The admin preview then rendered
 * production's copy of the card rather than the one the local machine had
 * just written — so development showed a stale design and looked like the
 * generator had drifted. Display and publication want different URLs; only
 * publication wants the absolute one, and it asks for it explicitly (see
 * ig_public_url()).
 */
function ig_save_images(array $gdImages, $date) {
    $dir = dirname(__DIR__) . '/uploads/social';
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    $out = [];
    foreach ($gdImages as $i => $im) {
        $name = 'ig-' . date('Y-m-d', $date) . '-' . ($i + 1) . '.png';
        $path = $dir . '/' . $name;
        imagepng($im, $path);
        imagedestroy($im);
        $out[] = [$path, '/uploads/social/' . $name];
    }

    // Stills and the animation files that sit beside them (ig-<date>-<n>.mp4
    // plus its .key, .lock and any leftover .part.mp4) age out together.
    foreach (glob($dir . '/ig-*.{png,mp4,mp4.key,mp4.lock}', GLOB_BRACE) as $old) {
        if (filemtime($old) < time() - 7 * 86400) unlink($old);
    }

    return $out;
}

/**
 * The absolute, publicly reachable form of a root-relative upload URL.
 *
 * Only the Graph API needs this: Meta fetches the image over the internet, so
 * "/uploads/social/x.png" is meaningless to it. Returns '' when CTX_SITE_URL
 * is unset, and callers treat that as "not configured to post".
 *
 * Carries the same mtime cache-buster the admin mockup already uses (pass
 * the file's local path as $path), for the same reason: every day reuses
 * the exact same filename, so with a bare URL, anything that caches by URL —
 * and Meta's fetcher is exactly that kind of thing — can serve back whatever
 * it fetched the *first* time that path was ever posted, not what's on disk
 * now. Discovered when a repeat-tested carousel's last image published with
 * stale (pre-Marquee) content despite the current file being correct.
 */
function ig_public_url($relative, $path = null) {
    if (!defined('CTX_SITE_URL') || !CTX_SITE_URL) return '';
    $url = rtrim(CTX_SITE_URL, '/') . $relative;
    if ($path && file_exists($path)) $url .= '?v=' . filemtime($path);
    return $url;
}

// ── Caption ──────────────────────────────────────────────────────────────

function ig_build_caption(array $films, $date) {
    $lines = ['Today in Austin — ' . date('l, F j', $date), ''];

    foreach ($films as $film) {
        $venue = $film['location'] ? "{$film['venue']} ({$film['location']})" : $film['venue'];
        $lines[] = '• ' . mb_strtoupper($film['title']) . ' — ' . $venue . ' @ ' . ig_format_times($film['timestamps'] ?? [$film['timestamp']]);
    }

    $lines[] = '';
    $lines[] = 'Full schedule & tickets: cinematx.net';

    return implode("\n", $lines);
}

// Instagram rejects anything past this at publish time.
const IG_CAPTION_MAX = 2200;

/**
 * The caption that will actually post today: a saved override if the admin
 * page has one, otherwise the generated default. Every reader — admin
 * preview, dashboard peek, and the cron job — goes through this, so an edit
 * made in the morning is what posts that evening rather than being read only
 * by whichever page happens to regenerate it.
 */
function ig_caption(array $films, $date) {
    $override = dirname(__DIR__) . '/uploads/social/caption-' . date('Y-m-d', $date) . '.txt';
    if (file_exists($override)) {
        $saved = trim(file_get_contents($override));
        if ($saved !== '') return $saved;
    }
    return ig_build_caption($films, $date);
}

// ── Composition ──────────────────────────────────────────────────────────

// Absent compose-<date>.json means this: Auto mode with spotlight panels on,
// that day's scheduled theme (ig_theme_for_date()). New days behave this way
// until someone opens the admin page and changes something — the literal
// 'default'/false/'paper' here only ever matter as ig_compose_write()'s
// last-resort fallback for a POST that somehow omitted a field, which the
// admin form never does.
const IG_COMPOSE_DEFAULT = ['mode' => 'auto', 'per_page' => null, 'features' => true, 'theme' => 'paper'];

// Screenings-per-page when Auto mode (or Manual with no count set) is
// active — today's exact row height, so a single-page Auto day looks
// identical to a Default one.
const IG_AUTO_MAX_PER_PAGE = 10;

function ig_compose_path($date) {
    return dirname(__DIR__) . '/uploads/social/compose-' . date('Y-m-d', $date) . '.json';
}

// Same shape as IG_COMPOSE_DEFAULT, but with that day's scheduled theme
// instead of a fixed one — the actual default a fresh, never-saved date
// reads as.
function ig_compose_default($date) {
    return ['theme' => ig_theme_for_date($date)] + IG_COMPOSE_DEFAULT;
}

function ig_compose_read($date) {
    $file    = ig_compose_path($date);
    $default = ig_compose_default($date);
    if (!file_exists($file)) return $default;
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data + $default : $default;
}

function ig_compose_write($date, array $compose) {
    $dir = dirname(__DIR__) . '/uploads/social';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    file_put_contents(ig_compose_path($date), json_encode($compose + IG_COMPOSE_DEFAULT));
}

// Which films are on today's carousel — which ones get their own spotlight
// page, out of Instagram's shared 10-image-per-post budget (list pages +
// spotlight pages together). Keyed by ig_film_key() rather than array
// position, so it survives the scrape re-running with a different film
// order. Unlike every other per-day override file, absent here does NOT
// mean "empty" — it means "no override yet," which ig_carousel_selection()
// tells apart from an explicit empty save (nobody wants a single spotlight
// page today) by returning null instead of [].
function ig_carousel_path($date) {
    return dirname(__DIR__) . '/uploads/social/carousel-' . date('Y-m-d', $date) . '.json';
}

function ig_carousel_read($date) {
    $file = ig_carousel_path($date);
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function ig_carousel_write($date, array $keys) {
    $dir = dirname(__DIR__) . '/uploads/social';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    file_put_contents(ig_carousel_path($date), json_encode(array_values($keys)));
}

// How many of the carousel's 10 images are actually available for spotlight
// pages once the list pages this composition needs are accounted for.
// Default mode never has spotlight pages at all (ig_build_images() returns
// before ever looking at $compose['features']), so it's always 0 slots
// there regardless of film count.
function ig_carousel_slots(array $films, array $compose) {
    if ($compose['mode'] === 'default' || empty($films)) return 0;
    $maxPerPage = ($compose['mode'] === 'manual' && !empty($compose['per_page']))
        ? max(1, (int) $compose['per_page'])
        : IG_AUTO_MAX_PER_PAGE;
    return max(0, 10 - count(ig_paginate($films, $maxPerPage)));
}

/**
 * The film keys actually on today's carousel: an explicit saved list if the
 * admin has touched the checklist (however many or few that is — even an
 * empty save is honored, meaning "no spotlight pages today"), otherwise the
 * first however-many-slots-fit films in chronological order — exactly what
 * an untouched day has always rendered, now made adjustable instead of
 * automatic-only. $films is expected already chronological (ig_today_films()
 * always returns it that way), so slicing it keeps that order.
 */
function ig_carousel_selection(array $films, array $compose, $date) {
    $saved = ig_carousel_read($date);
    if ($saved !== null) return $saved;
    $slots = ig_carousel_slots($films, $compose);
    return array_map('ig_film_key', array_slice($films, 0, $slots));
}

// Which Alamo films today's admin opted into the card — same read/write
// shape as the carousel list, but keyed by ig_alamo_key() (title only)
// rather than ig_film_key(), since the checklist itself is one row per
// title. Returns null when nobody's ever saved this date's checklist
// (distinct from an empty array, an explicit save of zero) — same
// null-means-untouched contract as ig_carousel_read(), so
// ig_alamo_selected_keys() below can tell "never touched" from "chose
// none" the same way ig_carousel_selection() already does for the
// carousel.
function ig_alamo_path($date) {
    return dirname(__DIR__) . '/uploads/social/alamo-' . date('Y-m-d', $date) . '.json';
}

function ig_alamo_read($date) {
    $file = ig_alamo_path($date);
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

// The two Alamo screenings playing latest tonight, ranked by each film's
// own last showtime — one row per film (ig_alamo_films() already folds a
// title's repeat showings into one row), not per individual showtime, so a
// single film with two late showtimes only ever fills one of the two slots.
const IG_ALAMO_DEFAULT_COUNT = 2;

function ig_alamo_default_keys(array $films) {
    usort($films, fn($a, $b) => max($b['timestamps']) <=> max($a['timestamps']));
    return array_map('ig_alamo_key', array_slice($films, 0, IG_ALAMO_DEFAULT_COUNT));
}

// Whichever Alamo keys are actually in play for $date: the admin's own
// saved checklist once one exists (even an explicit save of zero honors
// that), otherwise ig_alamo_default_keys()'s pick. The one thing both
// ig_today_films() and the admin checklist page (whose checkboxes read
// straight off this) go through, so what shows checked always matches
// what would actually post — no separate "defaulted" state to track or
// display, it just reads as already checked.
function ig_alamo_selected_keys($date, array $films) {
    $saved = ig_alamo_read($date);
    return $saved !== null ? $saved : ig_alamo_default_keys($films);
}

function ig_alamo_write($date, array $keys) {
    $dir = dirname(__DIR__) . '/uploads/social';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    file_put_contents(ig_alamo_path($date), json_encode(array_values($keys)));
}

// Which arthouse films today's admin opted *out* of the card — the mirror
// of Alamo's opt-in list above, keyed by ig_film_key() same as the carousel
// list, since this checklist is the same one-row-per-venue-screening
// granularity. Empty/absent means nothing excluded, i.e. today's default:
// every arthouse screening included.
function ig_excluded_path($date) {
    return dirname(__DIR__) . '/uploads/social/excluded-' . date('Y-m-d', $date) . '.json';
}

function ig_excluded_read($date) {
    $file = ig_excluded_path($date);
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function ig_excluded_write($date, array $keys) {
    $dir = dirname(__DIR__) . '/uploads/social';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    file_put_contents(ig_excluded_path($date), json_encode(array_values($keys)));
}

/**
 * Renders today's screenings into one or more GD images per the saved (or
 * default) composition choice — the single place all three callers (admin
 * preview, the post handler, and the cron job) go through, so what gets
 * approved is what gets posted.
 *
 * Default mode: always one image, ig_build_list_page()'s own "+N more"
 * truncation, unchanged from before composition modes existed.
 *
 * Auto/Manual: ig_paginate() splits every screening across list pages (never
 * dropped, never a stranded last page), then — if the features toggle is on
 * — spends whatever carousel room is left (up to Instagram's 10-image cap)
 * on one feature page per film in ig_carousel_selection()'s order, stopping
 * when that selection or the cap runs out.
 */
function ig_build_images(array $films, $date, array $compose) {
    $images = [];
    foreach (ig_plan_pages($films, $date, $compose) as $p) {
        $images[] = $p['type'] === 'list'
            ? ig_build_list_page($p['films'], $date, $p['theme'], $p['moreCount'])
            : ig_build_feature_page($p['film'], $date, $p['theme']);
    }
    return $images;
}

/**
 * Which pages a composition produces, as descriptors rather than images —
 * [['type' => 'list', 'films' => [...], 'moreCount' => n, 'theme' => t] or
 * ['type' => 'feature', 'film' => [...], 'theme' => t], …] in carousel
 * order. ig_build_images() renders exactly this, and the animation code
 * reads it too, so the two can never disagree about how a day is paginated
 * or which slides are list pages.
 */
function ig_plan_pages(array $films, $date, array $compose) {
    $theme = in_array($compose['theme'] ?? 'paper', array_keys(IG_THEMES), true) ? $compose['theme'] : 'paper';

    if ($compose['mode'] === 'default' || empty($films)) {
        return [['type' => 'list', 'films' => $films, 'moreCount' => 0, 'theme' => $theme]];
    }

    $maxPerPage = ($compose['mode'] === 'manual' && !empty($compose['per_page']))
        ? max(1, (int) $compose['per_page'])
        : IG_AUTO_MAX_PER_PAGE;

    $plan     = [];
    $consumed = 0;
    foreach (ig_paginate($films, $maxPerPage) as $page) {
        $consumed += count($page);
        $moreCount = count($films) - $consumed; // 0 on the last list page
        $plan[]    = ['type' => 'list', 'films' => $page, 'moreCount' => $moreCount, 'theme' => $theme];
    }

    if (!empty($compose['features'])) {
        // $films is already chronological, so filtering it down to the
        // carousel selection keeps that order — a checked-but-late film
        // still yields its slot to an earlier one if there isn't room for
        // both, same as an untouched day's default already would.
        $selected = ig_carousel_selection($films, $compose, $date);
        $chosen   = array_values(array_filter($films, fn($f) => in_array(ig_film_key($f), $selected, true)));

        $slotsLeft = 10 - count($plan);
        foreach ($chosen as $film) {
            if ($slotsLeft <= 0) break;
            $plan[] = ['type' => 'feature', 'film' => $film, 'theme' => $theme];
            $slotsLeft--;
        }
    }

    return $plan;
}

// ── Graph API ────────────────────────────────────────────────────────────

function ig_graph_call($url, array $fields, $method = 'GET') {
    // A test seam, never set in production: a callable in
    // $GLOBALS['IG_GRAPH_STUB'] stands in for Meta so the publish logic —
    // and above all its video fallbacks, which can't be rehearsed against
    // the live API without risking a real post — can be exercised locally.
    if (!empty($GLOBALS['IG_GRAPH_STUB'])) {
        return ($GLOBALS['IG_GRAPH_STUB'])($url, $fields, $method);
    }
    $ch = curl_init();
    if ($method === 'GET') {
        curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($fields));
    } else {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'CinemaTX/1.0 (+https://cinematx.net)',
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response ? json_decode($response, true) : null;
}

/**
 * Creates a media container and polls until Meta finishes processing it.
 * Shared by the single-image path, every child of a carousel, and Reels
 * (ig_publish_reel() in list/forecast.php). Returns the container id, or
 * throws with whatever error payload Meta returned.
 *
 * $maxAttempts/$sleepSecs default to 10×2s (20s), plenty for an image.
 * Video processing can run well past that for a several-minute Reel, so
 * the Reels path passes a much longer budget explicitly rather than
 * changing the default every other caller relies on.
 */
function ig_create_container($base, $ig, array $fields, $maxAttempts = 10, $sleepSecs = 2) {
    $create = ig_graph_call("$base/$ig/media", $fields + ['access_token' => IG_ACCESS_TOKEN], 'POST');
    if (empty($create['id'])) {
        throw new RuntimeException('IG container creation failed: ' . json_encode($create));
    }
    $container_id = $create['id'];

    for ($i = 0; $i < $maxAttempts; $i++) {
        $status = ig_graph_call("$base/$container_id", [
            'fields'       => 'status_code',
            'access_token' => IG_ACCESS_TOKEN,
        ]);
        $code = $status['status_code'] ?? null;
        if ($code === 'FINISHED') return $container_id;
        if ($code === 'ERROR') {
            throw new RuntimeException('IG container failed to process: ' . json_encode($status));
        }
        sleep($sleepSecs);
    }

    throw new RuntimeException("IG container $container_id never finished processing after "
        . ($maxAttempts * $sleepSecs) . 's — it may still complete on Meta\'s side; check the container status manually before retrying.');
}

/**
 * The CAROUSEL parent container for $pages, children first. With $useVideo, a
 * page carrying a 'video' gets a video child — and if that one child fails it
 * is replaced by the page's still, so one bad video costs an animation, not a
 * slide. Without it (or on a page with no video) every child is an image,
 * exactly the requests the carousel has always made.
 */
function ig_carousel_container($base, $ig, array $pages, $caption, $useVideo) {
    $children   = [];
    $anyVideo   = false;
    foreach ($pages as $i => $page) {
        $video = $useVideo ? ($page['video'] ?? null) : null;
        if ($video) {
            try {
                [$videoPath, $videoUrl] = $video;
                $video_url = ig_public_url($videoUrl, $videoPath);
                if ($video_url === '') {
                    throw new RuntimeException('CTX_SITE_URL is not set, so Meta has no address to fetch the video from.');
                }
                $children[] = ig_create_container($base, $ig, [
                    'media_type'       => 'VIDEO',
                    'video_url'        => $video_url,
                    'is_carousel_item' => 'true',
                ], IG_VIDEO_POLL_ATTEMPTS, IG_VIDEO_POLL_SLEEP);
                $anyVideo = true;
                continue;
            } catch (Throwable $e) {
                error_log('ig: video for slide ' . ($i + 1) . ' failed (' . $e->getMessage() . '), using the still');
            }
        }

        [$path, $url] = $page;
        $image_url = ig_public_url($url, $path);
        if ($image_url === '') {
            throw new RuntimeException('CTX_SITE_URL is not set, so Meta has no address to fetch the card from.');
        }
        try {
            $children[] = ig_create_container($base, $ig, [
                'image_url'        => $image_url,
                'is_carousel_item' => 'true',
            ]);
        } catch (RuntimeException $e) {
            throw new RuntimeException('Carousel image ' . ($i + 1) . ' of ' . count($pages) . ' failed: ' . $e->getMessage());
        }
    }

    // With a video among the children the parent can take longer to settle.
    return $anyVideo
        ? ig_create_container($base, $ig, [
            'media_type' => 'CAROUSEL',
            'children'   => implode(',', $children),
            'caption'    => $caption,
        ], IG_VIDEO_POLL_ATTEMPTS, IG_VIDEO_POLL_SLEEP)
        : ig_create_container($base, $ig, [
            'media_type' => 'CAROUSEL',
            'children'   => implode(',', $children),
            'caption'    => $caption,
        ]);
}

/**
 * Container(s) → poll → publish. Returns the published media id, or throws
 * with whatever Meta's error payload said.
 *
 * Takes the [path, url] pairs ig_save_images() returns — the local path is
 * needed here, not just the URL, so each image can carry ig_public_url()'s
 * mtime cache-buster. Without it, every day reposts the exact same URL
 * (ig-<date>-<n>.png), and a fetcher that caches by URL — Meta's included —
 * can serve back whatever it fetched on an earlier test rather than today's
 * actual content. A single pair publishes as one image, same as always; an
 * array of 2+ publishes as a carousel — each becomes a child container
 * (polled individually, so a failure names which image it was) before the
 * CAROUSEL parent is created and published. Either way this is one post
 * against the rate limit.
 */
function ig_publish(array $pages, $caption) {
    $base  = 'https://graph.facebook.com/' . IG_GRAPH_VERSION;
    $ig    = IG_BUSINESS_ACCOUNT_ID;
    $pages = array_values($pages);

    if (count($pages) === 1) {
        [$path, $url] = $pages[0];
        $image_url = ig_public_url($url, $path);
        if ($image_url === '') {
            throw new RuntimeException('CTX_SITE_URL is not set, so Meta has no address to fetch the card from.');
        }
        $container_id = ig_create_container($base, $ig, [
            'image_url' => $image_url,
            'caption'   => $caption,
        ]);
    } else {
        // A page may carry a 'video' => [path, url] beside its still (see
        // ig_attach_animations()). The animation is an upgrade, never a
        // requirement: if building the carousel with videos fails for any
        // reason, it is rebuilt from the stills alone. This retry covers
        // only container *creation*, which publishes nothing; the one
        // media_publish call below is never repeated, so a fallback can't
        // double-post.
        $hasVideo = false;
        foreach ($pages as $page) {
            if (!empty($page['video'])) $hasVideo = true;
        }
        try {
            $container_id = ig_carousel_container($base, $ig, $pages, $caption, true);
        } catch (Throwable $e) {
            if (!$hasVideo) throw $e;
            error_log('ig: carousel with video failed (' . $e->getMessage() . '), retrying with stills only');
            $container_id = ig_carousel_container($base, $ig, $pages, $caption, false);
        }
    }

    $publish = ig_graph_call("$base/$ig/media_publish", [
        'creation_id'  => $container_id,
        'access_token' => IG_ACCESS_TOKEN,
    ], 'POST');

    if (empty($publish['id'])) {
        throw new RuntimeException('IG publish failed: ' . json_encode($publish));
    }

    return $publish['id'];
}
