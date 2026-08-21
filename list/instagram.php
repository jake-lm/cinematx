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

// The three arthouse venues this post is meant to promote — not the chains
// (Alamo Drafthouse, Fathom Events) and not member-submitted `events`. Alamo
// is still selectable on a per-day basis, though — see ig_alamo_films().
const IG_VENUES = ['Austin Film Society', 'Paramount Theatre', 'Hyperreal Film Club'];
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
    $films = array_values(array_filter($films, fn($f) => in_array($f['venue'], IG_VENUES, true)));
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
    // the admin checklist — fold in whichever ones were checked. Only
    // touches ig_alamo_films() (a second scrape-cache read, see
    // list/cache.php — cheap, not a live re-scrape) when there's actually a
    // selection to honor, so a day nobody checks anything on costs nothing.
    $alamoSelected = ig_alamo_read($start);
    if ($alamoSelected) {
        $alamo = array_values(array_filter(
            ig_alamo_films($conn, $start),
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
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_USERAGENT      => 'CinemaTX/1.0 (+https://cinematx.net)',
        ]);
        $data = curl_exec($ch);
        curl_close($ch);
        if (!$data) return null;
        file_put_contents($cacheFile, $data);
    }

    $src = @imagecreatefromstring(file_get_contents($cacheFile));
    if (!$src) { @unlink($cacheFile); return null; }

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    $thumb = imagecreatetruecolor($w, $h);

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

