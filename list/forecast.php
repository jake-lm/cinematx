<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Film Forecast — the weekly podcast Reel
//
//  Its own table (forecast_episodes) and its own visual identity, separate
//  from the daily screening carousel in list/instagram.php: this isn't a
//  screening, so it was never going to fit events/films without distorting
//  either. Requires list/instagram.php for its GD primitives (ig_hex(),
//  ig_fit_text(), ig_wrap_lines(), ig_fetch_thumb(), ig_graph_call(),
//  ig_create_container(), ig_public_url()) rather than duplicating them —
//  "independent" means its own data model and look, not reinventing
//  text-wrapping.
//
//  One fixed cover design, not a rotating set of themes — Forecast is a
//  weekly show with a consistent brand, unlike the daily carousel's
//  rotating-by-weekday themes.
// ═══════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/instagram.php';

// ── Data ─────────────────────────────────────────────────────────────────

function forecast_list_episodes($conn) {
    return $conn->query(
        "SELECT * FROM `forecast_episodes` ORDER BY `week_of` DESC, `id` DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

function forecast_get_episode($conn, $id) {
    $stmt = $conn->prepare("SELECT * FROM `forecast_episodes` WHERE `id` = :id");
    $stmt->execute([':id' => (int) $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Media probing ───────────────────────────────────────────────────────

/**
 * Duration in whole seconds via ffprobe, or null if the file can't be read.
 * Read straight off the file rather than trusted from a form field, so the
 * number on the cover graphic and the progress bar's timing can never drift
 * from what's actually in the audio/video.
 */
function forecast_probe_duration($path) {
    if (!file_exists($path)) return null;
    $cmd = 'ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($path);
    $out = shell_exec($cmd);
    $seconds = (float) trim((string) $out);
    return $seconds > 0 ? (int) round($seconds) : null;
}

function forecast_format_duration($seconds) {
    $seconds = (int) $seconds;
    $m = intdiv($seconds, 60);
    $s = $seconds % 60;
    return $m > 0 ? sprintf('%d:%02d', $m, $s) : sprintf('0:%02d', $s);
}

// ── Cover graphic (1080×1920 — Reels' own aspect ratio, not the daily
//    carousel's 1080×1350/1080×700) ─────────────────────────────────────

// The bottom band ffmpeg composites the waveform and progress bar into
// afterward — the cover render itself never draws here, so there's nothing
// underneath for the overlay to fight with.
const FORECAST_WAVE_BAND_Y = 1650;

// This week's screenings, one row per unique title (a film playing three
// nights only needs to appear once here) — the same Paper-theme list
// language the daily carousel already uses, so Forecast's background reads
// as "the list" rather than an unrelated graphic. $weekOf anchors the
// 7-day window; capped at $limit rows, with the true remaining count
// returned alongside so the cover can show a "+N more this week" line
// exactly like ig_build_list_page()'s own overflow handling.
function forecast_week_films($conn, $weekOf, $limit = 9) {
    $start = strtotime($weekOf);
    $end   = strtotime('+7 day', $start) - 1;
    $films = fetch_all_screenings($conn, $start, $end, false);
    $films = array_values(array_filter($films, fn($f) => in_array($f['venue'], IG_VENUES, true)));
    $films = ctx_enrich($films);

    $seen = [];
    $unique = [];
    foreach ($films as $f) {
        $title = !empty($f['display_title']) ? $f['display_title'] : $f['title'];
        $key = mb_strtolower($title);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $unique[] = $f + ['display_title' => $title];
    }

    return [
        'films' => array_slice($unique, 0, $limit),
        'more'  => max(0, count($unique) - $limit),
    ];
}

// Crops $src to a centered square, resizes to $diameter, and masks
// everything outside the circle to transparent — the standard GD approach
// (no native circular clip), fast enough at badge size to just walk every
// pixel once. Caller owns disposing both the input and the returned image.
function forecast_circle_crop($src, $diameter) {
    $srcW = imagesx($src);
    $srcH = imagesy($src);
    $size = min($srcW, $srcH);
    $srcX = (int) (($srcW - $size) / 2);
    $srcY = (int) (($srcH - $size) / 2);

    $dst = imagecreatetruecolor($diameter, $diameter);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $diameter, $diameter, $transparent);
    imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $diameter, $diameter, $size, $size);

    $r = $diameter / 2;
    for ($x = 0; $x < $diameter; $x++) {
        for ($y = 0; $y < $diameter; $y++) {
            if (($x - $r) ** 2 + ($y - $r) ** 2 > $r ** 2) {
                imagesetpixel($dst, $x, $y, $transparent);
            }
        }
    }
    return $dst;
}

function forecast_build_cover(array $episode, $conn) {
    $w = 1080;
    $h = 1920;
    $im = imagecreatetruecolor($w, $h);

    $paper  = ig_hex($im, '#F4F1EB');
    $ink    = ig_hex($im, '#14120F');
    $red    = ig_hex($im, '#922E32');
    $muted  = ig_hex($im, '#6B6659');
    $divider = ig_hex($im, '#DED7C7');
    $placeholder = ig_hex($im, '#E4DECE');

    imagefill($im, 0, 0, $paper);
    imagefilledrectangle($im, 0, 0, $w, 14, $red);

    $margin = 80;
    $y = 110;

    imagettftext($im, 28, 0, $margin, $y, $red, IG_FONT_BODY, strtoupper('Film Forecast'));
    $y += 90;

    // The largest text on the page — this is the headline now, the guest
    // is the byline underneath it, a deliberate flip from the first design
    // (guest photo full-bleed, guest name as the title).
    $weekOf = 'WEEK OF ' . strtoupper(date('M j', strtotime($episode['week_of'])));
    imagettftext($im, 96, 0, $margin, $y, $ink, IG_FONT_HEADLINE, $weekOf);
    $y += 70;

    $byline = 'with ' . $episode['guest_name'];
    if (!empty($episode['duration_seconds'])) {
        $byline .= '   ·   ' . forecast_format_duration($episode['duration_seconds']);
    }
    imagettftext($im, 34, 0, $margin, $y, $muted, IG_FONT_BODY, $byline);
    $y += 50;

    imagefilledrectangle($im, $margin, $y, $w - $margin, $y + 1, $divider);
    $y += 50;

    $thumbW = 70; $thumbH = 105;
    $textX  = $margin + $thumbW + 26;
    $textMaxWidth = $w - $margin - $textX;
    $rowHeight = $thumbH + 30;

    $week = forecast_week_films($conn, $episode['week_of'], (int) floor((FORECAST_WAVE_BAND_Y - $y - 60) / $rowHeight));

    foreach ($week['films'] as $f) {
        $thumb = ig_fetch_thumb($f['poster'] ?? null, $thumbW, $thumbH);
        if ($thumb) {
            imagecopy($im, $thumb, $margin, $y, 0, 0, $thumbW, $thumbH);
            imagedestroy($thumb);
        } else {
            imagefilledrectangle($im, $margin, $y, $margin + $thumbW, $y + $thumbH, $placeholder);
        }

        $title = ig_fit_text(mb_strtoupper($f['display_title']), IG_FONT_HEADLINE, 26, $textMaxWidth);
        imagettftext($im, 26, 0, $textX, $y + 38, $ink, IG_FONT_HEADLINE, $title);

        $meta = ig_fit_text($f['venue'] . '  ·  ' . date('D', $f['timestamp']), IG_FONT_BODY, 20, $textMaxWidth);
        imagettftext($im, 20, 0, $textX, $y + 70, $muted, IG_FONT_BODY, $meta);

        $y += $rowHeight;
    }

    if ($week['more'] > 0) {
        imagettftext($im, 22, 0, $margin, $y + 30, $red, IG_FONT_BODY, '+ ' . $week['more'] . ' more this week');
    }

    // Guest photo — small, bottom-right, its own badge rather than the
    // frame's dominant image now that the list is. Sits above
    // FORECAST_WAVE_BAND_Y so it never collides with ffmpeg's waveform/
    // progress-bar overlay.
    $photoUrl = !empty($episode['guest_photo']) ? '/uploads/forecast/' . $episode['guest_photo'] : null;
    $badgeD = 220;
    $bx = $w - $margin - $badgeD;
    $by = FORECAST_WAVE_BAND_Y - $badgeD - 40;

    $shadow = imagecolorallocatealpha($im, 0, 0, 0, 80);
    imagefilledellipse($im, (int) ($bx + $badgeD / 2) + 4, (int) ($by + $badgeD / 2) + 6, $badgeD + 16, $badgeD + 16, $shadow);
    imagefilledellipse($im, (int) ($bx + $badgeD / 2), (int) ($by + $badgeD / 2), $badgeD + 10, $badgeD + 10, $red);

    $photoSrc = $photoUrl ? ig_fetch_thumb($photoUrl, $badgeD, $badgeD) : null;
    if ($photoSrc) {
        $circle = forecast_circle_crop($photoSrc, $badgeD);
        imagedestroy($photoSrc);
        imagealphablending($im, true);
        imagecopy($im, $circle, $bx, $by, 0, 0, $badgeD, $badgeD);
        imagedestroy($circle);
    } else {
        imagefilledellipse($im, (int) ($bx + $badgeD / 2), (int) ($by + $badgeD / 2), $badgeD, $badgeD, $placeholder);
        $initial = mb_strtoupper(mb_substr($episode['guest_name'], 0, 1));
        $ibox = imagettfbbox(80, 0, IG_FONT_HEADLINE, $initial);
        $iw = $ibox[2] - $ibox[0];
        imagettftext($im, 80, 0, (int) ($bx + $badgeD / 2 - $iw / 2), (int) ($by + $badgeD / 2 + 28), $muted, IG_FONT_HEADLINE, $initial);
    }

    imagefilledrectangle($im, 0, FORECAST_WAVE_BAND_Y, $w, $h, $ink);

    return $im;
}

// ── Caption ──────────────────────────────────────────────────────────────

function forecast_build_caption(array $episode) {
    $lines = [
        '🎙️ Film Forecast — Week of ' . date('F j', strtotime($episode['week_of'])),
        '',
        'This week\'s guest: ' . $episode['guest_name'],
    ];
    if (!empty($episode['blurb'])) {
        $lines[] = '';
        $lines[] = $episode['blurb'];
    }
    $lines[] = '';
    $lines[] = 'Full schedule at cinematx.net';
    return implode("\n", $lines);
}

function forecast_caption(array $episode) {
    $saved = trim((string) ($episode['caption'] ?? ''));
    return $saved !== '' ? $saved : forecast_build_caption($episode);
}

// ── Video assembly ───────────────────────────────────────────────────────

/**
 * Composites the cover PNG, an animated waveform, and a filling progress
 * bar into one mp4 sized to the audio's own duration. Only used for the
 * audio-upload path — a directly-uploaded video skips this and posts as-is.
 *
 * The waveform (ffmpeg's showwaves) draws on black by default; colorkey
 * strips that black to transparent before it's overlaid, so the cover
 * shows through everywhere the waveform itself isn't drawn — confirmed
 * genuinely audio-reactive per frame (tested against a two-tone clip: tight
 * scallops during the high-frequency half, wide ones during the low).
 *
 * The progress bar is a geq-generated clip — every pixel's color is a
 * direct function of its X position and the frame time T, `if(X < barW*T/
 * duration, fill, track)`. Two earlier approaches both looked right in
 * short tests and broke on a real episode:
 *   - drawbox's w as a `t`-based expression accepted the syntax without
 *     erroring, but silently evaluated it as a constant (fully filled from
 *     frame one) instead of re-evaluating per frame — caught by checking
 *     multiple checkpoints across a clip, not just the first frame.
 *   - xfade's wipe actually animated correctly, but its `duration` option
 *     turned out to be capped at 60 seconds (it's meant for short
 *     transitions between clips) — invisible in an 8-second test clip,
 *     fatal on a real 5-10 minute episode ("Value 383.000000 for parameter
 *     'duration' out of range [0 - 60]").
 * geq has no such cap and was verified against both a short clip and a
 * real 383-second duration before landing here.
 *
 * Returns ['ok' => true] or ['ok' => false, 'error' => '...'] — the ffmpeg
 * output tail on failure, since a filter-graph syntax error only shows up
 * there, not in the exit code alone.
 */
function forecast_generate_video($audioPath, $coverPath, $outputPath, $durationSeconds) {
    if (!file_exists($audioPath)) return ['ok' => false, 'error' => 'Audio file not found.'];
    if (!file_exists($coverPath)) return ['ok' => false, 'error' => 'Cover image not found.'];
    if ($durationSeconds <= 0) return ['ok' => false, 'error' => 'Could not determine audio duration.'];

    $waveW = 900; $waveH = 160;
    $waveX = 90;  $waveY = FORECAST_WAVE_BAND_Y + 40;
    $barX  = 90;  $barY = FORECAST_WAVE_BAND_Y + 230;
    $barW  = 900; $barH = 8;

    // 0x3A362E (track) / 0x922E32 (fill), split into decimal RGB — geq's
    // per-channel expressions take plain numbers, not hex literals.
    $trackR = 0x3A; $trackG = 0x36; $trackB = 0x2E;
    $fillR  = 0x92; $fillG  = 0x2E; $fillB  = 0x32;
    $lit = "lt(X\\,{$barW}*T/{$durationSeconds})";
    $barGeq = "geq="
        . "r='if({$lit}\\,{$fillR}\\,{$trackR})':"
        . "g='if({$lit}\\,{$fillG}\\,{$trackG})':"
        . "b='if({$lit}\\,{$fillB}\\,{$trackB})'";

    $filter =
        "[1:a]showwaves=s={$waveW}x{$waveH}:mode=cline:rate=25:colors=0xF2C14E[wraw];"
        . "[wraw]colorkey=0x000000:0.15:0.1,format=rgba[wave];"
        . "[2:v]{$barGeq}[bar];"
        . "[0:v][wave]overlay={$waveX}:{$waveY}[bg1];"
        . "[bg1][bar]overlay={$barX}:{$barY}[out]";

    $cmd = sprintf(
        'ffmpeg -y -loop 1 -i %s -i %s -f lavfi -i %s -filter_complex %s -map %s -map 1:a '
        . '-c:v libx264 -pix_fmt yuv420p -c:a aac -b:a 128k -r 25 -shortest %s 2>&1',
        escapeshellarg($coverPath),
        escapeshellarg($audioPath),
        escapeshellarg("color=c=black:s={$barW}x{$barH}:d={$durationSeconds}:r=25"),
        escapeshellarg($filter),
        escapeshellarg('[out]'),
        escapeshellarg($outputPath)
    );

    exec($cmd, $outLines, $exitCode);
    if ($exitCode !== 0 || !file_exists($outputPath)) {
        return ['ok' => false, 'error' => implode("\n", array_slice($outLines, -25))];
    }
    return ['ok' => true];
}

// ── Publish ──────────────────────────────────────────────────────────────

// Video processing on Meta's side can run well past the 20s budget an
// image needs — see ig_create_container()'s $maxAttempts/$sleepSecs.
const IG_REEL_POLL_ATTEMPTS = 90;
const IG_REEL_POLL_SLEEP    = 5; // 90 × 5s = 7.5 minutes

/**
 * Publishes a Reel. $videoPath is the local file (for ig_public_url()'s
 * mtime cache-buster, same reasoning as the daily carousel's images —
 * Meta's fetcher caches by URL, so a stable filename needs a version
 * query string to guarantee it fetches the current file). Returns the
 * published media id, or throws.
 */
function ig_publish_reel($videoRelativeUrl, $videoPath, $caption) {
    $base = 'https://graph.facebook.com/' . IG_GRAPH_VERSION;
    $ig   = IG_BUSINESS_ACCOUNT_ID;

    $video_url = ig_public_url($videoRelativeUrl, $videoPath);
    if ($video_url === '') {
        throw new RuntimeException('CTX_SITE_URL is not set, so Meta has no address to fetch the video from.');
    }

    $container_id = ig_create_container($base, $ig, [
        'media_type'    => 'REELS',
        'video_url'     => $video_url,
        'caption'       => $caption,
        'share_to_feed' => 'true',
    ], IG_REEL_POLL_ATTEMPTS, IG_REEL_POLL_SLEEP);

    $publish = ig_graph_call("$base/$ig/media_publish", [
        'creation_id'  => $container_id,
        'access_token' => IG_ACCESS_TOKEN,
    ], 'POST');

    if (empty($publish['id'])) {
        throw new RuntimeException('IG Reel publish failed: ' . json_encode($publish));
    }

    return $publish['id'];
}
