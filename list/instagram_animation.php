<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Animated list pages — a looping video in place of a still slide
//
//  A theme that supports it draws one frame of a looping version of its list
//  page when ig_build_list_page() is handed an $anim spec (today: Marquee, Paper and Neon, in
//  October). This file turns those frames into an MP4, caches it, and tells
//  the carousel publisher which slides have one.
//
//  A video is only ever an upgrade over the still, never a requirement: every
//  step here fails soft. A missing ffmpeg, a failed render or a video Meta
//  refuses leaves that slide as the PNG it always was, so the day's post can't
//  be held up by the animation.
//
//  Rendering is slow (96 frames plus an encode), so it must never run during
//  an admin page load; the admin page starts it in the background
//  (bin/ig-render-animations.php) and the post step renders anything still
//  missing itself.
// ═══════════════════════════════════════════════════════════════════════════

// Bump whenever an animation's look changes — cached videos are keyed on it,
// and without a bump a deploy would keep serving yesterday's design.
const IG_ANIM_VERSION = 1;

const IG_ANIM_FRAMES = 96;   // frames drawn per loop...
const IG_ANIM_FPS    = 12;   // ...at this rate: an 8-second loop
// Instagram requires 23-60 fps, so the 12 fps frames are encoded at 24 with
// each one shown twice.
const IG_VIDEO_FPS   = 24;

// Meta's video processing is slower than an image's 20s budget; 60 × 5s.
const IG_VIDEO_POLL_ATTEMPTS = 60;
const IG_VIDEO_POLL_SLEEP    = 5;

// Frames in a theme's loop. Most are one 8-second cycle (IG_ANIM_FRAMES);
// Neon's ghost drifts the whole height of the card at a leisurely pace, so its
// video is six of those cycles back to back — everything else in the scene
// repeats every cycle, the ghost makes a single slow journey across all six.
// Newsprint's classifieds likewise turn over slowly: three ads, each up for a
// third of the video, while its bat makes one crossing in each third too —
// three cycles of 82 frames (6.8s). Instagram wants carousel videos under a
// minute: 6 x 8s = 48s, 3 x 6.83s = 20.5s. Darkroom's print develops four
// pictures, one per 8s cycle (The Scream, Son of Man, Psycho, American Gothic):
// 4 x 8s = 32s. Marquee's breeze has room for two gusts, and for leaves to fall the whole height of the card: 4 x 8s = 32s.
function ig_anim_frames($theme) {
    if ($theme === 'paper') return 3 * IG_ANIM_FRAMES;         // the widow's long drop and climb: 24s
    if ($theme === 'marquee') return 4 * IG_ANIM_FRAMES;       // a breeze with two gusts, and the time for its leaves to fall right across the page: 32s
    if ($theme === 'newsprint') return 3 * 82;
    if ($theme === 'darkroom') return 4 * IG_ANIM_FRAMES;   // four prints develop: 32s
    if ($theme === 'zine') return 3 * IG_ANIM_FRAMES;       // three posters, a gust between each: 24s
    if ($theme === 'austin') return 11 * IG_ANIM_FRAMES / 2; // the Big Wheel and the twins (16s), then a ghost captured down a full page (28s): 44s
    return ($theme === 'neon' ? 6 : 1) * IG_ANIM_FRAMES;
}

// Which themes have an animated list page, for a post on $date.
function ig_theme_animates($theme, $date) {
    return in_array($theme, ['marquee', 'paper', 'neon', 'newsprint', 'darkroom', 'zine', 'austin'], true) && ig_halloween_season($date);
}

// Whether a planned page (see ig_plan_pages()) gets a video.
function ig_item_animates(array $item, $date) {
    return $item['type'] === 'list' && ig_theme_animates($item['theme'], $date);
}

// [filesystem path, root-relative URL] of page $i's video, whether or not it
// exists yet. Mirrors ig_save_images()'s naming.
function ig_anim_paths($date, $i) {
    $name = 'ig-' . date('Y-m-d', $date) . '-' . ($i + 1) . '.mp4';
    return [dirname(__DIR__) . '/uploads/social/' . $name, '/uploads/social/' . $name];
}

/**
 * What a cached video was made from: a hash of the page's still, plus
 * IG_ANIM_VERSION. The still carries every input that matters (films, date,
 * theme, "+N more"), so if it is unchanged the video would be too — and it
 * is cheap to recompute where a separate fingerprint of those inputs would
 * be one more thing to keep in step with them.
 */
function ig_anim_key(array $item, $date) {
    $im = ig_build_list_page($item['films'], $date, $item['theme'], $item['moreCount']);
    ob_start();
    imagepng($im);
    $png = ob_get_clean();
    imagedestroy($im);
    // A theme whose animation changed without its still changing carries a revision number here, so
    // a video cached from before the change is not mistaken for current.
    static $rev = ['paper' => 2];
    return md5($png) . ':v' . IG_ANIM_VERSION . (isset($rev[$item['theme']]) ? ':r' . $rev[$item['theme']] : '');
}

function ig_anim_fresh(array $item, $date, $i) {
    [$mp4] = ig_anim_paths($date, $i);
    return is_file($mp4) && filesize($mp4) > 0
        && is_file($mp4 . '.key')
        && trim((string) file_get_contents($mp4 . '.key')) === ig_anim_key($item, $date);
}

