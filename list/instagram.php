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

define('IG_GRAPH_VERSION', 'v21.0');
define('IG_FONT_HEADLINE', dirname(__DIR__) . '/assets/fonts/Fraunces-Bold.ttf');
define('IG_FONT_BODY',     dirname(__DIR__) . '/assets/fonts/InstrumentSans-SemiBold.ttf');

// ── Data ─────────────────────────────────────────────────────────────────

// The three arthouse venues this post is meant to promote — not the chains
// (Alamo Drafthouse, Fathom Events) and not member-submitted `events`.
const IG_VENUES = ['Austin Film Society', 'Paramount Theatre', 'Hyperreal Film Club'];

// Today's window in the project's own timezone (America/Chicago, set in
// database.php). $force is false — this rides on whatever warm-cache.php
// has already scraped, same as a normal page request.
function ig_today_films($conn) {
    $start = strtotime('today');
    $end   = strtotime('tomorrow') - 1;
    $films = fetch_all_screenings($conn, $start, $end, false);
    return array_values(array_filter($films, fn($f) => in_array($f['venue'], IG_VENUES, true)));
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

// A pill — rectangle with semicircular ends — for the wordmark chip.
function ig_pill($im, $x1, $y1, $x2, $y2, $color) {
    $r = (int) round(($y2 - $y1) / 2);
    imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
    imagefilledellipse($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
    imagefilledellipse($im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
}

// Downloads a poster (TMDB, w300) once and caches it — the same handful of
// repertory titles recur night after night, no reason to refetch. Returns a
// cropped-to-cover GD image sized exactly $w x $h, or null on any miss/failure
// so the caller can fall back to a placeholder rather than breaking the row.
function ig_fetch_thumb($url, $w, $h) {
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
        imagecopyresampled($thumb, $src, 0, 0, 0, (int) (($srcH - $cropH) / 2), $w, $h, $srcW, $cropH);
    }
    imagedestroy($src);
    return $thumb;
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

function ig_build_list_page(array $films, $date) {
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

        $time = ig_fit_text(date('g:i A', $film['timestamp']), IG_FONT_BODY, 22, $textMaxWidth);
        imagettftext($im, 22, 0, $textX, $y + 104, $red, IG_FONT_BODY, $time);

        $y += $rowHeight;
        if ($i < $lastIndex) {
            imagefilledrectangle($im, $margin, $y - 15, $w - $margin, $y - 14, $divider);
        }
    }

    if ($extra > 0) {
        imagettftext($im, 24, 0, $margin, $y + 16, $red, IG_FONT_BODY, "+{$extra} more today");
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
function ig_build_feature_page(array $film, $date) {
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

    $hero = ig_fetch_thumb(ig_hero_url($film['poster']), $w, $heroH);
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

    // Brand mark on top of the hero, same lockup and position as the list
    // page, so a single swiped-to feature page still reads as a CinemaTX
    // post on its own rather than an anonymous photo.
    imagefilledrectangle($im, 0, 0, $w, 14, $red);
    $chipText = 'CINEMA, TX';
    $chipBox  = imagettfbbox(20, 0, IG_FONT_BODY, $chipText);
    $chipW    = $chipBox[2] - $chipBox[0];
    ig_pill($im, $margin, 70, $margin + $chipW + 48, 114, $red);
    imagettftext($im, 20, 0, $margin + 24, 100, $paper, IG_FONT_BODY, $chipText);

    $textMaxWidth = $w - $margin * 2;
    $y = $heroH + 50;

    $venue  = strtoupper($film['venue'] ?? '');
    $time   = !empty($film['timestamp']) ? date('g:i A', $film['timestamp']) : '';
    $kicker = trim($venue . ($time ? "  ·  {$time}" : ''));
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
 */
function ig_public_url($relative) {
    if (!defined('CTX_SITE_URL') || !CTX_SITE_URL) return '';
    return rtrim(CTX_SITE_URL, '/') . $relative;
}

// ── Caption ──────────────────────────────────────────────────────────────

// A handful of intro lines, rotated deterministically by day-of-year so the
// same day's dry run and real post always match, but it varies day to day.
const IG_INTROS = [
    "Here's where to be in Austin today:",
    "Tonight's lineup around town:",
    "A few reasons to leave the apartment:",
    "What's screening across the city today:",
    "On screens around Austin today:",
];

function ig_build_caption(array $films, $date) {
    $intro = IG_INTROS[(int) date('z', $date) % count(IG_INTROS)];
    $lines = ['Today in Austin — ' . date('l, F j', $date), '', $intro, ''];

    foreach ($films as $film) {
        $venue = $film['location'] ? "{$film['venue']} ({$film['location']})" : $film['venue'];
        $lines[] = '• ' . mb_strtoupper($film['title']) . ' — ' . $venue . ' @ ' . date('g:i A', $film['timestamp']);
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

// Absent compose-<date>.json means this: one card, today's exact design,
// no pagination, no spotlight pages. New days behave exactly as they always
// have until someone opens the admin page and changes something.
const IG_COMPOSE_DEFAULT = ['mode' => 'default', 'per_page' => null, 'features' => false];

// Screenings-per-page when Auto mode (or Manual with no count set) is
// active — today's exact row height, so a single-page Auto day looks
// identical to a Default one.
const IG_AUTO_MAX_PER_PAGE = 6;

function ig_compose_path($date) {
    return dirname(__DIR__) . '/uploads/social/compose-' . date('Y-m-d', $date) . '.json';
}

function ig_compose_read($date) {
    $file = ig_compose_path($date);
    if (!file_exists($file)) return IG_COMPOSE_DEFAULT;
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data + IG_COMPOSE_DEFAULT : IG_COMPOSE_DEFAULT;
}

function ig_compose_write($date, array $compose) {
    $dir = dirname(__DIR__) . '/uploads/social';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    file_put_contents(ig_compose_path($date), json_encode($compose + IG_COMPOSE_DEFAULT));
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
 * on one feature page per film, chronological order, stopping when the
 * films or the cap runs out.
 */
function ig_build_images(array $films, $date, array $compose) {
    if ($compose['mode'] === 'default' || empty($films)) {
        return [ig_build_list_page($films, $date)];
    }

    $maxPerPage = ($compose['mode'] === 'manual' && !empty($compose['per_page']))
        ? max(1, (int) $compose['per_page'])
        : IG_AUTO_MAX_PER_PAGE;

    $images = [];
    foreach (ig_paginate($films, $maxPerPage) as $page) {
        $images[] = ig_build_list_page($page, $date);
    }

    if (!empty($compose['features'])) {
        $slotsLeft = 10 - count($images);
        foreach ($films as $film) {
            if ($slotsLeft <= 0) break;
            $images[] = ig_build_feature_page($film, $date);
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
 * Takes the root-relative path(s) ig_save_images() returns and resolves
 * them to absolute URLs here, so no caller has to remember which form this
 * needs. A single path publishes as one image, same as always; an array of
 * 2+ publishes as a carousel — each becomes a child
 * container (polled individually, so a failure names which image it was)
 * before the CAROUSEL parent is created and published. Either way this is
 * one post against the rate limit.
 */
function ig_publish($image_paths, $caption) {
    $base  = 'https://graph.facebook.com/' . IG_GRAPH_VERSION;
    $ig    = IG_BUSINESS_ACCOUNT_ID;
    $paths = is_array($image_paths) ? array_values($image_paths) : [$image_paths];

    if (count($paths) === 1) {
        $image_url = ig_public_url($paths[0]);
        if ($image_url === '') {
            throw new RuntimeException('CTX_SITE_URL is not set, so Meta has no address to fetch the card from.');
        }
        $container_id = ig_create_container($base, $ig, [
            'image_url' => $image_url,
            'caption'   => $caption,
        ]);
    } else {
        $children = [];
        foreach ($paths as $i => $path) {
            $image_url = ig_public_url($path);
            if ($image_url === '') {
                throw new RuntimeException('CTX_SITE_URL is not set, so Meta has no address to fetch the card from.');
            }
            try {
                $children[] = ig_create_container($base, $ig, [
                    'image_url'        => $image_url,
                    'is_carousel_item' => 'true',
                ]);
            } catch (RuntimeException $e) {
                throw new RuntimeException('Carousel image ' . ($i + 1) . ' of ' . count($paths) . ' failed: ' . $e->getMessage());
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
