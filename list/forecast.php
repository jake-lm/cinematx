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

// ── Podcast RSS feed ─────────────────────────────────────────────────────
//
// posted_media_id — already the "this episode is publicly live" signal the
// Instagram Reel posting flow sets — doubles as the podcast feed's publish
// gate too, rather than adding a second, separate "public" flag. Ascending
// by posted_at so itunes:episode numbers count up in release order; the
// feed itself still renders newest-first, the normal podcast convention.

function forecast_feed_episodes($conn) {
    return $conn->query(
        "SELECT * FROM `forecast_episodes`
         WHERE `posted_media_id` IS NOT NULL AND `audio_file` IS NOT NULL AND `audio_file` != ''
         ORDER BY `posted_at` ASC, `id` ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Transcodes a just-uploaded audio file to a standard 128kbps MP3 — WAV in
 * particular is valid per the podcast RSS spec but poorly supported by
 * some directories/players, and it's many times the size of an MP3 for no
 * audible difference at spoken-word bitrates (a real episode this project
 * uploaded: 147MB WAV). Returns the new filename (already renamed to
 * .mp3, source file removed) or null if there was nothing to do — the
 * source was already an MP3, or ffmpeg failed. A failed transcode always
 * leaves the original upload in place; it never deletes the only copy of
 * the audio on a failure.
 */
function forecast_transcode_audio_to_mp3($dir, $filename) {
    if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'mp3') return null;

    $srcPath = $dir . '/' . $filename;
    $mp3Name = pathinfo($filename, PATHINFO_FILENAME) . '.mp3';
    $mp3Path = $dir . '/' . $mp3Name;

    $cmd = sprintf(
        'ffmpeg -y -i %s -vn -c:a libmp3lame -b:a 128k %s 2>&1',
        escapeshellarg($srcPath),
        escapeshellarg($mp3Path)
    );
    exec($cmd, $output, $exitCode);
    if ($exitCode !== 0 || !file_exists($mp3Path) || filesize($mp3Path) === 0) {
        @unlink($mp3Path);
        return null;
    }

    unlink($srcPath);
    return $mp3Name;
}

/**
 * Pulls the audio track out of a directly-uploaded video into its own
 * MP3 — for the manual-edit fallback path (a finished video edited
 * outside the auto-pipeline, in Premiere say) where the video is the
 * real final cut and whatever audio_file happens to be on the episode
 * may since have diverged from it. The podcast RSS feed's enclosure
 * always reads audio_file (see forecast-feed.php), so without this the
 * feed would keep serving stale audio even after the video's replaced.
 * Returns the new filename, or null if ffmpeg failed — the video upload
 * itself still succeeds either way, this just leaves audio_file as it
 * was.
 */
function forecast_extract_audio_from_video($dir, $videoFilename, $episode_id) {
    $videoPath = $dir . '/' . $videoFilename;
    $mp3Name = $episode_id . '-audio_file-' . time() . '.mp3';
    $mp3Path = $dir . '/' . $mp3Name;

    $cmd = sprintf(
        'ffmpeg -y -i %s -vn -c:a libmp3lame -b:a 128k %s 2>&1',
        escapeshellarg($videoPath),
        escapeshellarg($mp3Path)
    );
    exec($cmd, $output, $exitCode);
    if ($exitCode !== 0 || !file_exists($mp3Path) || filesize($mp3Path) === 0) {
        @unlink($mp3Path);
        return null;
    }

    return $mp3Name;
}

function forecast_audio_mime($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'mp3':  return 'audio/mpeg';
        case 'wav':  return 'audio/wav';
        case 'm4a':  return 'audio/mp4';
        case 'aac':  return 'audio/aac';
        case 'ogg':  return 'audio/ogg';
        default:     return 'application/octet-stream';
    }
}

// HH:MM:SS, not forecast_format_duration()'s M:SS — that one's for compact
// on-page/on-cover display; itunes:duration is conventionally the fuller
// timecode form and podcast apps expect it zero-padded throughout.
function forecast_rss_duration($seconds) {
    $seconds = (int) $seconds;
    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
}

// The blurb if the admin wrote one; otherwise a plain, non-Instagram-
// flavored fallback — forecast_caption() exists for the Reel post
// specifically (emoji, "Full schedule at cinematx.net" CTA) and isn't the
// right tone for a podcast app's episode description.
function forecast_feed_description(array $episode) {
    $blurb = trim((string) ($episode['blurb'] ?? ''));
    if ($blurb !== '') return $blurb;
    return 'This week\'s Film Forecast, with ' . $episode['guest_name'] . '.';
}

// ── Podcast episode art (square, for the RSS feed) ──────────────────────
//
// Podcast directories show one square image per episode — a raw guest
// photo (whatever crop/aspect ratio it happened to be uploaded at) never
// looked right there. This borrows the show's own wordmark language
// (paper, the red top bar, the big Fraunces lockup — see
// forecast_build_preshow_card()) with the week's date standing in for the
// show name, plus a small circular guest-photo badge in the corner — the
// same visual grammar as the Reel's own badge (forecast_draw_guest_badge())
// without sharing its Reel-specific band/position constants, since this
// canvas has no reserved ink band to sit inside.

const FORECAST_ART_SIZE = 1400; // matches assets/forecast-cover.png

