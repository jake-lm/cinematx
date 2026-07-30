<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Daily Instagram poster — run from cron, never from the web
//
//    0 9 * * *  /usr/bin/php /path/to/cinematx/bin/post-instagram.php >> /var/log/cinematx-ig.log 2>&1
//
//  Renders today's Austin screenings into a card and publishes it through
//  the Instagram Graph API. Pass --dry-run to generate the image + caption
//  and skip the actual post — useful for checking the card looks right
//  before IG_ACCESS_TOKEN / IG_BUSINESS_ACCOUNT_ID are wired up in config.php.
// ═══════════════════════════════════════════════════════════════════════════

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/config.php';
require $root . '/database.php';
require $root . '/list/instagram.php';

$dry_run = in_array('--dry-run', $argv, true);

// One poster at a time — a slow Graph API round trip must not have the next
// day's cron tick land on top of it.
$lock = fopen(sys_get_temp_dir() . '/cinematx-ig.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, date('c') . " ig: already running, skipping\n");
    exit(0);
}

$now     = time();
$films   = ig_today_films($conn);
$compose = ig_compose_read($now);
$images  = ig_build_images($films, $now, $compose);
$pages   = ig_save_images($images, $now);
$caption = ig_caption($films, $now);

printf(
    "%s ig: %d screenings today, %s mode, %d page(s) written (%s)\n",
    date('c'), count($films), $compose['mode'], count($pages), implode(', ', array_column($pages, 0))
);

if ($dry_run) {
    // The absolute form, which is what Meta is handed and has to be able to
    // fetch. Worth printing: when it is wrong the failure is a generic
    // "media could not be fetched" from the API, hours later.
    echo "--- caption ---\n{$caption}\n";
    foreach ($pages as $i => [$path, $url]) {
        $public = ig_public_url($url, $path);
        printf("--- page %d image URL Meta would fetch: %s ---\n", $i + 1, $public !== '' ? $public : '(CTX_SITE_URL unset)');
    }
    echo "--- (dry run, nothing posted) ---\n";
} else {
    if (!defined('IG_ACCESS_TOKEN') || !defined('IG_BUSINESS_ACCOUNT_ID') || !IG_ACCESS_TOKEN
        || !defined('CTX_SITE_URL') || !CTX_SITE_URL) {
        fwrite(STDERR, date('c') . " ig: IG_ACCESS_TOKEN / IG_BUSINESS_ACCOUNT_ID / CTX_SITE_URL not configured\n");
        flock($lock, LOCK_UN);
        fclose($lock);
        exit(1);
    }

    // Shared with the admin panel's "Post to Instagram" button — whichever
    // posts first today wins, the other is a no-op rather than a duplicate.
    $flag = $root . '/uploads/social/.posted-' . date('Y-m-d', $now);
    // DEV OVERRIDE (2026-07-30): guard disabled to match instagram_post.php
    // while composition modes are being live-tested. Re-enable both together.
    // if (file_exists($flag)) {
    //     printf("%s ig: already posted today, skipping\n", date('c'));
    //     flock($lock, LOCK_UN);
    //     fclose($lock);
    //     exit(0);
    // }

    try {
        $media_id = ig_publish($pages, $caption);
        file_put_contents($flag, $media_id);
        printf("%s ig: published, media id %s\n", date('c'), $media_id);
    } catch (Throwable $e) {
        fwrite(STDERR, date('c') . ' ig: ' . $e->getMessage() . "\n");
        flock($lock, LOCK_UN);
        fclose($lock);
        exit(1);
    }
}

flock($lock, LOCK_UN);
fclose($lock);