// ── Themes ───────────────────────────────────────────────────────────────
//
// Every theme draws its own complete list page and feature page — no shared
// "layout with a color map" abstraction. The two designs are meant to look
// like different things, not the same skeleton recolored, and a self-
// contained function per theme is easier to read and safer to touch than
// unpicking a color parameter passed through several drawing calls. Row
// geometry (thumbnail size, row height, the 6-per-page budget Auto mode
// assumes) stays identical across themes for now, so composition modes and
// pagination don't need to know which theme is active.
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
function ig_build_list_page(array $films, $date, $theme = 'paper', $moreCount = 0) {
    switch ($theme) {
        case 'marquee':   return ig_build_list_page_marquee($films, $date, $moreCount);
        case 'zine':      return ig_build_list_page_zine($films, $date, $moreCount);
        case 'newsprint': return ig_build_list_page_newsprint($films, $date, $moreCount);
        case 'neon':      return ig_build_list_page_neon($films, $date, $moreCount);
        case 'terminal':  return ig_build_list_page_terminal($films, $date, $moreCount);
        case 'darkroom':  return ig_build_list_page_darkroom($films, $date, $moreCount);
        case 'austin':    return ig_build_list_page_austin($films, $date, $moreCount);
        default:          return ig_build_list_page_paper($films, $date, $moreCount);
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
function ig_marquee_bulbs($im, $x1, $y1, $x2, $y2, $spacing, $glow, $bulb) {
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
        imagefilledellipse($im, (int) $x, (int) $y, 16, 16, $glow);
        imagefilledellipse($im, (int) $x, (int) $y, 7, 7, $bulb);
    }
}

function ig_build_list_page_paper(array $films, $date, $moreCount = 0) {
    $w = 1080;
    $h = 1350;
    $im = imagecreatetruecolor($w, $h);

    $paper   = ig_hex($im, '#F4F1EB');
    $red     = ig_hex($im, '#922E32');
    $ink     = ig_hex($im, '#14120F');
    $muted   = ig_hex($im, '#6B6659');
    $divider = ig_hex($im, '#DED7C7');
    $placeholder = ig_hex($im, '#E4DECE');

    imagefill($im, 0, 0, $paper);

    // Red rule under the header, same visual role as the accent bars in v7.
    imagefilledrectangle($im, 0, 0, $w, 14, $red);

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

    $thumbW = 82;
    $thumbH = 123;
    $textX  = $margin + $thumbW + 28;
    $textMaxWidth = $w - $margin - $textX;

    // Footer sits at a fixed baseline near the bottom, so rows must stop
    // early enough to leave room for it (plus the "+N more" line above it) —
    // otherwise a long list runs straight through the footer text.
    $rowHeight     = $thumbH + 30;
    $footerY       = $h - 60;
    $rowsAreaEnd   = $footerY - 70;
    $maxRows       = max(1, (int) floor(($rowsAreaEnd - $y) / $rowHeight));

    $rows  = array_slice($films, 0, $maxRows);
    $extra = count($films) - count($rows);

    if (empty($films)) {
        imagettftext($im, 28, 0, $margin, $y, $muted, IG_FONT_BODY, 'Nothing scraped for today — check back later.');
    }

    $lastIndex = count($rows) - 1;
    foreach ($rows as $i => $film) {
        $thumb = ig_fetch_thumb($film['poster'], $thumbW, $thumbH);
        if ($thumb) {
            imagecopy($im, $thumb, $margin, $y, 0, 0, $thumbW, $thumbH);
            imagedestroy($thumb);
        } else {
            imagefilledrectangle($im, $margin, $y, $margin + $thumbW, $y + $thumbH, $placeholder);
            $initial = mb_strtoupper(mb_substr($film['title'], 0, 1));
            $ibox = imagettfbbox(36, 0, IG_FONT_HEADLINE, $initial);
            $iw = $ibox[2] - $ibox[0];
            imagettftext($im, 36, 0, (int) ($margin + ($thumbW - $iw) / 2), $y + (int) ($thumbH / 2) + 12, $muted, IG_FONT_HEADLINE, $initial);
        }

        $title = ig_fit_text(mb_strtoupper($film['title']), IG_FONT_HEADLINE, 32, $textMaxWidth);
        imagettftext($im, 32, 0, $textX, $y + 40, $ink, IG_FONT_HEADLINE, $title);

        $venue = $film['location'] ? "{$film['venue']} — {$film['location']}" : $film['venue'];
        if ($film['director']) $venue .= '  ·  dir. ' . $film['director'];
        $meta  = ig_fit_text($venue, IG_FONT_BODY, 22, $textMaxWidth);
        imagettftext($im, 22, 0, $textX, $y + 74, $muted, IG_FONT_BODY, $meta);

        $time = ig_fit_text(ig_format_times($film['timestamps'] ?? [$film['timestamp']]), IG_FONT_BODY, 22, $textMaxWidth);
        imagettftext($im, 22, 0, $textX, $y + 104, $red, IG_FONT_BODY, $time);

        $y += $rowHeight;
        if ($i < $lastIndex) {
            imagefilledrectangle($im, $margin, $y - 15, $w - $margin, $y - 14, $divider);
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
// (thumbnail size, row height, footer position) is identical to the paper
// theme on purpose — only the palette and the bulb frame change, so this
// stays a drop-in for the same pagination math.
function ig_build_list_page_marquee(array $films, $date, $moreCount = 0) {
    $w = 1080;
    $h = 1350;
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);

    $bg          = ig_hex($im, '#14120F');
    $ink         = ig_hex($im, '#F2EEE5');
    $muted       = ig_hex($im, '#B5AFA0');
    $divider     = ig_hex($im, '#3A362E');
    $placeholder = ig_hex($im, '#2A2620');
    $gold        = ig_hex($im, '#F2C14E');
    $goldGlow    = imagecolorallocatealpha($im, 0xF2, 0xC1, 0x4E, 100);

    imagefill($im, 0, 0, $bg);
    ig_marquee_bulbs($im, 28, 28, $w - 28, $h - 28, 40, $goldGlow, $gold);

    $margin = 80;

    $y = 150;
    imagettftext($im, 26, 0, $margin, $y, $gold, IG_FONT_BODY, strtoupper('Showing Tonight'));
    $y += 66;
    imagettftext($im, 50, 0, $margin, $y, $ink, IG_FONT_HEADLINE, date('l, F j', $date));
    $y += 50;

    imagefilledrectangle($im, $margin, $y, $w - $margin, $y + 1, $divider);
    $y += 40;

    $thumbW = 82;
    $thumbH = 123;
    $textX  = $margin + $thumbW + 28;
    $textMaxWidth = $w - $margin - $textX;

    $rowHeight   = $thumbH + 30;
    $footerY     = $h - 60;
    $rowsAreaEnd = $footerY - 70;
    $maxRows     = max(1, (int) floor(($rowsAreaEnd - $y) / $rowHeight));

    $rows  = array_slice($films, 0, $maxRows);
    $extra = count($films) - count($rows);

    if (empty($films)) {
        imagettftext($im, 28, 0, $margin, $y, $muted, IG_FONT_BODY, 'Nothing scraped for today — check back later.');
    }

    $lastIndex = count($rows) - 1;
    foreach ($rows as $i => $film) {
        $thumb = ig_fetch_thumb($film['poster'], $thumbW, $thumbH);
        if ($thumb) {
            imagecopy($im, $thumb, $margin, $y, 0, 0, $thumbW, $thumbH);
            imagedestroy($thumb);
        } else {
            imagefilledrectangle($im, $margin, $y, $margin + $thumbW, $y + $thumbH, $placeholder);
            $initial = mb_strtoupper(mb_substr($film['title'], 0, 1));
            $ibox = imagettfbbox(36, 0, IG_FONT_MARQUEE_TITLE, $initial);
            $iw = $ibox[2] - $ibox[0];
            imagettftext($im, 36, 0, (int) ($margin + ($thumbW - $iw) / 2), $y + (int) ($thumbH / 2) + 12, $gold, IG_FONT_MARQUEE_TITLE, $initial);
        }

        $title = ig_fit_text(mb_strtoupper($film['title']), IG_FONT_MARQUEE_TITLE, 32, $textMaxWidth);
        imagettftext($im, 32, 0, $textX, $y + 40, $ink, IG_FONT_MARQUEE_TITLE, $title);

        $venue = $film['location'] ? "{$film['venue']} — {$film['location']}" : $film['venue'];
        if ($film['director']) $venue .= '  ·  dir. ' . $film['director'];
        $meta  = ig_fit_text($venue, IG_FONT_BODY, 22, $textMaxWidth);
        imagettftext($im, 22, 0, $textX, $y + 74, $muted, IG_FONT_BODY, $meta);

        $time = ig_fit_text(ig_format_times($film['timestamps'] ?? [$film['timestamp']]), IG_FONT_BODY, 22, $textMaxWidth);
        imagettftext($im, 22, 0, $textX, $y + 104, $gold, IG_FONT_BODY, $time);

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
function ig_build_list_page_zine(array $films, $date, $moreCount = 0) {
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

    $y = 180;
    imagettftext($im, 28, 0, $margin, $y, $pink, IG_FONT_BODY, strtoupper('Today in Austin'));
    $y += 66;
    imagettftext($im, 50, 0, $margin, $y, $ink, IG_FONT_ZINE_TITLE, date('l, F j', $date));
    $y += 50;

    ig_torn_line($im, $margin, $w - $margin, $y, $divider);
    $y += 40;

    $thumbW = 82;
    $thumbH = 123;
    $textX  = $margin + $thumbW + 28;
    $textMaxWidth = $w - $margin - $textX;

    $rowHeight   = $thumbH + 30;
    $footerY     = $h - 60;
    $rowsAreaEnd = $footerY - 70;
    $maxRows     = max(1, (int) floor(($rowsAreaEnd - $y) / $rowHeight));

    $rows  = array_slice($films, 0, $maxRows);
    $extra = count($films) - count($rows);

    if (empty($films)) {
        imagettftext($im, 28, 0, $margin, $y, $muted, IG_FONT_BODY, 'Nothing scraped for today — check back later.');
    }

    $lastIndex = count($rows) - 1;
    foreach ($rows as $i => $film) {
        $thumb = ig_fetch_thumb($film['poster'], $thumbW, $thumbH);
        if ($thumb) {
            imagecopy($im, $thumb, $margin, $y, 0, 0, $thumbW, $thumbH);
            imagedestroy($thumb);
        } else {
            imagefilledrectangle($im, $margin, $y, $margin + $thumbW, $y + $thumbH, $placeholder);
            $initial = mb_strtoupper(mb_substr($film['title'], 0, 1));
            $ibox = imagettfbbox(36, 0, IG_FONT_ZINE_TITLE, $initial);
            $iw = $ibox[2] - $ibox[0];
            imagettftext($im, 36, 0, (int) ($margin + ($thumbW - $iw) / 2), $y + (int) ($thumbH / 2) + 12, $muted, IG_FONT_ZINE_TITLE, $initial);
        }

        $title = ig_fit_text(mb_strtoupper($film['title']), IG_FONT_ZINE_TITLE, 32, $textMaxWidth);
        imagettftext($im, 32, 0, $textX, $y + 40, $ink, IG_FONT_ZINE_TITLE, $title);

        $venue = $film['location'] ? "{$film['venue']} — {$film['location']}" : $film['venue'];
        if ($film['director']) $venue .= '  ·  dir. ' . $film['director'];
        $meta  = ig_fit_text($venue, IG_FONT_BODY, 22, $textMaxWidth);
        imagettftext($im, 22, 0, $textX, $y + 74, $muted, IG_FONT_BODY, $meta);

        $time = ig_fit_text(ig_format_times($film['timestamps'] ?? [$film['timestamp']]), IG_FONT_BODY, 22, $textMaxWidth);
        imagettftext($im, 22, 0, $textX, $y + 104, $pink, IG_FONT_BODY, $time);

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

    return $im;
}

// A real newspaper listings page: gray newsprint stock, black ink, red used
// once and sparingly (showtimes only) rather than as a running accent.
// Posters run grayscale, no color wash — actual newsprint photo
// reproduction, not a Riso tint. The wordmark is a masthead nameplate with
// a thick/thin double rule beneath it, the way a real paper's name sits
// over its own folio rule, rather than a chip or a stamp. Labels are flat
// rectangles, not rounded pills — newsprint has no rounded corners anywhere.
function ig_build_list_page_newsprint(array $films, $date, $moreCount = 0) {
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

    imagettftext($im, 34, 0, $margin, 108, $ink, IG_FONT_NEWSPRINT_TITLE, 'CINEMA, TX');
    imagefilledrectangle($im, $margin, 126, $w - $margin, 130, $ink);
    imagefilledrectangle($im, $margin, 136, $w - $margin, 137, $ink);

    $y = 182;
    imagettftext($im, 24, 0, $margin, $y, $red, IG_FONT_BODY, strtoupper('Today in Austin'));
    $y += 46;
    imagettftext($im, 34, 0, $margin, $y, $ink, IG_FONT_BODY, date('l, F j', $date));
    $y += 34;
    imagefilledrectangle($im, $margin, $y, $w - $margin, $y + 2, $ink);
    $y += 38;

    $thumbW = 82;
    $thumbH = 123;
    $textX  = $margin + $thumbW + 28;
    $textMaxWidth = $w - $margin - $textX;

    $rowHeight   = $thumbH + 30;
    $footerY     = $h - 60;
    $rowsAreaEnd = $footerY - 70;
    $maxRows     = max(1, (int) floor(($rowsAreaEnd - $y) / $rowHeight));

    $rows  = array_slice($films, 0, $maxRows);
    $extra = count($films) - count($rows);

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
            $ibox = imagettfbbox(36, 0, IG_FONT_NEWSPRINT_TITLE, $initial);
            $iw = $ibox[2] - $ibox[0];
            imagettftext($im, 36, 0, (int) ($margin + ($thumbW - $iw) / 2), $y + (int) ($thumbH / 2) + 12, $muted, IG_FONT_NEWSPRINT_TITLE, $initial);
        }

        $title = ig_fit_text(mb_strtoupper($film['title']), IG_FONT_NEWSPRINT_TITLE, 32, $textMaxWidth);
        imagettftext($im, 32, 0, $textX, $y + 40, $ink, IG_FONT_NEWSPRINT_TITLE, $title);

        $venue = $film['location'] ? "{$film['venue']} — {$film['location']}" : $film['venue'];
        if ($film['director']) $venue .= '  ·  dir. ' . $film['director'];
        $meta  = ig_fit_text($venue, IG_FONT_BODY, 22, $textMaxWidth);
        imagettftext($im, 22, 0, $textX, $y + 74, $muted, IG_FONT_BODY, $meta);

        $time = ig_fit_text(ig_format_times($film['timestamps'] ?? [$film['timestamp']]), IG_FONT_BODY, 22, $textMaxWidth);
        imagettftext($im, 22, 0, $textX, $y + 104, $red, IG_FONT_BODY, $time);

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

    return $im;
}

// A video-store rental card at closing time: deep purple-navy, dual neon
// accents (cyan for identity/titles, pink for the wordmark and call-outs),
// posters left in full color rather than tinted — unlike Zine/Newsprint,
// this is the "watching it on a CRT" theme, not a print-reproduction one.
// The wordmark is glowing letters with no box around them (a real neon
// sign has no chip), every page sits inside a glowing border frame like
// Marquee's bulb border, and scanlines are the final pass over everything.
function ig_build_list_page_neon(array $films, $date, $moreCount = 0) {
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
    ig_neon_text($im, 34, $margin, 108, IG_FONT_NEON_TITLE, 'CINEMA, TX', $pink, $pinkGlow);

    // A recording-light dot — the one place this theme borrows red, since
    // nothing else reads "REC" like it does.
    imagefilledellipse($im, $w - $margin - 58, 60, 12, 12, ig_hex($im, '#FF3355'));
    imagettftext($im, 20, 0, $w - $margin - 42, 66, $ink, IG_FONT_BODY, 'REC');

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

    $thumbW = 82;
    $thumbH = 123;
    $textX  = $margin + $thumbW + 28;
    $textMaxWidth = $w - $margin - $textX;

    $rowHeight   = $thumbH + 30;
    $footerY     = $h - 60;
    $rowsAreaEnd = $footerY - 70;
    $maxRows     = max(1, (int) floor(($rowsAreaEnd - $y) / $rowHeight));

    $rows  = array_slice($films, 0, $maxRows);
    $extra = count($films) - count($rows);

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
            $ibox = imagettfbbox(26, 0, IG_FONT_NEON_TITLE, $initial);
            $iw = $ibox[2] - $ibox[0];
            imagettftext($im, 26, 0, (int) ($margin + ($thumbW - $iw) / 2), $y + (int) ($thumbH / 2) + 9, $muted, IG_FONT_NEON_TITLE, $initial);
        }

        // Space for the "w/ Org" tag is reserved before the title is fit,
        // not squeezed in after — otherwise a long title would always fill
        // the row and the tag would silently never have room to appear.
        $presentedWith = ig_presented_with($film['billing'] ?? null);
        $tagText = null;
        $tagW = 0;
        if ($presentedWith) {
            $tagText = ig_fit_text($presentedWith, IG_FONT_BODY, 18, 200);
            $tagBox  = imagettfbbox(18, 0, IG_FONT_BODY, $tagText);
            $tagW    = $tagBox[2] - $tagBox[0];
        }

        $title = ig_fit_text(mb_strtoupper($film['title']), IG_FONT_NEON_TITLE, 28, $textMaxWidth - ($tagText ? $tagW + 14 : 0));
        ig_neon_text($im, 28, $textX, $y + 40, IG_FONT_NEON_TITLE, $title, $cyan, $cyanGlow);

        if ($tagText) {
            $titleBox = imagettfbbox(28, 0, IG_FONT_NEON_TITLE, $title);
            $titleW   = $titleBox[2] - $titleBox[0];
            imagettftext($im, 18, 0, $textX + $titleW + 14, $y + 40, $muted, IG_FONT_BODY, $tagText);
        }

        $venue = $film['location'] ? "{$film['venue']} — {$film['location']}" : $film['venue'];
        if ($film['director']) $venue .= '  ·  dir. ' . $film['director'];
        $meta  = ig_fit_text($venue, IG_FONT_BODY, 22, $textMaxWidth);
        imagettftext($im, 22, 0, $textX, $y + 74, $muted, IG_FONT_BODY, $meta);

        $time = ig_fit_text(ig_format_times($film['timestamps'] ?? [$film['timestamp']]), IG_FONT_BODY, 22, $textMaxWidth);
        imagettftext($im, 22, 0, $textX, $y + 104, $pink, IG_FONT_BODY, $time);

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
    $thumbW = 82;
    $thumbH = 123;

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

    $rowHeight   = $thumbH + 30;
    $footerY     = $h - 60;
    $rowsAreaEnd = $footerY - 70;
    $maxRows     = max(1, (int) floor(($rowsAreaEnd - $y) / $rowHeight));

    $rows  = array_slice($films, 0, $maxRows);
    $extra = count($films) - count($rows);

    if (empty($films)) {
        imagettftext($im, 24, 0, $margin, $y + 40, $muted, IG_FONT_TERMINAL, 'NOTHING SCRAPED FOR TODAY — CHECK BACK LATER.');
    }

    $timeMaxWidth   = $colPoster - $colTime - 20;
    $titleMaxWidth  = $colVenue - $colTitle - 20;
    $venueMaxWidth  = $colStatus - $colVenue - 20;
    $statusMaxWidth = ($w - $margin) - $colStatus;

    $lastIndex = count($rows) - 1;
    foreach ($rows as $i => $film) {
        $textY = $y + (int) round($thumbH / 2) + 8;

        $time = ig_fit_text(implode('/', array_map(fn($t) => date('g:iA', $t), $film['timestamps'] ?? [$film['timestamp']])), IG_FONT_TERMINAL, 18, $timeMaxWidth);
        imagettftext($im, 18, 0, $colTime, $textY, $ink, IG_FONT_TERMINAL, $time);

        $thumb = ig_fetch_thumb($film['poster'], $thumbW, $thumbH);
        if ($thumb) {
            imagecopy($im, $thumb, $colPoster, $y, 0, 0, $thumbW, $thumbH);
            imagedestroy($thumb);
            imagerectangle($im, $colPoster, $y, $colPoster + $thumbW, $y + $thumbH, $dim);
        } else {
            imagefilledrectangle($im, $colPoster, $y, $colPoster + $thumbW, $y + $thumbH, $placeholder);
            $initial = mb_strtoupper(mb_substr($film['title'], 0, 1));
            $ibox = imagettfbbox(24, 0, IG_FONT_TERMINAL, $initial);
            $iw = $ibox[2] - $ibox[0];
            imagettftext($im, 24, 0, (int) ($colPoster + ($thumbW - $iw) / 2), $y + (int) ($thumbH / 2) + 9, $muted, IG_FONT_TERMINAL, $initial);
        }
        $title = ig_fit_text(mb_strtoupper($film['title']), IG_FONT_TERMINAL, 22, $titleMaxWidth);
        imagettftext($im, 22, 0, $colTitle, $textY, $ink, IG_FONT_TERMINAL, $title);

        $venue = ig_fit_text(mb_strtoupper($film['venue']), IG_FONT_TERMINAL, 14, $venueMaxWidth);
        imagettftext($im, 14, 0, $colVenue, $textY, $muted, IG_FONT_TERMINAL, $venue);

        imagettftext($im, 18, 0, $colStatus, $textY, $green, IG_FONT_TERMINAL, ig_fit_text('ON TIME', IG_FONT_TERMINAL, 18, $statusMaxWidth));

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
function ig_build_list_page_darkroom(array $films, $date, $moreCount = 0) {
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

    imagefill($im, 0, 0, $bg);
    ig_sprocket_edge($im, 0, $h, 56, $strip, $bg);
    ig_sprocket_edge($im, $w - 56, $h, 56, $strip, $bg);

    $margin = 100;

    imagettftext($im, 28, 0, $margin, 110, $ink, IG_FONT_DARKROOM_TITLE, 'CINEMA, TX');
    $stampBox = imagettfbbox(28, 0, IG_FONT_DARKROOM_TITLE, 'CINEMA, TX');
    imagefilledrectangle($im, $margin, 122, $margin + ($stampBox[2] - $stampBox[0]), 124, $amber);

    ig_leader_mark($im, $w - $margin - 30, 70, 28, $amber);

    $y = 175;
    imagettftext($im, 24, 0, $margin, $y, $amber, IG_FONT_BODY, strtoupper("Today's Reel"));
    $y += 50;
    $amberGlow = imagecolorallocatealpha($im, 0xE0, 0x8A, 0x3E, 105);
    ig_neon_text($im, 44, $margin, $y, IG_FONT_DARKROOM_TITLE, date('l, F j', $date), $ink, $amberGlow);
    $y += 36;
    ig_dashed_line($im, $margin, $w - $margin, $y, $rust, 12, 8);
    $y += 40;

    $thumbW = 82;
    $thumbH = 123;
    $textX  = $margin + $thumbW + 28;
    $textMaxWidth = $w - $margin - $textX;

    $rowHeight   = $thumbH + 30;
    $footerY     = $h - 60;
    $rowsAreaEnd = $footerY - 70;
    $maxRows     = max(1, (int) floor(($rowsAreaEnd - $y) / $rowHeight));

    $rows  = array_slice($films, 0, $maxRows);
    $extra = count($films) - count($rows);

    if (empty($films)) {
        imagettftext($im, 28, 0, $margin, $y, $muted, IG_FONT_BODY, 'Nothing scraped for today — check back later.');
    }

    $lastIndex = count($rows) - 1;
    foreach ($rows as $i => $film) {
        $thumb = ig_fetch_thumb($film['poster'], $thumbW, $thumbH);
        if ($thumb) {
            imagecopy($im, $thumb, $margin, $y, 0, 0, $thumbW, $thumbH);
            imagedestroy($thumb);
        } else {
            imagefilledrectangle($im, $margin, $y, $margin + $thumbW, $y + $thumbH, $placeholder);
            $initial = mb_strtoupper(mb_substr($film['title'], 0, 1));
            $ibox = imagettfbbox(36, 0, IG_FONT_DARKROOM_TITLE, $initial);
            $iw = $ibox[2] - $ibox[0];
            imagettftext($im, 36, 0, (int) ($margin + ($thumbW - $iw) / 2), $y + (int) ($thumbH / 2) + 12, $muted, IG_FONT_DARKROOM_TITLE, $initial);
        }

        $title = ig_fit_text(mb_strtoupper($film['title']), IG_FONT_DARKROOM_TITLE, 30, $textMaxWidth);
        imagettftext($im, 30, 0, $textX, $y + 40, $amber, IG_FONT_DARKROOM_TITLE, $title);

        $venue = $film['location'] ? "{$film['venue']} — {$film['location']}" : $film['venue'];
        if ($film['director']) $venue .= '  ·  dir. ' . $film['director'];
        $meta  = ig_fit_text($venue, IG_FONT_BODY, 22, $textMaxWidth);
        imagettftext($im, 22, 0, $textX, $y + 74, $muted, IG_FONT_BODY, $meta);

        $time = ig_fit_text(ig_format_times($film['timestamps'] ?? [$film['timestamp']]), IG_FONT_BODY, 22, $textMaxWidth);
        imagettftext($im, 22, 0, $textX, $y + 104, $rust, IG_FONT_BODY, $time);

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

    $thumbW = 82;
    $thumbH = 123;
    $textX  = $margin + $thumbW + 28;
    $textMaxWidth = $w - $margin - $textX;

    $rowHeight   = $thumbH + 30;
    $footerY     = $h - 60;
    $rowsAreaEnd = $footerY - 70;
    $maxRows     = max(1, (int) floor(($rowsAreaEnd - $y) / $rowHeight));

    $rows  = array_slice($films, 0, $maxRows);
    $extra = count($films) - count($rows);

    if (empty($films)) {
        imagettftext($im, 28, 0, $margin, $y, $muted, IG_FONT_BODY, 'Nothing scraped for today — check back later.');
    }

    $lastIndex = count($rows) - 1;
    foreach ($rows as $i => $film) {
        $thumb = ig_fetch_thumb($film['poster'], $thumbW, $thumbH);
        if ($thumb) {
            imagecopy($im, $thumb, $margin, $y, 0, 0, $thumbW, $thumbH);
            imagedestroy($thumb);
        } else {
            imagefilledrectangle($im, $margin, $y, $margin + $thumbW, $y + $thumbH, $placeholder);
            $initial = mb_strtoupper(mb_substr($film['title'], 0, 1));
            $ibox = imagettfbbox(36, 0, IG_FONT_HEADLINE, $initial);
            $iw = $ibox[2] - $ibox[0];
            imagettftext($im, 36, 0, (int) ($margin + ($thumbW - $iw) / 2), $y + (int) ($thumbH / 2) + 12, $muted, IG_FONT_HEADLINE, $initial);
        }

        $title = ig_fit_text(mb_strtoupper($film['title']), IG_FONT_HEADLINE, 30, $textMaxWidth);
        imagettftext($im, 30, 0, $textX, $y + 40, $plum, IG_FONT_HEADLINE, $title);

        $venue = $film['location'] ? "{$film['venue']} — {$film['location']}" : $film['venue'];
        if ($film['director']) $venue .= '  ·  dir. ' . $film['director'];
        $meta  = ig_fit_text($venue, IG_FONT_BODY, 22, $textMaxWidth);
        imagettftext($im, 22, 0, $textX, $y + 74, $muted, IG_FONT_BODY, $meta);

        $time = ig_fit_text(ig_format_times($film['timestamps'] ?? [$film['timestamp']]), IG_FONT_BODY, 22, $textMaxWidth);
        imagettftext($im, 22, 0, $textX, $y + 104, $terracotta, IG_FONT_BODY, $time);

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

    $paper       = ig_hex($im, '#F4F1EB');
    $red         = ig_hex($im, '#922E32');
    $ink         = ig_hex($im, '#14120F');
    $muted       = ig_hex($im, '#6B6659');
    $divider     = ig_hex($im, '#DED7C7');
    $placeholder = ig_hex($im, '#E4DECE');

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
        $band  = imagecolorallocatealpha($im, 0xF4, 0xF1, 0xEB, $alpha);
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

    $kicker = strtoupper($film['venue'] ?? '');
    if ($kicker !== '') {
        imagettftext($im, 24, 0, $margin, $y, $red, IG_FONT_BODY, $kicker);
        // 56px caps reach ~52px above their own baseline — a flat leading
        // borrowed from the 24px line above it left the title's top edge
        // drawn back over the kicker. Gapped by the *next* line's ascent,
        // not the current line's, since it's the line about to be drawn
        // that determines how far up the pixels reach.
        $y += 68;
    }

    $titleLines = ig_wrap_lines(mb_strtoupper($film['title']), IG_FONT_HEADLINE, 56, $textMaxWidth, 2);
    foreach ($titleLines as $line) {
        imagettftext($im, 56, 0, $margin, $y, $ink, IG_FONT_HEADLINE, $line);
        $y += 72;
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
    $gold        = ig_hex($im, '#F2C14E');
    $goldGlow    = imagecolorallocatealpha($im, 0xF2, 0xC1, 0x4E, 100);

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

    $kicker = strtoupper($film['venue'] ?? '');
    if ($kicker !== '') {
        imagettftext($im, 24, 0, $margin, $y, $gold, IG_FONT_BODY, $kicker);
        // Anton reaches noticeably higher above its own baseline than
        // Fraunces at the same size (69px of ascent at 56px vs Fraunces'
        // 52px, measured) — the paper theme's gap would drive the title's
        // top edge back into the kicker here.
        $y += 84;
    }

    $titleLines = ig_wrap_lines(mb_strtoupper($film['title']), IG_FONT_MARQUEE_TITLE, 56, $textMaxWidth, 2);
    foreach ($titleLines as $line) {
        imagettftext($im, 56, 0, $margin, $y, $ink, IG_FONT_MARQUEE_TITLE, $line);
        $y += 86;
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

    $kicker = strtoupper($film['venue'] ?? '');
    if ($kicker !== '') {
        imagettftext($im, 24, 0, $margin, $y, $pink, IG_FONT_BODY, $kicker);
        $y += 68;
    }

    $titleLines = ig_wrap_lines(mb_strtoupper($film['title']), IG_FONT_ZINE_TITLE, 56, $textMaxWidth, 2);
    foreach ($titleLines as $line) {
        imagettftext($im, 56, 0, $margin, $y, $ink, IG_FONT_ZINE_TITLE, $line);
        $y += 72;
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

    $kicker = strtoupper($film['venue'] ?? '');
    if ($kicker !== '') {
        imagettftext($im, 24, 0, $margin, $y, $red, IG_FONT_BODY, $kicker);
        $y += 68;
    }

    $titleLines = ig_wrap_lines(mb_strtoupper($film['title']), IG_FONT_NEWSPRINT_TITLE, 56, $textMaxWidth, 2);
    foreach ($titleLines as $line) {
        imagettftext($im, 56, 0, $margin, $y, $ink, IG_FONT_NEWSPRINT_TITLE, $line);
        $y += 76;
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

    $kicker = strtoupper($film['venue'] ?? '');
    if ($kicker !== '') {
        imagettftext($im, 24, 0, $margin, $y, $pink, IG_FONT_BODY, $kicker);
        // Baloo 2's ascent at 46px (measured: 42px) runs shorter than
        // Monoton's did at 56px, so this gap shrank along with the title
        // size rather than keeping the old, now-oversized clearance.
        $y += 60;
    }

    $titleLines = ig_wrap_lines(mb_strtoupper($film['title']), IG_FONT_NEON_TITLE, 46, $textMaxWidth, 2);
    foreach ($titleLines as $line) {
        ig_neon_text($im, 46, $margin, $y, IG_FONT_NEON_TITLE, $line, $cyan, $cyanGlow);
        $y += 60;
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

    $titleLines = ig_wrap_lines(mb_strtoupper($film['title']), IG_FONT_TERMINAL, 54, $textMaxWidth, 2);
    foreach ($titleLines as $line) {
        imagettftext($im, 54, 0, $margin, $y, $ink, IG_FONT_TERMINAL, $line);
        $y += 66;
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

    $kicker = strtoupper($film['venue'] ?? '');
    if ($kicker !== '') {
        imagettftext($im, 24, 0, $margin, $y, $amber, IG_FONT_BODY, $kicker);
        $y += 66;
    }

    $titleLines = ig_wrap_lines(mb_strtoupper($film['title']), IG_FONT_DARKROOM_TITLE, 50, $textMaxWidth, 2);
    foreach ($titleLines as $line) {
        ig_neon_text($im, 50, $margin, $y, IG_FONT_DARKROOM_TITLE, $line, $ink, $amberGlow);
        $y += 62;
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

    $kicker = strtoupper($film['venue'] ?? '');
    if ($kicker !== '') {
        imagettftext($im, 24, 0, $margin, $y, $terracotta, IG_FONT_BODY, $kicker);
        $y += 66;
    }

    $titleLines = ig_wrap_lines(mb_strtoupper($film['title']), IG_FONT_HEADLINE, 52, $textMaxWidth, 2);
    foreach ($titleLines as $line) {
        imagettftext($im, 52, 0, $margin, $y, $ink, IG_FONT_HEADLINE, $line);
        $y += 58;
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

    foreach (glob($dir . '/ig-*.png') as $old) {
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
const IG_AUTO_MAX_PER_PAGE = 6;

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
// title, and absent here does mean empty (Alamo is opt-in, not defaulted).
function ig_alamo_path($date) {
    return dirname(__DIR__) . '/uploads/social/alamo-' . date('Y-m-d', $date) . '.json';
}

function ig_alamo_read($date) {
    $file = ig_alamo_path($date);
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
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
    $theme = in_array($compose['theme'] ?? 'paper', array_keys(IG_THEMES), true) ? $compose['theme'] : 'paper';

    if ($compose['mode'] === 'default' || empty($films)) {
        return [ig_build_list_page($films, $date, $theme)];
    }

    $maxPerPage = ($compose['mode'] === 'manual' && !empty($compose['per_page']))
        ? max(1, (int) $compose['per_page'])
        : IG_AUTO_MAX_PER_PAGE;

    $images   = [];
    $consumed = 0;
    foreach (ig_paginate($films, $maxPerPage) as $page) {
        $consumed += count($page);
        $moreCount = count($films) - $consumed; // 0 on the last list page
        $images[]  = ig_build_list_page($page, $date, $theme, $moreCount);
    }

    if (!empty($compose['features'])) {
        // $films is already chronological, so filtering it down to the
        // carousel selection keeps that order — a checked-but-late film
        // still yields its slot to an earlier one if there isn't room for
        // both, same as an untouched day's default already would.
        $selected = ig_carousel_selection($films, $compose, $date);
        $chosen   = array_values(array_filter($films, fn($f) => in_array(ig_film_key($f), $selected, true)));

        $slotsLeft = 10 - count($images);
        foreach ($chosen as $film) {
            if ($slotsLeft <= 0) break;
            $images[] = ig_build_feature_page($film, $date, $theme);
            $slotsLeft--;
        }
    }

    return $images;
}

// ── Graph API ────────────────────────────────────────────────────────────

function ig_graph_call($url, array $fields, $method = 'GET') {
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
 * Shared by the single-image path and every child of a carousel. Returns the
 * container id, or throws with whatever error payload Meta returned.
 */
function ig_create_container($base, $ig, array $fields) {
    $create = ig_graph_call("$base/$ig/media", $fields + ['access_token' => IG_ACCESS_TOKEN], 'POST');
    if (empty($create['id'])) {
        throw new RuntimeException('IG container creation failed: ' . json_encode($create));
    }
    $container_id = $create['id'];

    for ($i = 0; $i < 10; $i++) {
        $status = ig_graph_call("$base/$container_id", [
            'fields'       => 'status_code',
            'access_token' => IG_ACCESS_TOKEN,
        ]);
        $code = $status['status_code'] ?? null;
        if ($code === 'FINISHED') break;
        if ($code === 'ERROR') {
            throw new RuntimeException('IG container failed to process: ' . json_encode($status));
        }
        sleep(2);
    }

    return $container_id;
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
        $children = [];
        foreach ($pages as $i => [$path, $url]) {
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
        $container_id = ig_create_container($base, $ig, [
            'media_type' => 'CAROUSEL',
            'children'   => implode(',', $children),
            'caption'    => $caption,
        ]);
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
