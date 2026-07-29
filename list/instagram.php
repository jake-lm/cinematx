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

function ig_build_image(array $films, $date) {
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

// Writes the PNG, prunes anything older than a week, returns the public URL.
function ig_save_image($im, $date) {
    $dir = dirname(__DIR__) . '/uploads/social';
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    $name = 'ig-' . date('Y-m-d', $date) . '.png';
    $path = $dir . '/' . $name;
    imagepng($im, $path);
    imagedestroy($im);

    foreach (glob($dir . '/ig-*.png') as $old) {
        if (filemtime($old) < time() - 7 * 86400) unlink($old);
    }

    $site_url = defined('CTX_SITE_URL') ? CTX_SITE_URL : '';
    return [$path, $site_url . '/uploads/social/' . $name];
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

// Container → poll → publish. Returns the published media id, or throws with
// whatever Meta's error payload said.
function ig_publish($image_url, $caption) {
    $base = 'https://graph.facebook.com/' . IG_GRAPH_VERSION;
    $ig   = IG_BUSINESS_ACCOUNT_ID;

    $create = ig_graph_call("$base/$ig/media", [
        'image_url'    => $image_url,
        'caption'      => $caption,
        'access_token' => IG_ACCESS_TOKEN,
    ], 'POST');

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

    $publish = ig_graph_call("$base/$ig/media_publish", [
        'creation_id'  => $container_id,
        'access_token' => IG_ACCESS_TOKEN,
    ], 'POST');

    if (empty($publish['id'])) {
        throw new RuntimeException('IG publish failed: ' . json_encode($publish));
    }

    return $publish['id'];
}
