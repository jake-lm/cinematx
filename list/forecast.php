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

function forecast_build_cover(array $episode) {
    $w = 1080;
    $h = 1920;
    $im = imagecreatetruecolor($w, $h);

    $ink    = ig_hex($im, '#14120F');
    $cream  = ig_hex($im, '#F4F1EB');
    $red    = ig_hex($im, '#922E32');
    $gold   = ig_hex($im, '#F2C14E');
    $muted  = ig_hex($im, '#B5AFA0');
    $placeholder = ig_hex($im, '#241D16');

    imagefill($im, 0, 0, $ink);

    $margin = 80;
    $heroH  = 1200;

    $photoUrl = !empty($episode['guest_photo']) ? '/uploads/forecast/' . $episode['guest_photo'] : null;
    $hero = $photoUrl ? ig_fetch_thumb($photoUrl, $w, $heroH) : null;
    if ($hero) {
        imagecopy($im, $hero, 0, 0, 0, 0, $w, $heroH);
        imagedestroy($hero);
    } else {
        imagefilledrectangle($im, 0, 0, $w, $heroH, $placeholder);
        $initial = mb_strtoupper(mb_substr($episode['guest_name'], 0, 1));
        $ibox = imagettfbbox(140, 0, IG_FONT_HEADLINE, $initial);
        $iw = $ibox[2] - $ibox[0];
        imagettftext($im, 140, 0, (int) (($w - $iw) / 2), (int) ($heroH / 2) + 50, $muted, IG_FONT_HEADLINE, $initial);
    }

    // Fade the hero into the ink background so the text block below always
    // has guaranteed contrast regardless of the photo's own tones.
    $fadeH = 260;
    for ($i = 0; $i < $fadeH; $i++) {
        $alpha = (int) round(127 * (1 - $i / $fadeH));
        $band  = imagecolorallocatealpha($im, 0x14, 0x12, 0x0F, $alpha);
        imagefilledrectangle($im, 0, $heroH - $fadeH + $i, $w, $heroH - $fadeH + $i + 1, $band);
    }
    imagefilledrectangle($im, 0, $heroH, $w, FORECAST_WAVE_BAND_Y, $ink);

    imagettftext($im, 30, 0, $margin, $heroH + 70, $red, IG_FONT_BODY, strtoupper('Film Forecast'));

    $name = ig_fit_text(mb_strtoupper($episode['guest_name']), IG_FONT_HEADLINE, 64, $w - $margin * 2);
    imagettftext($im, 64, 0, $margin, $heroH + 150, $cream, IG_FONT_HEADLINE, $name);

    $weekOf = 'WEEK OF ' . strtoupper(date('M j', strtotime($episode['week_of'])));
    $deckParts = [$weekOf];
    if (!empty($episode['duration_seconds'])) {
        $deckParts[] = forecast_format_duration($episode['duration_seconds']);
    }
    imagettftext($im, 28, 0, $margin, $heroH + 200, $gold, IG_FONT_BODY, implode('   ·   ', $deckParts));

    if (!empty($episode['blurb'])) {
        $y = $heroH + 260;
        foreach (ig_wrap_lines($episode['blurb'], IG_FONT_BODY, 26, $w - $margin * 2, 4) as $line) {
            imagettftext($im, 26, 0, $margin, $y, $muted, IG_FONT_BODY, $line);
            $y += 38;
        }
    }

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