function forecast_build_podcast_art(array $episode, $conn, $wallFilmsOverride = null) {
    $w = $h = FORECAST_ART_SIZE;
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);

    $paper       = ig_hex($im, '#F4F1EB');
    $ink         = ig_hex($im, '#14120F');
    $red         = ig_hex($im, '#922E32');
    $muted       = ig_hex($im, '#6B6659');
    $placeholder = ig_hex($im, '#E4DECE');

    imagefill($im, 0, 0, $paper);
    imagefilledrectangle($im, 0, 0, $w, 14, $red);
    imagefilledrectangle($im, 0, $h - 14, $w, $h, $red);

    $margin   = 100;
    $maxWidth = $w - $margin * 2;

    // Computed up front — the guest name line below needs to know where
    // the badge starts so it can stop clear of it, and the badge itself
    // is drawn after, once the photo/placeholder is decided.
    $badgeD      = 280;
    $badgeMargin = 150;
    $bx = $w - $badgeMargin - $badgeD;
    $by = $h - $badgeMargin - $badgeD;

    $y = 14 + 130;
    imagettftext($im, 32, 0, $margin, $y, $red, IG_FONT_BODY, strtoupper('Film Forecast'));
    $y += 90;

    // "WEEK OF" / the date, as a two-line lockup — the same shared
    // shrink-to-fit loop forecast_build_preshow_card() uses for its own
    // two-line wordmark, so a long date can't overflow the canvas any
    // more than a long show name could there.
    $lines = ['WEEK OF', strtoupper(date('M j', strtotime($episode['week_of'])))];
    $lineSize = 190;
    while ($lineSize > 60) {
        $fits = true;
        foreach ($lines as $line) {
            $bbox = imagettfbbox($lineSize, 0, IG_FONT_HEADLINE, $line);
            if ($bbox[2] - $bbox[0] > $maxWidth) { $fits = false; break; }
        }
        if ($fits) break;
        $lineSize -= 4;
    }
    foreach ($lines as $line) {
        $y += $lineSize;
        imagettftext($im, $lineSize, 0, $margin, $y, $ink, IG_FONT_HEADLINE, $line);
    }

    // The year, as a small subtitle under the lockup rather than folded
    // into the big line itself — keeps that line short enough to stay
    // legible at the small sizes podcast apps actually render this at.
    // Padded a touch past the headline's own left edge, the normal way a
    // subtitle sits slightly indented from the display line above it.
    $y += 60;
    imagettftext($im, 44, 0, $margin + 8, $y, $muted, IG_FONT_BODY, date('Y', strtotime($episode['week_of'])));

    // A "wall" of this week's posters as a background texture for the
    // lower portion of the square — the same automatic one-per-night pick
    // forecast_week_films() already uses for the Reel cover's own film
    // list, capped at 24 (3 rows of 8). Bottom-anchored so it always fills
    // the same footprint regardless of how many posters actually exist,
    // and floored against the text already drawn above so it can never
    // climb into the headline even on an unusually cramped date string.
    $wallCols   = 8;
    $wallRows   = 3;
    $wallGutter = 8;
    $thumbW = (int) floor(($maxWidth - ($wallCols - 1) * $wallGutter) / $wallCols);
    $thumbH = (int) round($thumbW * 1.5);
    $gridH  = $wallRows * $thumbH + ($wallRows - 1) * $wallGutter;
    $wallBottom = $h - 14 - 20;
    $gridTop = max($y + 30, $wallBottom - $gridH);

    // Once a selection is saved, that's the only real source left for
    // this week's posters — forecast_week_films() re-scrapes each venue's
    // *current* live schedule, which no longer carries anything from a
    // week that's already aired. TMDB lookups don't have that problem
    // (they're keyed by title, not by a still-live listing), so a saved
    // selection resolves the same way whether the episode is brand new
    // or long past. A curated selection is usually well under the grid's
    // 24-cell capacity, unlike a live week sized to the real thing —
    // cycled to fill it out rather than leaving most of the wall blank.
    if ($wallFilmsOverride !== null) {
        $wallFilms = $wallFilmsOverride;
    } elseif (!empty($episode['selected_films'])) {
        $wallFilms = forecast_wall_films_from_selection(json_decode($episode['selected_films'], true) ?: []);
    } else {
        $wallFilms = forecast_week_films($conn, $episode['week_of'], $wallCols * $wallRows)['films'];
    }
    $wallCapacity = $wallCols * $wallRows;
    if ($wallFilms && count($wallFilms) < $wallCapacity) {
        $wallFilms = array_map(fn($i) => $wallFilms[$i % count($wallFilms)], range(0, $wallCapacity - 1));
    }

    foreach ($wallFilms as $i => $f) {
        $row = intdiv($i, $wallCols);
        if ($row >= $wallRows) break;
        $col = $i % $wallCols;
        $px = $margin + $col * ($thumbW + $wallGutter);
        $py = $gridTop + $row * ($thumbH + $wallGutter);
        $thumb = ig_fetch_thumb($f['poster'] ?? null, $thumbW, $thumbH);
        if ($thumb) {
            imagecopy($im, $thumb, $px, $py, 0, 0, $thumbW, $thumbH);
            imagedestroy($thumb);
        } else {
            imagefilledrectangle($im, $px, $py, $px + $thumbW, $py + $thumbH, $placeholder);
        }
    }

    // Fades the top of the grid up into clean paper — same alpha-band
    // technique forecast_build_chapter_card() uses for its own hero fade,
    // just running the opposite direction (opaque paper at the grid's top
    // edge, fully transparent by $fadeH px down) so the wall dissolves in
    // under the date rather than cutting off hard.
    $fadeH = min(220, $gridH);
    for ($i = 0; $i < $fadeH; $i++) {
        $alpha = (int) round(127 * ($i / $fadeH));
        $band  = imagecolorallocatealpha($im, 0xF4, 0xF1, 0xEB, $alpha);
        imagefilledrectangle($im, 0, $gridTop + $i, $w, $gridTop + $i + 1, $band);
    }

    // "With" / the guest's name, on its own two lines, filling what was
    // otherwise open space in the lower-left — sized between the small
    // eyebrow and the huge headline, the pair vertically centered as a
    // block on the photo badge to its right (same 0.34×size baseline
    // offset forecast_draw_guest_badge() uses for its own centered
    // initial), and ig_fit_text()'d so a long name stops clear of the
    // badge rather than running into it. Sits on top of the poster wall,
    // so it gets a ~70%-opacity black scrim behind it first to stay
    // legible over whatever's underneath.
    $withSize  = 36;
    $nameSize  = 60;
    $lineGap   = 64; // baseline-to-baseline
    $guestGap  = 50;
    $guestMaxWidth = $bx - $guestGap - $margin;

    $withBaselineY = (int) round($by + $badgeD / 2 - $lineGap / 2 + $withSize * 0.32);
    $nameBaselineY = $withBaselineY + $lineGap;
    $nameText = ig_fit_text($episode['guest_name'], IG_FONT_BODY, $nameSize, $guestMaxWidth);

    $withBox = imagettfbbox($withSize, 0, IG_FONT_BODY, 'With');
    $nameBox = imagettfbbox($nameSize, 0, IG_FONT_BODY, $nameText);
    $scrimW  = max($withBox[2] - $withBox[0], $nameBox[2] - $nameBox[0]);
    $scrimPadX = 24; $scrimPadY = 18;
    $scrim = imagecolorallocatealpha($im, 0x14, 0x12, 0x0F, (int) round(127 * 0.3));
    imagefilledrectangle(
        $im,
        $margin - $scrimPadX,
        $withBaselineY - $withSize - $scrimPadY,
        $margin + $scrimW + $scrimPadX,
        $nameBaselineY + (int) round($nameSize * 0.28) + $scrimPadY,
        $scrim
    );

    imagettftext($im, $withSize, 0, $margin, $withBaselineY, $paper, IG_FONT_BODY, 'With');
    imagettftext($im, $nameSize, 0, $margin, $nameBaselineY, $paper, IG_FONT_BODY, $nameText);

    // Guest-photo badge, bottom-right — same circular-crop technique as
    // forecast_draw_guest_badge(), independently positioned.
    $shadow = imagecolorallocatealpha($im, 0, 0, 0, 90);
    imagefilledellipse($im, (int) ($bx + $badgeD / 2) + 4, (int) ($by + $badgeD / 2) + 6, $badgeD + 14, $badgeD + 14, $shadow);
    imagefilledellipse($im, (int) ($bx + $badgeD / 2), (int) ($by + $badgeD / 2), $badgeD + 10, $badgeD + 10, $red);

    $photoUrl = !empty($episode['guest_photo']) ? '/uploads/forecast/' . $episode['guest_photo'] : null;
    $photoSrc = $photoUrl ? ig_fetch_thumb($photoUrl, $badgeD, $badgeD) : null;
    if ($photoSrc) {
        $circle = forecast_circle_crop($photoSrc, $badgeD);
        imagedestroy($photoSrc);
        imagecopy($im, $circle, $bx, $by, 0, 0, $badgeD, $badgeD);
        imagedestroy($circle);
    } else {
        imagefilledellipse($im, (int) ($bx + $badgeD / 2), (int) ($by + $badgeD / 2), $badgeD, $badgeD, $placeholder);
        $initial = mb_strtoupper(mb_substr($episode['guest_name'], 0, 1));
        $ibox = imagettfbbox(90, 0, IG_FONT_HEADLINE, $initial);
        $iw = $ibox[2] - $ibox[0];
        imagettftext($im, 90, 0, (int) ($bx + $badgeD / 2 - $iw / 2), (int) ($by + $badgeD / 2 + 32), $muted, IG_FONT_HEADLINE, $initial);
    }

    return $im;
}

// Resolves forecast_save_selection()'s saved keys (ig_film_key()'s
// "title|venue|location", lowercased) back into just enough film shape
// for the wall — a poster — via TMDB. Only the title half of each key is
// actually usable this way; venue/location can't be recovered once the
// week's live listing is gone, but the wall never displayed them either.
function forecast_wall_films_from_selection(array $keys) {
    $out = [];
    foreach ($keys as $key) {
        $title = trim(explode('|', $key)[0] ?? '');
        if ($title === '') continue;
        $out[] = ['poster' => fetch_tmdb($title)['poster']];
    }
    return $out;
}

// Cached to disk keyed by the fields that actually affect the render — a
// changed guest photo, week, or selection gets a new filename
// automatically, so there's no explicit invalidation step, only an
// orphaned old file left behind (same tradeoff list/instagram.php's
// poster_cache already makes).
function forecast_podcast_art_path(array $episode) {
    $key = $episode['id'] . '|' . $episode['week_of'] . '|' . $episode['guest_name'] . '|'
         . ($episode['guest_photo'] ?? '') . '|' . ($episode['selected_films'] ?? '');
    return dirname(__DIR__) . '/uploads/forecast/art-' . substr(md5($key), 0, 12) . '.png';
}