/**
 * Renders page $i's video if it isn't already current. $wait says what to do
 * when another process is mid-render of the same file: block until it
 * finishes (the post step, which needs the file) or give up at once (the
 * background job, which would only be duplicating it). Throws on any
 * failure; callers decide whether that matters.
 */
function ig_render_anim(array $item, $date, $i, $wait = true) {
    [$mp4] = ig_anim_paths($date, $i);
    $dir = dirname($mp4);
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    $lock = fopen($mp4 . '.lock', 'c');
    if (!$lock) throw new RuntimeException('could not open the render lock');
    if (!flock($lock, $wait ? LOCK_EX : (LOCK_EX | LOCK_NB))) {
        fclose($lock);
        throw new RuntimeException('a render of this video is already running');
    }

    $tmp = $mp4 . '.part.mp4';
    $frames = sys_get_temp_dir() . '/ig-anim-' . getmypid() . '-' . bin2hex(random_bytes(3));
    try {
        // Re-checked under the lock: whoever held it may have just finished.
        $key = ig_anim_key($item, $date);
        if (is_file($mp4) && is_file($mp4 . '.key') && trim((string) file_get_contents($mp4 . '.key')) === $key) {
            return;
        }

        exec('command -v ffmpeg', $which, $found);
        if ($found !== 0) throw new RuntimeException('ffmpeg is not installed');

        mkdir($frames, 0700);
        $nFrames = ig_anim_frames($item['theme']);
        for ($f = 0; $f < $nFrames; $f++) {
            $im = ig_build_list_page($item['films'], $date, $item['theme'], $item['moreCount'],
                ['frame' => $f, 'frames' => $nFrames, 'fps' => IG_ANIM_FPS]);
            imagepng($im, sprintf('%s/f%03d.png', $frames, $f), 1);
            imagedestroy($im);
        }

        // -t is a float: a video whose frame count is not a whole number of
        // seconds (Newsprint's is 25.5s) would be cut short by an integer.
        // A silent stereo track rides along: some Meta video paths expect
        // one, and it costs nothing a muted loop would miss. Meta's spec
        // rules out edit lists and wants the index at the front: -bf 0 and
        // -use_editlist 0 keep the muxer from writing one (it otherwise adds
        // one for the AAC track's priming samples), and +faststart moves
        // the index forward.
        $cmd = sprintf(
            'ffmpeg -y -loglevel error -framerate %d -i %s -f lavfi -i anullsrc=channel_layout=stereo:sample_rate=44100 '
            . '-shortest -t %.4F -r %d -c:v libx264 -preset medium -crf 20 -pix_fmt yuv420p -profile:v high -bf 0 -g %d '
            . '-c:a aac -b:a 64k -use_editlist 0 -movflags +faststart %s 2>&1',
            IG_ANIM_FPS,
            escapeshellarg($frames . '/f%03d.png'),
            $nFrames / IG_ANIM_FPS,
            IG_VIDEO_FPS,
            IG_VIDEO_FPS,
            escapeshellarg($tmp)
        );
        exec($cmd, $out, $code);
        if ($code !== 0 || !is_file($tmp) || filesize($tmp) < 1000) {
            throw new RuntimeException('ffmpeg failed: ' . implode(' ', array_slice($out, -3)));
        }

        rename($tmp, $mp4);
        file_put_contents($mp4 . '.key', $key);
    } finally {
        foreach (glob($frames . '/*') ?: [] as $f) @unlink($f);
        @rmdir($frames);
        @unlink($tmp);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Adds a 'video' => [path, url] entry to each page whose video is ready.
 * $render = true renders any that aren't (the post step); false just attaches
 * what already exists (the admin preview). A carousel is the only place a
 * video can go — a one-image day can't hold one — so a one-page plan is
 * returned untouched. Every failure is logged and leaves that page a still.
 */
function ig_attach_animations(array $pages, array $plan, $date, $render = false) {
    if (count($plan) < 2) return $pages;

    foreach ($plan as $i => $item) {
        if (!isset($pages[$i]) || !ig_item_animates($item, $date)) continue;
        try {
            if (!ig_anim_fresh($item, $date, $i)) {
                if (!$render) continue;
                ig_render_anim($item, $date, $i, true);
            }
            $pages[$i]['video'] = ig_anim_paths($date, $i);
        } catch (Throwable $e) {
            error_log('ig animation, page ' . ($i + 1) . ': ' . $e->getMessage());
        }
    }
    return $pages;
}

// Page indexes that animate but have no current video yet.
function ig_pending_animations(array $plan, $date) {
    if (count($plan) < 2) return [];
    $pending = [];
    foreach ($plan as $i => $item) {
        if (ig_item_animates($item, $date) && !ig_anim_fresh($item, $date, $i)) $pending[] = $i;
    }
    return $pending;
}

// Starts the background renderer for $date and returns immediately. Safe to
// call on every admin page load: the script exits at once if a render is
// already running or nothing is pending.
function ig_spawn_animation_render($date) {
    $script = dirname(__DIR__) . '/bin/ig-render-animations.php';
    $log    = dirname(__DIR__) . '/uploads/social/cinematx-ig-anim.log';
    exec(sprintf(
        'nohup %s %s --date=%s >> %s 2>&1 &',
        escapeshellarg(PHP_BINDIR . '/php'),
        escapeshellarg($script),
        escapeshellarg(date('Y-m-d', $date)),
        escapeshellarg($log)
    ));
}
