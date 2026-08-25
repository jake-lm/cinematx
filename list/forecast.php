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

// Every unique screening this week (a film playing three nights only
// counts once), grouped by calendar day and chronologically ordered within
// each — the one source both the automatic picker and the manual
// checklist (forecast_all_week_films(), _admin/forecast_episode.php) read
// from, so they can never disagree about what's actually playing.
function forecast_week_by_day($conn, $weekOf) {
    $start = strtotime($weekOf);
    $end   = strtotime('+7 day', $start) - 1;
    $films = fetch_all_screenings($conn, $start, $end, false);
    $films = array_values(array_filter($films, fn($f) => in_array($f['venue'], IG_VENUES, true)));
    $films = ctx_enrich($films);

    $byDay = [];
    $seenTitles = [];
    foreach ($films as $f) {
        $title = !empty($f['display_title']) ? $f['display_title'] : $f['title'];
        $key = mb_strtolower($title);
        if (isset($seenTitles[$key])) continue;
        $seenTitles[$key] = true;
        $byDay[date('Y-m-d', $f['timestamp'])][] = $f + ['display_title' => $title];
    }
    ksort($byDay);
    return $byDay;
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
 * Which films actually belong on the cover, resolved in the same
 * override-beats-saved-beats-default order the daily carousel's own
 * ig_carousel_selection() already established:
 *   1. $override (film keys from $_GET during live preview) if given at
 *      all — even an empty array, since unchecking everything is a
 *      deliberate choice that deserves to be reflected in the mockup.
 *   2. The episode's saved selected_films, if it's ever been touched.
 *   3. The automatic one-per-night default (forecast_round_robin_order(),
 *      capped the same 9 rows forecast_week_films() uses).
 * Returns a flat, chronologically-ordered film list either way — the
 * order the checklist itself presents them in, so a saved selection can
 * only ever be a subset of that same order.
 */
function forecast_resolve_selection(array $episode, array $byDay, $override = null) {
    $flat = [];
    foreach ($byDay as $dayFilms) {
        foreach ($dayFilms as $f) $flat[] = $f;
    }

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
        return array_slice(forecast_round_robin_order($byDay), 0, 9);
    }

    $keys = array_flip($wantedKeys);
    return array_values(array_filter($flat, fn($f) => isset($keys[ig_film_key($f)])));
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

    $availableHeight = FORECAST_WAVE_BAND_Y - $y - 60;
    $idealRowHeight  = 135; // thumbH(105) + gap(30), the automatic default's fixed size

    if ($filmsOverride !== null) {
        // A manual selection is a deliberate editorial choice — hiding some
        // of it behind a "+N more" would undercut the whole point of
        // picking, so this shrinks to fit instead of capping, down to a
        // legibility floor. Past that floor it does fall back to capping,
        // same as the automatic default, rather than rendering illegibly
        // small rows.
        $films = $filmsOverride;
        // 95, not lower — the floor title+meta text (18px/14px, set below)
        // needs about that much room to stay clear of both the row above
        // and the thumbnail beneath it. Confirmed by rendering a 15-film
        // test list at a lower floor: title and meta text visibly collided
        // once rows shrank much past this.
        $minRowHeight = 95;
        if (count($films) > 0 && count($films) * $idealRowHeight > $availableHeight) {
            $fitRows = max(1, (int) floor($availableHeight / $minRowHeight));
            if (count($films) > $fitRows) {
                $films = array_slice($films, 0, $fitRows);
            }
            $rowHeight = count($films) > 0 ? (int) floor($availableHeight / count($films)) : $idealRowHeight;
        } else {
            $rowHeight = $idealRowHeight;
        }
        // "+N more" here isn't just an overflow indicator the way it is in
        // the automatic path below — it also covers films the admin simply
        // didn't check, so the cover still hints there's more happening
        // this week even when the showcase is a deliberately short,
        // comfortably-fitting list.
        $more = $totalThisWeek !== null ? max(0, $totalThisWeek - count($films)) : 0;
        $thumbH = max(40, min(105, $rowHeight - 25));
        $thumbW = (int) round($thumbH * 70 / 105);
    } else {
        $thumbW = 70; $thumbH = 105; $rowHeight = $idealRowHeight;
        $week  = forecast_week_films($conn, $episode['week_of'], (int) floor($availableHeight / $rowHeight));
        $films = $week['films'];
        $more  = $week['more'];
    }

    $textX = $margin + $thumbW + 26;
    $textMaxWidth = $w - $margin - $textX;
    $titleSize = $thumbH >= 90 ? 26 : max(18, (int) round($thumbH * 26 / 105));
    $metaSize  = $thumbH >= 90 ? 20 : max(14, (int) round($thumbH * 20 / 105));
    // A second, smaller meta line for the director — sized down from
    // $metaSize the same way $metaSize itself scales off $titleSize, so it
    // shrinks in step with everything else on a long manual selection
    // rather than becoming the tallest thing in a cramped row.
    $metaSize2 = $thumbH >= 90 ? 18 : max(12, (int) round($thumbH * 18 / 105));

    foreach ($films as $f) {
        $thumb = ig_fetch_thumb($f['poster'] ?? null, $thumbW, $thumbH);
        if ($thumb) {
            imagecopy($im, $thumb, $margin, $y, 0, 0, $thumbW, $thumbH);
            imagedestroy($thumb);
        } else {
            imagefilledrectangle($im, $margin, $y, $margin + $thumbW, $y + $thumbH, $placeholder);
        }

        // Offset from fixed font-size padding, not a fraction of thumbH —
        // a fraction shrinks the gap between lines just as fast as it
        // shrinks the thumbnail, but the floored font sizes below don't
        // shrink nearly that fast, so a fraction-based offset lets the
        // lines run into each other on a long manual selection. Three
        // lines (title, venue/time, director) still fit well inside even
        // the shrunk floor row height this way — verified by rendering a
        // 15-film test list end to end before this shipped.
        $titleY = $y + $titleSize + 8;
        $metaY  = $titleY + $metaSize + 8;
        $metaY2 = $metaY + $metaSize2 + 6;

        $title = ig_fit_text(mb_strtoupper($f['display_title']), IG_FONT_HEADLINE, $titleSize, $textMaxWidth);
        imagettftext($im, $titleSize, 0, $textX, $titleY, $ink, IG_FONT_HEADLINE, $title);

        $meta = ig_fit_text($f['venue'] . '  ·  ' . date('D, g:ia', $f['timestamp']), IG_FONT_BODY, $metaSize, $textMaxWidth);
        imagettftext($im, $metaSize, 0, $textX, $metaY, $muted, IG_FONT_BODY, $meta);

        if (!empty($f['director'])) {
            $director = ig_fit_text('Dir. ' . $f['director'], IG_FONT_BODY, $metaSize2, $textMaxWidth);
            imagettftext($im, $metaSize2, 0, $textX, $metaY2, $muted, IG_FONT_BODY, $director);
        }

        $y += $rowHeight;
    }

    if ($more > 0) {
        imagettftext($im, 22, 0, $margin, $y + 30, $red, IG_FONT_BODY, '+ ' . $more . ' more this week');
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

// ── Generation progress ─────────────────────────────────────────────────
//
// A tiny JSON file per episode is the whole mechanism: bin/forecast-
// generate.php (launched detached by _admin/forecast_generate.php) writes
// it as ffmpeg runs, _admin/forecast_progress.php just reads it back for
// the browser to poll. Its own 'status' field doubles as the lock that
// stops a second "Generate" click from starting a duplicate ffmpeg run —
// no separate lock file needed.

function forecast_progress_path($episode_id) {
    return dirname(__DIR__) . '/uploads/forecast/' . (int) $episode_id . '-progress.json';
}

function forecast_generation_status($episode_id) {
    $path = forecast_progress_path($episode_id);
    if (!file_exists($path)) return null;
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function forecast_write_progress($episode_id, $status, $percent = null, $error = null) {
    $data = ['status' => $status];
    if ($percent !== null) $data['percent'] = $percent;
    if ($error !== null) $data['error'] = $error;
    file_put_contents(forecast_progress_path($episode_id), json_encode($data));
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
 *
 * $onProgress, if given, is called with an integer 0-99 as encoding runs —
 * run via proc_open() rather than exec() specifically so this is possible:
 * ffmpeg's own stderr already prints `time=HH:MM:SS.ms` as it encodes, no
 * need for its separate -progress file option or any extra moving parts,
 * just read the pipe as it streams and match that pattern against the
 * already-known total duration.
 *
 * $durationSlot, if given (['x', 'y', 'size'], from forecast_build_cover()'s
 * own by-reference output — see its $liveDuration param), draws a live
 * "elapsed / total" counter into the video at that exact position instead
 * of the static duration forecast_build_cover() would otherwise have baked
 * into the cover PNG. Built from ffmpeg drawtext's `%{eif:...}` text
 * expansion (evaluate an expression, format as an integer, zero-pad to a
 * given width) — `floor(t/60)` and `mod(t\,60)`, each zero-padded to 2
 * digits, off `t` (the frame's own timestamp in seconds). No extra moving
 * parts (no separate progress file, no JS timer), and it naturally has no
 * hour component to strip for an episode under an hour.
 *
 * Not `%{pts\:gmtime\:%M\:%S}`, ffmpeg's own documented recipe for exactly
 * this — it parses without error but silently fails on this server's
 * ffmpeg (4.4.2): every frame logs "Invalid delta '%M'" and skips drawing
 * entirely, so the very first version of this produced a real, valid
 * video with no timer on it at all, discovered only by actually looking at
 * an extracted frame rather than trusting the "ok":true result. Confirmed
 * the eif version actually ticks (not just parses) by extracting frames at
 * the start, past a minute rollover, and near the end of a real multi-
 * minute test render and checking the printed value against each frame's
 * real timestamp — not just that it looked right on the first frame, the
 * exact mistake that broke drawbox's progress bar earlier in this file.
 */
function forecast_generate_video($audioPath, $coverPath, $outputPath, $durationSeconds, $durationSlot = null, $onProgress = null) {
    if (!file_exists($audioPath)) return ['ok' => false, 'error' => 'Audio file not found.'];
    if (!file_exists($coverPath)) return ['ok' => false, 'error' => 'Cover image not found.'];
    if ($durationSeconds <= 0) return ['ok' => false, 'error' => 'Could not determine audio duration.'];

    $waveW = 900; $waveH = 160;
    $waveX = 90;  $waveY = FORECAST_WAVE_BAND_Y + 40;
    $barX  = 90;  $barY = FORECAST_WAVE_BAND_Y + 230;
    $barW  = 900; $barH = 8;

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
    // bottom rather than only weighted toward the bottom.
    $topWaveW = 900; $topWaveH = 90;
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

    $timerFilter = null;
    if ($durationSlot) {
        $totalStr = sprintf('%02d\\:%02d', intdiv($durationSeconds, 60), $durationSeconds % 60);
        // Built from eif (evaluate-integer-format) expansions, not the
        // pts:gmtime:FORMAT expansion the ffmpeg docs describe — that one
        // reliably failed on this server's ffmpeg (4.4.2) with "Invalid
        // delta '%M'" for every frame, silently skipping the draw instead
        // of erroring out, which is exactly why the first version of this
        // rendered a real, valid video with no timer text on it at all.
        // eif's two calls compute whole minutes and whole seconds off `t`
        // (the frame's own timestamp) directly and zero-pad each to width
        // 2 — confirmed ticking correctly frame to frame, including the
        // minute rollover (00:59 -> 01:00), by extracting frames at
        // several checkpoints across a real multi-minute test render.
        $timerText = "%{eif\\:floor(t/60)\\:d\\:2}\\:%{eif\\:mod(t\\,60)\\:d\\:2} / {$totalStr}";
        $timerFilter = "drawtext=fontfile={$fontfilePath}:text='{$timerText}':fontsize={$durationSlot['size']}"
            . ":fontcolor=0x6B6659:x={$durationSlot['x']}:y={$durationSlot['y']}";
    }

    $filter =
        "[1:a]showwaves=s={$waveW}x{$waveH}:mode=cline:rate=25:colors=0xF2C14E[wraw];"
        . "[wraw]colorkey=0x000000:0.15:0.1,format=rgba[wave];"
        . "[1:a]showwaves=s={$topWaveW}x{$topWaveH}:mode=cline:rate=25:colors=0xF2C14E[wraw2];"
        . "[wraw2]colorkey=0x000000:0.15:0.1,format=rgba[wave2];"
        . "[2:v]{$barGeq}[bar];"
        . "[0:v][wave2]overlay={$topWaveX}:{$topWaveY}[bg0];"
        . "[bg0][wave]overlay={$waveX}:{$waveY}[bg1];"
        . ($timerFilter
            ? "[bg1][bar]overlay={$barX}:{$barY}[bg2];[bg2]{$timerFilter}[out]"
            : "[bg1][bar]overlay={$barX}:{$barY}[out]");

    $cmd = sprintf(
        'ffmpeg -y -loop 1 -i %s -i %s -f lavfi -i %s -filter_complex %s -map %s -map 1:a '
        . '-c:v libx264 -pix_fmt yuv420p -c:a aac -b:a 128k -r 25 -shortest %s',
        escapeshellarg($coverPath),
        escapeshellarg($audioPath),
        escapeshellarg("color=c=black:s={$barW}x{$barH}:d={$durationSeconds}:r=25"),
        escapeshellarg($filter),
        escapeshellarg('[out]'),
        escapeshellarg($outputPath)
    );

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