function forecast_ensure_podcast_art(array $episode, $conn) {
    $path = forecast_podcast_art_path($episode);
    if (!file_exists($path)) {
        $im = forecast_build_podcast_art($episode, $conn);
        imagepng($im, $path);
        imagedestroy($im);
    }
    return $path;
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

// The top and bottom bands ffmpeg composites the waveform (and, at the
// bottom, the progress bar) into afterward — the cover render itself never
// draws into either, so there's nothing underneath for the overlay to
// fight with.
const FORECAST_TOP_WAVE_BAND_H = 130;
const FORECAST_WAVE_BAND_Y     = 1650;

// The guest-photo badge — one size, one position, on every panel (the
// cover/intro and every chapter card alike) via forecast_draw_guest_badge()
// below, rather than each caller picking its own. Sits inside the bottom
// ink band, not above it, specifically so it can share a vertical center
// with the waveform ffmpeg composites into that same band afterward (see
// forecast_generate_video()) — a fixed "media player" row rather than a
// badge floating separately above a waveform underneath it.
const FORECAST_BADGE_D = 190;
const FORECAST_BADGE_MARGIN = 80;

// The Y every bottom-band chrome element (the badge, and ffmpeg's own
// waveform overlay) centers on — the vertical middle of the ink band
// itself, so nothing in that band reads as arbitrarily placed relative
// to anything else in it.
function forecast_bottom_row_center_y() {
    return FORECAST_WAVE_BAND_Y + (int) round((1920 - FORECAST_WAVE_BAND_Y) / 2);
}

// How long each segment-to-segment fade takes in the generated video —
// see forecast_generate_video(). Short on purpose: a beat between films,
// not a slow dissolve.
const FORECAST_SEGMENT_FADE = 1.0;

// How much runtime the wrap-up card gets at the end of the video, and the
// minimum room the last chapter needs before it before one gets carved
// out at all — see bin/forecast-generate.php's segment assembly.
const FORECAST_WRAPUP_SECONDS = 15;

// How long the preshow title card holds at the very start of the video,
// under the short music-only intro before the episode itself begins — see
// forecast_build_preshow_card() below and bin/forecast-generate.php's
// segment assembly, which now opens on this instead of the intro/cover.
const FORECAST_PRESHOW_SECONDS = 5;

// Whether a dedicated closing beat fits at all, and if so where it
// starts — null otherwise, meaning the last chapter (or the intro, if
// there are no chapters) just runs to the end instead. Shared by
// bin/forecast-generate.php (decides whether to render the wrap-up
// segment) and _admin/forecast_episode.php (shows it on the timeline) so
// the same threshold can't drift between what the video actually does
// and what the timeline says it'll do.
function forecast_wrapup_start(array $chapters, $duration) {
    $lastStart = $chapters ? end($chapters)['start'] : 0;
    if ($duration - $lastStart > FORECAST_WRAPUP_SECONDS * 2) {
        return $duration - FORECAST_WRAPUP_SECONDS;
    }
    return null;
}

// Every unique screening this week (a film playing three nights only
// counts once), grouped by calendar day and chronologically ordered within
// each — the one source both the automatic picker and the manual
// checklist (forecast_all_week_films(), _admin/forecast_episode.php) read
// from, so they can never disagree about what's actually playing.
function forecast_week_by_day($conn, $weekOf) {
    $start = strtotime($weekOf);
    $end   = strtotime('+7 day', $start) - 1;
    $films = fetch_all_screenings($conn, $start, $end, false);
    // IG_VENUES on its own is the *daily Instagram carousel's* venue
    // list — Alamo is deliberately excluded from it (it gets its own
    // separate carousel flow, ig_alamo_films()/_admin/instagram_alamo.php,
    // for reasons specific to that feature: opt-in stacking, one
    // repeat-showing card per day). None of that applies to Forecast,
    // which just wants every film actually playing this week, so Alamo
    // is added on here rather than into the shared constant — folding it
    // into IG_VENUES itself would silently pull Alamo into the daily
    // carousel too. Its five locations collapse under one venue name
    // ('Alamo Drafthouse') the same way the other three venues would if
    // they had branches — the title-only dedup below already merges a
    // film across every location and night it plays into one entry with
    // several showtimes, exactly like a same-title booking spanning two
    // "real" venues.
    $films = array_values(array_filter($films, fn($f) => in_array($f['venue'], IG_VENUES, true) || $f['venue'] === IG_ALAMO_VENUE));
    $films = ctx_enrich($films);

    // A film playing more than once this week (a repertory favorite
    // showing Tuesday and Thursday, say) used to just lose every
    // occurrence after its first — fine when all a film needed was to
    // exist once in the list, a real gap once the chapter card started
    // needing to say when it's actually showing. Every occurrence is
    // now kept as one entry in $showtimes (own timestamp/venue/location
    // each, in case a same-title booking ever genuinely spans two —
    // see forecast_format_showtimes()), grouped under the film's first
    // occurrence rather than becoming a second, duplicate row.
    $byTitle = [];
    $order = [];
    foreach ($films as $f) {
        $title = !empty($f['display_title']) ? $f['display_title'] : $f['title'];
        $key = mb_strtolower($title);
        $showtime = ['timestamp' => $f['timestamp'], 'venue' => $f['venue'], 'location' => $f['location'] ?? null];
        if (isset($byTitle[$key])) {
            $byTitle[$key]['showtimes'][] = $showtime;
            continue;
        }
        $byTitle[$key] = $f + ['display_title' => $title, 'showtimes' => [$showtime]];
        $order[] = $key;
    }

    $byDay = [];
    foreach ($order as $key) {
        $f = $byTitle[$key];
        $byDay[date('Y-m-d', $f['timestamp'])][] = $f;
    }
    ksort($byDay);
    return $byDay;
}

// One line covering every night a film plays this week, not just its
// first ("Mon, 7:30pm & Thu, 9:00pm" for a repertory favorite showing
// twice) — the same single value it's always been for the far more
// common film showing only once. Venue folds in once at the end when
// every showtime shares one (the normal case); a same-title booking
// that genuinely spans two venues in one week gets each showtime
// labeled with its own instead of silently showing the wrong one.
function forecast_format_showtimes(array $showtimes) {
    usort($showtimes, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
    $venueKeys = array_unique(array_map(fn($s) => $s['venue'] . '|' . ($s['location'] ?? ''), $showtimes));
    $sameVenue = count($venueKeys) === 1;

    $parts = [];
    foreach ($showtimes as $s) {
        $when = date('D, g:ia', $s['timestamp']);
        if ($sameVenue) {
            $parts[] = $when;
        } else {
            $venueBit = !empty($s['location']) ? "{$s['venue']} — {$s['location']}" : $s['venue'];
            $parts[] = "{$when} ({$venueBit})";
        }
    }

    $line = implode(' & ', $parts);
    if ($sameVenue && $showtimes) {
        $venueBit = !empty($showtimes[0]['location']) ? "{$showtimes[0]['venue']} — {$showtimes[0]['location']}" : $showtimes[0]['venue'];
        $line .= '   ·   ' . $venueBit;
    }
    return $line;
}

// Every night that has a screening gets its first pick before any night
// gets a second, so a busy Monday can't fill the whole row budget before
// the rest of the week is ever considered. Unlimited unless $limit is
// given — forecast_week_films() below slices it for the automatic cover
// default; forecast_resolve_selection()'s own fallback uses the same order.
function forecast_round_robin_order(array $byDay) {
    $ordered = [];
    for ($round = 0; ; $round++) {
        $addedThisRound = false;
        foreach ($byDay as $dayFilms) {
            if (isset($dayFilms[$round])) {
                $ordered[] = $dayFilms[$round];
                $addedThisRound = true;
            }
        }
        if (!$addedThisRound) break;
    }
    return $ordered;
}

// Every unique film this week, in the order it actually plays — not
// round-robin. What the package export's panel list and
// forecast_resolve_selection()'s own default both want: the whole week,
// chronologically, not fairness-across-nights.
function forecast_flat_week_films(array $byDay) {
    $flat = [];
    foreach ($byDay as $dayFilms) {
        foreach ($dayFilms as $f) $flat[] = $f;
    }
    return $flat;
}

// The automatic cover default — round-robin, capped at $limit rows, with
// the true remaining count so the cover can show a "+N more this week"
// line exactly like ig_build_list_page()'s own overflow handling.
function forecast_week_films($conn, $weekOf, $limit = 9) {
    $ordered = forecast_round_robin_order(forecast_week_by_day($conn, $weekOf));
    return [
        'films' => array_slice($ordered, 0, $limit),
        'more'  => max(0, count($ordered) - $limit),
    ];
}

// Every unique film this week, still grouped by day — what
// _admin/forecast_episode.php's checklist renders one section per night
// from.
function forecast_all_week_films($conn, $weekOf) {
    return forecast_week_by_day($conn, $weekOf);
}

// Shared by _admin/forecast_selection_save.php (save without generating)
// and _admin/forecast_generate.php (generate always saves the exact
// selection it's about to render first, so "what I just checked" and
// "what the video actually shows" can never drift apart). Only keys that
// are actually playing this week are kept — the day's real data is the
// source of truth for what a valid key looks like, same rule every other
// checklist save in this project already follows.
function forecast_save_selection($conn, $episode_id, $uid, array $byDay, array $posted) {
    $valid = [];
    foreach ($byDay as $dayFilms) {
        foreach ($dayFilms as $f) $valid[] = ig_film_key($f);
    }
    $keys = array_values(array_intersect($posted, $valid));
    $conn->prepare("UPDATE `forecast_episodes` SET selected_films = :films WHERE id = :id AND uid = :uid")
         ->execute([':films' => json_encode($keys), ':id' => $episode_id, ':uid' => $uid]);
    return $keys;
}

/**
 * Which films get a spoken-commentary chapter in the real video, resolved
 * in the same override-beats-saved-beats-default order the daily
 * carousel's own ig_carousel_selection() already established:
 *   1. $override (film keys from $_GET during live preview) if given at
 *      all — even an empty array, since unchecking everything is a
 *      deliberate choice that deserves to be reflected in the mockup.
 *   2. The episode's saved selected_films, if it's ever been touched.
 *   3. Every film this week, chronologically (forecast_flat_week_films())
 *      — every film gets a chapter unless explicitly unchecked, now that
 *      nothing about the cover's own design caps how many films can be
 *      shown (see forecast_build_cover()'s poster wall).
 * Returns a flat, chronologically-ordered film list either way — the
 * order the checklist itself presents them in, so a saved selection can
 * only ever be a subset of that same order.
 */
function forecast_resolve_selection(array $episode, array $byDay, $override = null) {
    $flat = forecast_flat_week_films($byDay);

    $wantedKeys = null;
    if ($override !== null) {
        $wantedKeys = $override;
    } else {
        $saved = $episode['selected_films'] ?? null;
        if ($saved !== null && $saved !== '') {
            $wantedKeys = json_decode($saved, true) ?: [];
        }
    }

    if ($wantedKeys === null) {
        return $flat;
    }

    $keys = array_flip($wantedKeys);
    return array_values(array_filter($flat, fn($f) => isset($keys[ig_film_key($f)])));
}

// ── Chapters (segment timing) ───────────────────────────────────────────
//
// A chapter only ever exists for a film that's currently selected — the
// checklist stays the single source of truth for *which* films are in the
// episode, chapters only add *when*. Same reasoning selected_films itself
// follows relative to the automatic default.

/**
 * Every currently-selected film gets a chapter, ordered by start time.
 * A film with a saved start keeps it; a film with none (never placed, or
 * newly checked since chapters were last saved) gets an evenly-spread
 * default based on its position in $selectedFilms — a starting point to
 * drag from, not meant to be precise. Films no longer selected are simply
 * absent from $selectedFilms already, so their old saved chapter (if any)
 * quietly drops out here rather than needing an explicit prune step.
 */
function forecast_resolve_chapters(array $selectedFilms, $savedChaptersJson, $durationSeconds) {
    $saved = [];
    if ($savedChaptersJson) {
        foreach ((json_decode($savedChaptersJson, true) ?: []) as $c) {
            if (isset($c['film'], $c['start'])) $saved[$c['film']] = (float) $c['start'];
        }
    }

    $count = count($selectedFilms);
    $chapters = [];
    foreach (array_values($selectedFilms) as $i => $film) {
        $key = ig_film_key($film);
        $start = $saved[$key] ?? ($count > 0 ? round($durationSeconds * $i / $count) : 0);
        $chapters[] = ['film' => $key, 'start' => (float) $start, 'title' => $film['display_title'] ?? $film['title'], 'data' => $film];
    }

    usort($chapters, fn($a, $b) => $a['start'] <=> $b['start']);
    return $chapters;
}

// Mirrors forecast_save_selection()'s shape exactly: only chapters whose
// film is actually in the current selection are kept, start times clamped
// into range so a stray client-side drag can't save something nonsensical.
function forecast_save_chapters($conn, $episode_id, $uid, array $selectedFilmKeys, array $postedChapters, $durationSeconds) {
    $validKeys = array_flip($selectedFilmKeys);
    $out = [];
    foreach ($postedChapters as $c) {
        if (!isset($c['film'], $c['start'])) continue;
        if (!isset($validKeys[$c['film']])) continue;
        $start = max(0, min((float) $durationSeconds, (float) $c['start']));
        $out[] = ['film' => $c['film'], 'start' => $start];
    }
    usort($out, fn($a, $b) => $a['start'] <=> $b['start']);
    $conn->prepare("UPDATE `forecast_episodes` SET chapters = :chapters WHERE id = :id AND uid = :uid")
         ->execute([':chapters' => json_encode($out), ':id' => $episode_id, ':uid' => $uid]);
    return $out;
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

// The guest-photo badge, identical on every panel — see FORECAST_BADGE_D
// and forecast_bottom_row_center_y() above for why. Call this only after
// the caller has already filled in the ink band
// (imagefilledrectangle(..., FORECAST_WAVE_BAND_Y, ..., $ink)) — the
// badge needs to sit on top of that fill, not under it.
function forecast_draw_guest_badge($im, array $episode) {
    $w = imagesx($im);
    $badgeD = FORECAST_BADGE_D;
    $bx = $w - FORECAST_BADGE_MARGIN - $badgeD;
    $by = forecast_bottom_row_center_y() - (int) round($badgeD / 2);

    $red   = ig_hex($im, '#922E32');
    $muted = ig_hex($im, '#6B6659');
    $placeholder = ig_hex($im, '#E4DECE');

    $photoUrl = !empty($episode['guest_photo']) ? '/uploads/forecast/' . $episode['guest_photo'] : null;

    $shadow = imagecolorallocatealpha($im, 0, 0, 0, 90);
    imagefilledellipse($im, (int) ($bx + $badgeD / 2) + 3, (int) ($by + $badgeD / 2) + 4, $badgeD + 10, $badgeD + 10, $shadow);
    imagefilledellipse($im, (int) ($bx + $badgeD / 2), (int) ($by + $badgeD / 2), $badgeD + 8, $badgeD + 8, $red);

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
        $ibox = imagettfbbox(70, 0, IG_FONT_HEADLINE, $initial);
        $iw = $ibox[2] - $ibox[0];
        imagettftext($im, 70, 0, (int) ($bx + $badgeD / 2 - $iw / 2), (int) ($by + $badgeD / 2 + 24), $muted, IG_FONT_HEADLINE, $initial);
    }
}

function forecast_build_cover(array $episode, $conn, $filmsOverride = null, $totalThisWeek = null, $liveDuration = false, &$durationSlot = null) {
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

    // Dark, same as the bottom band — so the gold waveform ffmpeg composites
    // in here afterward reads the same way in both places, and the two
    // bands bookend the cream list content between them.
    $topBandEnd = 14 + FORECAST_TOP_WAVE_BAND_H;
    imagefilledrectangle($im, 0, 14, $w, $topBandEnd, $ink);

    $margin = 80;
    $y = $topBandEnd + 96;

    imagettftext($im, 28, 0, $margin, $y, $red, IG_FONT_BODY, strtoupper('Film Forecast'));
    $y += 90;

    // The largest text on the page — this is the headline now, the guest
    // is the byline underneath it, a deliberate flip from the first design
    // (guest photo full-bleed, guest name as the title). Shrinks to fit
    // one line rather than wrapping or running off the edge — "WEEK OF"
    // plus a short date is usually fine at 96px, but a longer week_of
    // format shouldn't be able to overflow the canvas.
    $weekOf = 'WEEK OF ' . strtoupper(date('M j', strtotime($episode['week_of'])));
    $headlineMaxWidth = $w - $margin * 2;
    $headlineSize = 96;
    while ($headlineSize > 40) {
        $bbox = imagettfbbox($headlineSize, 0, IG_FONT_HEADLINE, $weekOf);
        if ($bbox[2] - $bbox[0] <= $headlineMaxWidth) break;
        $headlineSize -= 2;
    }
    imagettftext($im, $headlineSize, 0, $margin, $y, $ink, IG_FONT_HEADLINE, $weekOf);
    $y += 70;

    $bylinePrefix = 'with ' . $episode['guest_name'];
    $hasDuration = !empty($episode['duration_seconds']);
    if ($hasDuration) $bylinePrefix .= '   ·   ';
    imagettftext($im, 34, 0, $margin, $y, $muted, IG_FONT_BODY, $bylinePrefix);

    if ($hasDuration) {
        $prefixBbox = imagettfbbox(34, 0, IG_FONT_BODY, $bylinePrefix);
        $durationX = $margin + ($prefixBbox[2] - $prefixBbox[0]);
        if ($liveDuration) {
            // Left blank here on purpose — ffmpeg draws a live elapsed/total
            // counter into this exact spot at render time (see
            // forecast_generate_video()'s $durationSlot). Measured off a
            // representative sample string at the same font/size so the
            // slot handed to ffmpeg lines up with this baseline exactly,
            // rather than an offset re-derived independently over there and
            // risking drift from whatever this function actually drew.
            $sample = sprintf('%02d:%02d / %02d:%02d', 0, 0, intdiv($episode['duration_seconds'], 60), $episode['duration_seconds'] % 60);
            $sampleBbox = imagettfbbox(34, 0, IG_FONT_BODY, $sample);
            $durationSlot = ['x' => $durationX, 'y' => $y + $sampleBbox[5], 'size' => 34];
        } else {
            imagettftext($im, 34, 0, $durationX, $y, $muted, IG_FONT_BODY, forecast_format_duration($episode['duration_seconds']));
        }
    }
    $y += 50;

    imagefilledrectangle($im, $margin, $y, $w - $margin, $y + 1, $divider);
    $y += 50;

    // A wall of this week's posters as background texture — replaces the
    // old enumerated list now that every film gets its own dedicated
    // panel elsewhere (see forecast_build_chapter_card()), so this card
    // no longer needs to name names, just signal "a lot is playing this
    // week." Same wall technique forecast_build_podcast_art() uses for
    // the RSS feed art, adapted to this canvas's portrait shape and
    // reserved ink bands: cycles to fill if the selection is short,
    // truncates if it's long, bottom-anchored so it always fills the
    // same footprint regardless of count. $totalThisWeek is unused now
    // (kept as a parameter — both call sites still pass it positionally,
    // not worth a signature change for no behavior).
    $films = $filmsOverride !== null ? $filmsOverride : forecast_week_films($conn, $episode['week_of'], 60)['films'];

    $wallCols   = 6;
    $wallGutter = 8;
    $wallMaxWidth = $w - $margin * 2;
    $thumbW = (int) floor(($wallMaxWidth - ($wallCols - 1) * $wallGutter) / $wallCols);
    $thumbH = (int) round($thumbW * 1.5);
    $wallBottom = FORECAST_WAVE_BAND_Y - 60;
    $wallTop    = $y + 20;
    $wallRows   = max(1, (int) floor(($wallBottom - $wallTop + $wallGutter) / ($thumbH + $wallGutter)));
    $gridH      = $wallRows * $thumbH + ($wallRows - 1) * $wallGutter;
    $gridTop    = $wallBottom - $gridH;

    $wallCapacity = $wallCols * $wallRows;
    if ($films && count($films) < $wallCapacity) {
        $films = array_map(fn($i) => $films[$i % count($films)], range(0, $wallCapacity - 1));
    }

    foreach ($films as $i => $f) {
        $row = intdiv($i, $wallCols);
        if ($row >= $wallRows) break;
        $col = $i % $wallCols;
        $px = $margin + $col * ($thumbW + $wallGutter);
        $py = $gridTop + $row * ($thumbH + $wallGutter);
        $thumb = ig_fetch_thumb($f['poster'] ?? null, $thumbW, $thumbH);
        if ($thumb) {
            imagecopy($im, $thumb, $px, $py, 0, 0, $thumbW, $thumbH);
            imagedestroy($thumb);
        } else {
            imagefilledrectangle($im, $px, $py, $px + $thumbW, $py + $thumbH, $placeholder);
        }
    }

    // Fades the top of the grid up into clean paper — same alpha-band
    // technique forecast_build_podcast_art() uses for its own wall (GD
    // has no gradient primitive of its own).
    $fadeH = min(160, $gridH);
    for ($i = 0; $i < $fadeH; $i++) {
        $alpha = (int) round(127 * ($i / $fadeH));
        $band  = imagecolorallocatealpha($im, 0xF4, 0xF1, 0xEB, $alpha);
        imagefilledrectangle($im, 0, $gridTop + $i, $w, $gridTop + $i + 1, $band);
    }

    // Ink band first, badge on top of it — see forecast_draw_guest_badge()
    // for why the badge needs to sit inside this band now rather than
    // above it.
    imagefilledrectangle($im, 0, FORECAST_WAVE_BAND_Y, $w, $h, $ink);
    forecast_draw_guest_badge($im, $episode);

    return $im;
}

// ── Segment cards (dynamic video) ───────────────────────────────────────
//
// The video opens on forecast_build_preshow_card() (below) for the first
// FORECAST_PRESHOW_SECONDS, then its base state — the intro segment, and
// the wrap-up at the end reusing the same design — is forecast_build_cover()
// itself: title, week of, the film list, the guest photo. Nothing new to
// build for that; see bin/forecast-generate.php's segment assembly, which
// calls forecast_build_cover() directly the same way the admin preview
// already does. What follows the preshow is only the per-chapter "now
// watching" card.

// The opening title beat — plays under the few seconds of music-only
// intro before the episode itself starts, so there's something to look at
// besides a still frame while the audio hasn't said anything yet. Same
// visual language as the show's own podcast-platform cover art
// (assets/forecast-cover.png: paper, the red bars, "CINEMA, TX" eyebrow,
// the big two-line wordmark) rather than a new design, adapted to this
// frame's portrait aspect and reserved top/bottom bands. No episode data
// in it at all, deliberately — this is the show's own ident, not this
// week's — so it's the one card here that takes no arguments and carries
// no guest badge. A first pass, expected to develop over time.
function forecast_build_preshow_card() {
    $w = 1080;
    $h = 1920;
    $im = imagecreatetruecolor($w, $h);

    $paper = ig_hex($im, '#F4F1EB');
    $ink   = ig_hex($im, '#14120F');
    $red   = ig_hex($im, '#922E32');
    $muted = ig_hex($im, '#6B6659');

    imagefill($im, 0, 0, $paper);
    imagefilledrectangle($im, 0, 0, $w, 14, $red);

    $topBandEnd = 14 + FORECAST_TOP_WAVE_BAND_H;
    imagefilledrectangle($im, 0, 14, $w, $topBandEnd, $ink);

    $margin = 80;
    $maxWidth = $w - $margin * 2;

    // "FILM" / "FORECAST" as two lines at one shared size, not each
    // shrunk independently to its own best fit — a single size keeps the
    // wordmark reading as one lockup instead of two mismatched words.
    $lines = ['FILM', 'FORECAST'];
    $lineSize = 210;
    while ($lineSize > 60) {
        $fits = true;
        foreach ($lines as $line) {
            $bbox = imagettfbbox($lineSize, 0, IG_FONT_HEADLINE, $line);
            if ($bbox[2] - $bbox[0] > $maxWidth) { $fits = false; break; }
        }
        if ($fits) break;
        $lineSize -= 4;
    }

    $eyebrowSize  = 34;
    $eyebrowGap   = 44;
    $titleLineH   = $lineSize + 26;
    $dividerGap   = 56;
    $dividerH     = 6;
    $subGap       = 66;
    $subSize      = 28;

    $blockH = $eyebrowSize + $eyebrowGap + $titleLineH * count($lines) + $dividerGap + $dividerH + $subGap + $subSize;
    $contentTop = $topBandEnd;
    $contentH   = FORECAST_WAVE_BAND_Y - $contentTop;
    $y = $contentTop + (int) round(($contentH - $blockH) / 2) + $eyebrowSize;

    imagettftext($im, $eyebrowSize, 0, $margin, $y, $red, IG_FONT_BODY, strtoupper('Cinema, TX'));
    $y += $eyebrowGap;

    $lastBaseline = $y;
    foreach ($lines as $line) {
        $y += $lineSize;
        imagettftext($im, $lineSize, 0, $margin, $y, $ink, IG_FONT_HEADLINE, $line);
        $lastBaseline = $y;
        $y += $titleLineH - $lineSize;
    }

    $y = $lastBaseline + $dividerGap;
    imagefilledrectangle($im, $margin, $y, $w - $margin, $y + $dividerH, $red);
    $y += $dividerH + $subGap;

    imagettftext($im, $subSize, 0, $margin, $y, $muted, IG_FONT_BODY, strtoupper('A Weekly Film Podcast'));

    imagefilledrectangle($im, 0, FORECAST_WAVE_BAND_Y, $w, $h, $ink);

    return $im;
}

// One per chapter — a full-frame "now watching" card: poster, title,
// venue/director. Same reserved top/bottom bands as every other frame in
// this video, so ffmpeg's waveform/progress-bar/timer overlays land
// identically regardless of which card is showing underneath. Also
// carries over the intro/cover's own branding — the "FILM FORECAST"
// eyebrow with the guest's name, and the same small circular guest-photo
// badge forecast_build_cover() uses — so a chapter card still reads as
// part of the same episode rather than a bare film fact-sheet, in case a
// viewer starts watching partway through.
//
// Mimics list/instagram.php's own single-film spotlight page
// (ig_build_feature_page_paper()) for everything film-specific — hero,
// title, deck, divider, overview, director — in Forecast's own
// paper/red/ink palette. Same hero-fade, text-wrapping, and circle-crop
// techniques (ig_hero_url(), ig_poster_crop_bias(), ig_wrap_lines(),
// forecast_circle_crop()) reused as-is rather than re-solved here.
function forecast_build_chapter_card(array $film, array $episode, $showShowtimes = true) {
    $w = 1080;
    $h = 1920;
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);

    $paper   = ig_hex($im, '#F4F1EB');
    $ink     = ig_hex($im, '#14120F');
    $red     = ig_hex($im, '#922E32');
    $muted   = ig_hex($im, '#6B6659');
    $divider = ig_hex($im, '#DED7C7');
    $placeholder = ig_hex($im, '#E4DECE');

    imagefill($im, 0, 0, $paper);
    imagefilledrectangle($im, 0, 0, $w, 14, $red);

    $topBandEnd = 14 + FORECAST_TOP_WAVE_BAND_H;
    imagefilledrectangle($im, 0, 14, $w, $topBandEnd, $ink);

    $margin = 80;
    $textMaxWidth = $w - $margin * 2;
    $title = !empty($film['display_title']) ? $film['display_title'] : $film['title'];

    // Same branding the intro/cover opens on — the eyebrow (week of,
    // not "now watching" — this is what episode you're in, the film
    // title just below already says what's on screen) and the "with
    // GUEST" byline — so a chapter card still reads as part of this
    // episode rather than a bare film fact-sheet, in case a viewer starts
    // watching partway through.
    $y = $topBandEnd + 50;
    $weekOfLabel = strtoupper('Film Forecast · Week of ' . date('M j', strtotime($episode['week_of'])));
    imagettftext($im, 24, 0, $margin, $y, $red, IG_FONT_BODY, $weekOfLabel);
    $y += 34;
    imagettftext($im, 22, 0, $margin, $y, $muted, IG_FONT_BODY, 'with ' . $episode['guest_name']);
    $y += 44;

    // Landscape hero, not a portrait poster — the same crop treatment (and
    // the same admin-adjustable crop bias) the Instagram spotlight page
    // uses, so a "now watching" card actually reads like the spotlight
    // it's meant to mimic rather than a poster thumbnail blown up.
    $heroH   = 640;
    $heroUrl = ig_hero_url($film['poster'] ?? null);
    $hero    = ig_fetch_thumb($heroUrl, $w, $heroH, ig_poster_crop_bias($heroUrl));
    if ($hero) {
        imagecopy($im, $hero, 0, $y, 0, 0, $w, $heroH);
        imagedestroy($hero);
    } else {
        imagefilledrectangle($im, 0, $y, $w, $y + $heroH, $placeholder);
        $initial = mb_strtoupper(mb_substr($title, 0, 1));
        $ibox = imagettfbbox(110, 0, IG_FONT_HEADLINE, $initial);
        $iw = $ibox[2] - $ibox[0];
        imagettftext($im, 110, 0, (int) (($w - $iw) / 2), $y + (int) ($heroH / 2) + 38, $muted, IG_FONT_HEADLINE, $initial);
    }

    // Fades the hero into the paper background over its last stretch,
    // same alpha-band technique ig_build_feature_page_paper() uses —
    // GD has no gradient primitive of its own.
    $fadeH = 100;
    for ($i = 0; $i < $fadeH; $i++) {
        $alpha = (int) round(127 * (1 - $i / $fadeH));
        $band  = imagecolorallocatealpha($im, 0xF4, 0xF1, 0xEB, $alpha);
        imagefilledrectangle($im, 0, $y + $heroH - $fadeH + $i, $w, $y + $heroH - $fadeH + $i + 1, $band);
    }
    $y += $heroH + 44;

    $titleLines = ig_wrap_lines(mb_strtoupper($title), IG_FONT_HEADLINE, 50, $textMaxWidth, 2);
    foreach ($titleLines as $line) {
        imagettftext($im, 50, 0, $margin, $y, $ink, IG_FONT_HEADLINE, $line);
        $y += 60;
    }

    // Genre + runtime, the same deck line the spotlight page keeps under
    // its title (minus year — less relevant once a film is already
    // showing than what it is and how long it runs).
    $deckParts = array_filter([$film['genres'] ?? null, !empty($film['runtime']) ? round($film['runtime']) . ' min' : null]);
    if ($deckParts) {
        $deck = ig_fit_text(implode('  ·  ', $deckParts), IG_FONT_BODY, 24, $textMaxWidth);
        imagettftext($im, 24, 0, $margin, $y, $muted, IG_FONT_BODY, $deck);
        $y += 38;
    }

    // Every night this film actually plays this week, not just one — the
    // two facts the spotlight page keeps as pills over its hero, folded
    // into a single line in the brand red since this frame's real estate
    // is shared with the overview below. Falls back to just $timestamp
    // if $showtimes is somehow missing (a caller not going through
    // forecast_week_by_day()) rather than showing nothing.
    $showtimes = !empty($film['showtimes']) ? $film['showtimes']
        : (!empty($film['timestamp']) ? [['timestamp' => $film['timestamp'], 'venue' => $film['venue'] ?? null, 'location' => $film['location'] ?? null]] : []);
    if ($showShowtimes && $showtimes) {
        $meta = ig_fit_text(forecast_format_showtimes($showtimes), IG_FONT_BODY, 24, $textMaxWidth);
        imagettftext($im, 24, 0, $margin, $y, $red, IG_FONT_BODY, $meta);
        $y += 40;
    }

    $y += 14;
    imagefilledrectangle($im, $margin, $y, $w - $margin, $y + 1, $divider);
    $y += 34;

    if (!empty($film['overview'])) {
        foreach (ig_wrap_lines($film['overview'], IG_FONT_BODY, 25, $textMaxWidth, 5) as $line) {
            imagettftext($im, 25, 0, $margin, $y, $ink, IG_FONT_BODY, $line);
            $y += 36;
        }
    }

    if (!empty($film['director'])) {
        $y += 12;
        $dir = ig_fit_text('Dir. ' . $film['director'], IG_FONT_BODY, 23, $textMaxWidth);
        imagettftext($im, 23, 0, $margin, $y, $muted, IG_FONT_BODY, $dir);
    }

    // Ink band first, badge on top of it, same shared helper
    // forecast_build_cover() uses — one size and position on every panel,
    // carrying the episode's own branding all the way through rather
    // than just at the intro.
    imagefilledrectangle($im, 0, FORECAST_WAVE_BAND_Y, $w, $h, $ink);
    forecast_draw_guest_badge($im, $episode);

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

// ── Generation progress ─────────────────────────────────────────────────
//
// A tiny JSON file per episode is the whole mechanism: bin/forecast-
// generate.php (launched detached by _admin/forecast_generate.php) writes
// it as ffmpeg runs, _admin/forecast_progress.php just reads it back for
// the browser to poll. Its own 'status' field doubles as the lock that
// stops a second "Generate" click from starting a duplicate ffmpeg run —
// no separate lock file needed.

// $kind distinguishes the real video's progress from the package/waveform
// exports' own, all three running independently of one another. 'video'
// keeps the original, un-suffixed filename — nothing about the
// already-working real pipeline changes shape.
function forecast_progress_path($episode_id, $kind = 'video') {
    $suffix = $kind === 'video' ? 'progress' : "{$kind}-progress";
    return dirname(__DIR__) . '/uploads/forecast/' . (int) $episode_id . "-{$suffix}.json";
}

function forecast_generation_status($episode_id, $kind = 'video') {
    $path = forecast_progress_path($episode_id, $kind);
    if (!file_exists($path)) return null;
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function forecast_write_progress($episode_id, $status, $percent = null, $error = null, $kind = 'video') {
    $data = ['status' => $status];
    if ($percent !== null) $data['percent'] = $percent;
    if ($error !== null) $data['error'] = $error;
    file_put_contents(forecast_progress_path($episode_id, $kind), json_encode($data));
}

// ── Video assembly ───────────────────────────────────────────────────────

/**
 * Composites an ordered sequence of segment images (intro and wrap-up
 * are forecast_build_cover() itself, one forecast_build_chapter_card()
 * per chapter in between), an animated waveform, a filling progress bar,
 * and a live elapsed/total counter into one mp4 sized to the audio's own
 * duration. Only used for the audio-upload path — a
 * directly-uploaded video skips this and posts as-is.
 *
 * $segments is `[['image' => <path>, 'start' => <seconds>], ...]`,
 * ordered by start, `start` of the first entry always 0. Each segment
 * plays until the next one's start (or the audio's end, for the last).
 * A single segment behaves like the old single-static-cover design — no
 * crossfade filter is built at all in that case.
 *
 * Segments after the first fade in over `FORECAST_SEGMENT_FADE` seconds,
 * timed so the fade finishes exactly at its segment's start — a crossfade
 * look without the classic multi-way xfade offset-chaining recipe (each
 * subsequent xfade's offset depends on every prior segment's duration
 * minus every prior transition's overlap, a well-known source of
 * off-by-one drift). Works because every `-loop 1` image input already
 * shares one global timeline by default (no `-itsoffset`, no `setpts`
 * reset) — `fade`'s `st` and `overlay`'s `enable` can both just use the
 * segment's real, absolute start time directly.
 *
 * The waveform (ffmpeg's showwaves) draws on black by default; colorkey
 * strips that black to transparent before it's overlaid, so whichever
 * segment is showing underneath shows through everywhere the waveform
 * itself isn't drawn — confirmed genuinely audio-reactive per frame
 * (tested against a two-tone clip: tight scallops during the high-
 * frequency half, wide ones during the low).
 *
 * The bottom waveform and progress bar both key off
 * forecast_bottom_row_center_y() and FORECAST_BADGE_D/FORECAST_BADGE_MARGIN
 * (list/forecast.php's own constants for forecast_draw_guest_badge()) —
 * one shared row, not independently-chosen offsets: the waveform's
 * vertical center matches the badge's, and it runs from near the left
 * edge to just clear of the badge rather than stopping at the standard
 * content margin.
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
 * The elapsed/total counter is chrome, not part of any one segment's
 * design — it's drawn top-right of the top band, on every segment alike,
 * built from ffmpeg drawtext's `%{eif:...}` text expansion (evaluate an
 * expression, format as an integer, zero-pad to a given width) —
 * `floor(t/60)` and `mod(t\,60)`, each zero-padded to 2 digits, off `t`
 * (the frame's own timestamp in seconds). Not `%{pts\:gmtime\:%M\:%S}`,
 * ffmpeg's own documented recipe for exactly this — it parses without
 * error but silently fails on this server's ffmpeg (4.4.2): every frame
 * logs "Invalid delta '%M'" and skips drawing entirely, discovered only
 * by actually looking at an extracted frame rather than trusting an
 * "ok":true result. The eif version was confirmed actually ticking (not
 * just parsing), including the minute rollover, by extracting frames at
 * several checkpoints across a real multi-minute test render — the exact
 * discipline that caught the drawbox and pts:gmtime failures too.
 *
 * Returns ['ok' => true] or ['ok' => false, 'error' => '...'] — the ffmpeg
 * output tail on failure, since a filter-graph syntax error only shows up
 * there, not in the exit code alone.
 *
 * $onProgress, if given, is called with an integer 0-99 as encoding runs —
 * run via proc_open() rather than exec() specifically so this is possible:
 * ffmpeg's own stderr already prints `time=HH:MM:SS.ms` as it encodes, no
 * need for its separate -progress file option or any extra moving parts,
 * just read the pipe as it streams and match that pattern against the
 * already-known total duration.
 */
function forecast_generate_video($audioPath, array $segments, $outputPath, $durationSeconds, $onProgress = null) {
    if (!file_exists($audioPath)) return ['ok' => false, 'error' => 'Audio file not found.'];
    if (!$segments) return ['ok' => false, 'error' => 'No segments to render.'];
    foreach ($segments as $seg) {
        if (!file_exists($seg['image'])) return ['ok' => false, 'error' => 'Segment image not found: ' . basename($seg['image'])];
    }
    if ($durationSeconds <= 0) return ['ok' => false, 'error' => 'Could not determine audio duration.'];

    // Vertically centered on the same row the guest-photo badge sits
    // on (forecast_bottom_row_center_y(), FORECAST_BADGE_D — see
    // forecast_draw_guest_badge()) rather than an independently-chosen
    // offset, and running from near the left edge up to just clear of
    // the badge rather than stopping at the standard content margin —
    // one continuous "media player" row, not a badge floating above a
    // waveform underneath it.
    $rowCenterY = forecast_bottom_row_center_y();
    $edgeMargin = 30;
    $badgeLeft  = 1080 - FORECAST_BADGE_MARGIN - FORECAST_BADGE_D;

    $waveH = 150;
    $waveY = $rowCenterY - (int) round($waveH / 2);
    $waveX = $edgeMargin;
    $waveW = $badgeLeft - $edgeMargin - 30; // 30px clear of the badge

    $barH = 8;
    $barY = 1920 - $barH - 16;
    $barX = $edgeMargin;
    $barW = 1080 - $edgeMargin * 2;

    // Plain path, not escapeshellarg()'d — this is a value inside an
    // ffmpeg filter-option string, not a shell argument on its own; the
    // whole -filter_complex value gets one escapeshellarg() pass below.
    // Safe unescaped because IG_FONT_BODY is a fixed repo-controlled path
    // with none of ffmpeg's filter-syntax special characters (: , ' [ ]).
    $fontfilePath = IG_FONT_BODY;

    // A second, smaller waveform in the top band — same source, same
    // showwaves/colorkey treatment, just sized to fit the shorter strip
    // reserved up there (see FORECAST_TOP_WAVE_BAND_H in
    // forecast_build_cover()) so the video reads as bookended top and
    // bottom rather than only weighted toward the bottom. Narrower than
    // the bottom one (700 vs 900) — the live counter's chrome now shares
    // this band, and at full width the waveform's peaks ran directly
    // through the timer text, confirmed by extracting a frame mid-fade
    // and finding the two illegibly overlapping.
    $topWaveW = 700; $topWaveH = 90;
    $topWaveX = 90;  $topWaveY = (int) (14 + (FORECAST_TOP_WAVE_BAND_H - $topWaveH) / 2);

    // 0x3A362E (track) / 0x922E32 (fill), split into decimal RGB — geq's
    // per-channel expressions take plain numbers, not hex literals.
    $trackR = 0x3A; $trackG = 0x36; $trackB = 0x2E;
    $fillR  = 0x92; $fillG  = 0x2E; $fillB  = 0x32;
    $lit = "lt(X\\,{$barW}*T/{$durationSeconds})";
    $barGeq = "geq="
        . "r='if({$lit}\\,{$fillR}\\,{$trackR})':"
        . "g='if({$lit}\\,{$fillG}\\,{$trackG})':"
        . "b='if({$lit}\\,{$fillB}\\,{$trackB})'";

    $totalStr = sprintf('%02d\\:%02d', intdiv($durationSeconds, 60), $durationSeconds % 60);
    $timerText = "%{eif\\:floor(t/60)\\:d\\:2}\\:%{eif\\:mod(t\\,60)\\:d\\:2} / {$totalStr}";
    // w/tw/th are ffmpeg drawtext's own built-ins (video width, this
    // text's rendered width/height) — right-aligned and vertically
    // centered in the top band without PHP ever measuring the string.
    $timerFilter = "drawtext=fontfile={$fontfilePath}:text='{$timerText}':fontsize=30"
        . ":fontcolor=0xF2C14E:x=w-tw-80:y=14+(" . FORECAST_TOP_WAVE_BAND_H . "-th)/2";

    $inputArgs = '-loop 1 -i ' . escapeshellarg($segments[0]['image']) . ' ';
    $bgFilter  = '';
    $bgLabel   = '0:v';

    // Fade each later segment in over its own short window, then latch it
    // permanently on top with a one-sided enable — the next segment's own
    // overlay (later in the chain) will cover it again when its turn
    // comes, so there's no need for an explicit end bound here.
    for ($i = 1; $i < count($segments); $i++) {
        $inputArgs .= '-loop 1 -i ' . escapeshellarg($segments[$i]['image']) . ' ';
        $fadeStart = max(0, $segments[$i]['start'] - FORECAST_SEGMENT_FADE);
        $bgFilter .= "[{$i}:v]format=rgba,fade=t=in:st={$fadeStart}:d=" . FORECAST_SEGMENT_FADE . ":alpha=1[seg{$i}];";
        $bgFilter .= "[{$bgLabel}][seg{$i}]overlay=0:0:enable='gte(t\\,{$fadeStart})'[comp{$i}];";
        $bgLabel = "comp{$i}";
    }

    $audioIdx = count($segments);
    $barIdx   = $audioIdx + 1;

    $filter = $bgFilter
        . "[{$audioIdx}:a]showwaves=s={$waveW}x{$waveH}:mode=cline:rate=25:colors=0xF2C14E[wraw];"
        . "[wraw]colorkey=0x000000:0.15:0.1,format=rgba[wave];"
        . "[{$audioIdx}:a]showwaves=s={$topWaveW}x{$topWaveH}:mode=cline:rate=25:colors=0xF2C14E[wraw2];"
        . "[wraw2]colorkey=0x000000:0.15:0.1,format=rgba[wave2];"
        . "[{$barIdx}:v]{$barGeq}[bar];"
        . "[{$bgLabel}][wave2]overlay={$topWaveX}:{$topWaveY}[bgw0];"
        . "[bgw0][wave]overlay={$waveX}:{$waveY}[bgw1];"
        . "[bgw1][bar]overlay={$barX}:{$barY}[bgw2];"
        . "[bgw2]{$timerFilter}[out]";

    $cmd = sprintf(
        'ffmpeg -y %s-i %s -f lavfi -i %s -filter_complex %s -map %s -map %d:a '
        . '-c:v libx264 -pix_fmt yuv420p -c:a aac -b:a 128k -r 25 -shortest %s',
        $inputArgs,
        escapeshellarg($audioPath),
        escapeshellarg("color=c=black:s={$barW}x{$barH}:d={$durationSeconds}:r=25"),
        escapeshellarg($filter),
        escapeshellarg('[out]'),
        $audioIdx,
        escapeshellarg($outputPath)
    );

    return forecast_run_ffmpeg($cmd, $outputPath, $onProgress, $durationSeconds);
}

/**
 * Runs an already-built ffmpeg command line via proc_open(), reporting
 * 0-99 progress off ffmpeg's own stderr `time=` lines as it streams, and
 * returning ['ok' => true] or ['ok' => false, 'error' => '...'] (the
 * stderr tail, since a filter-graph syntax error only shows up there, not
 * in the exit code alone). Shared by forecast_generate_video() and
 * forecast_generate_waveform_clip() — factored out because the exit-code
 * handling below has a real, already-debugged gotcha (see the comment at
 * the $exitCode line) that a second hand-copied version would risk
 * silently losing.
 */
function forecast_run_ffmpeg($cmd, $outputPath, $onProgress, $durationSeconds) {
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        return ['ok' => false, 'error' => 'Could not start ffmpeg.'];
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[2], false);

    $tail = '';
    do {
        $chunk = fread($pipes[2], 8192);
        if ($chunk !== false && $chunk !== '') {
            $tail .= $chunk;
            if (strlen($tail) > 4000) $tail = substr($tail, -4000);
            if ($onProgress && preg_match('/time=(\d+):(\d+):(\d+\.\d+)/', $chunk, $m)) {
                $elapsed = ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (float) $m[3];
                $onProgress(max(0, min(99, (int) round($elapsed / $durationSeconds * 100))));
            }
        }
        $status = proc_get_status($process);
        if ($chunk === '' || $chunk === false) usleep(200000);
    } while ($status['running']);

    // The exit code has to come from *this* $status — the one where
    // running just flipped to false — not from proc_close()'s return
    // value below. proc_get_status() reaps the child internally
    // (waitpid), so by the time proc_close() runs the process is often
    // already gone; it then has nothing left to reap and returns -1,
    // which reads as a crash even though ffmpeg finished cleanly. Caught
    // on a real 383s episode: valid, complete output file on disk, exit
    // code -1 anyway — every "failure" was ffmpeg's own progress output
    // (frame=/fps=/time=/bitrate=), never an actual error line, because
    // there never was a real one.
    $exitCode = $status['exitcode'];

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    if ($exitCode !== 0 || !file_exists($outputPath)) {
        // ffmpeg's -stats progress line rewrites in place with \r, not
        // \n — split on both, or a genuine fatal error sitting among a
        // burst of progress updates ends up glued to them as one
        // "line" instead of standing out on its own.
        $lines = array_filter(preg_split('/[\r\n]+/', $tail));
        return ['ok' => false, 'error' => implode("\n", array_slice($lines, -25))];
    }
    return ['ok' => true];
}

/**
 * A transparent clip carrying only the two gold waveform bands (same
 * geometry forecast_generate_video() composites into the real video, so
 * this lines up pixel-for-pixel with it) — no progress bar, no timer, no
 * segment images. Built for the manual Premiere fallback: drop this on a
 * track above a hand-placed sequence of panel stills and the waveform
 * reacts to the real audio without redrawing anything by hand. No audio
 * track of its own — the editor already has the master audio on its own
 * track in Premiere.
 *
 * QuickTime Animation (qtrle), not ProRes 4444 — tried prores_ks/4444
 * first (it's the more modern, more compressed choice, and this server's
 * ffmpeg does have the encoder), but the alpha-carrying pixel format it
 * needs (yuva444p10le) round-trips the RGB through YUV and back visibly
 * wrong on this ffmpeg build: the gold wave (0xF2C14E) came back pink,
 * reproducibly, with or without explicit -colorspace/-color_primaries/
 * -color_range flags. qtrle stays in RGB the whole way through (no YUV
 * conversion at all) and came back pixel-correct in the same test. Both
 * are standard, Premiere-native QuickTime codecs; qtrle is simply the
 * one that's actually right here. RLE also compresses this specific
 * content (mostly transparent, a thin bright shape) well despite being
 * "just" lossless run-length, so the size tradeoff isn't the problem
 * ProRes 4444 usually justifies.
 */
function forecast_generate_waveform_clip($audioPath, $outputPath, $durationSeconds, $onProgress = null) {
    if (!file_exists($audioPath)) return ['ok' => false, 'error' => 'Audio file not found.'];
    if ($durationSeconds <= 0) return ['ok' => false, 'error' => 'Could not determine audio duration.'];

    $rowCenterY = forecast_bottom_row_center_y();
    $edgeMargin = 30;
    $badgeLeft  = 1080 - FORECAST_BADGE_MARGIN - FORECAST_BADGE_D;

    $waveH = 150;
    $waveY = $rowCenterY - (int) round($waveH / 2);
    $waveX = $edgeMargin;
    $waveW = $badgeLeft - $edgeMargin - 30;

    $topWaveW = 700; $topWaveH = 90;
    $topWaveX = 90;  $topWaveY = (int) (14 + (FORECAST_TOP_WAVE_BAND_H - $topWaveH) / 2);

    // Not `overlay` — on this server's ffmpeg (4.4.2), `overlay`'s own
    // `format` option has no alpha-carrying choice at all (only
    // yuv420/420p10/422/422p10/444/rgb/gbrp/auto — confirmed via `ffmpeg
    // -h filter=overlay`), so however the inputs are formatted, its
    // output silently comes back fully opaque. `pad` genuinely preserves
    // alpha (confirmed by extracting a frame and reading the alpha
    // channel directly — transparent both outside and inside the padded
    // area), so each wave gets padded out to the full frame at its own
    // position first. The two padded layers never spatially overlap, so
    // `blend`'s `lighten` mode (max per channel, including alpha)
    // combines them correctly without ever going through `overlay` —
    // wherever one layer has real content its brighter, more-opaque
    // pixel wins over the other layer's transparent black there. Tried
    // `addition` first: alpha combined fine (0+0 stays transparent), but
    // summing raw RGB across two straight-alpha layers colored the wave
    // itself wrong wherever either layer's "empty" pixels weren't
    // perfectly (0,0,0) — confirmed by extracting a frame, and gone once
    // switched to `lighten`.
    $filter = "[0:a]showwaves=s={$waveW}x{$waveH}:mode=cline:rate=25:colors=0xF2C14E[wraw];"
        . "[wraw]colorkey=0x000000:0.15:0.1,format=rgba,pad=1080:1920:{$waveX}:{$waveY}:color=black@0.0[layer1];"
        . "[0:a]showwaves=s={$topWaveW}x{$topWaveH}:mode=cline:rate=25:colors=0xF2C14E[wraw2];"
        . "[wraw2]colorkey=0x000000:0.15:0.1,format=rgba,pad=1080:1920:{$topWaveX}:{$topWaveY}:color=black@0.0[layer2];"
        . "[layer1][layer2]blend=all_mode=lighten[out]";

    $cmd = sprintf(
        'ffmpeg -y -i %s -filter_complex %s -map %s '
        . '-c:v qtrle -pix_fmt rgba -t %s %s',
        escapeshellarg($audioPath),
        escapeshellarg($filter),
        escapeshellarg('[out]'),
        escapeshellarg($durationSeconds),
        escapeshellarg($outputPath)
    );

    return forecast_run_ffmpeg($cmd, $outputPath, $onProgress, $durationSeconds);
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
