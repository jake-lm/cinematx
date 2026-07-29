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

$now    = time();
$films  = ig_today_films($conn);
$image  = ig_build_image($films, $now);
[$path, $url] = ig_save_image($image, $now);
$caption = ig_build_caption($films, $now);

printf("%s ig: %d screenings today, card written to %s\n", date('c'), count($films), $path);

if ($dry_run) {
    echo "--- caption ---\n{$caption}\n";
    echo "--- (dry run, nothing posted) ---\n";
} else {
    if (!defined('IG_ACCESS_TOKEN') || !defined('IG_BUSINESS_ACCOUNT_ID') || !IG_ACCESS_TOKEN
        || !defined('CTX_SITE_URL') || !CTX_SITE_URL) {
        fwrite(STDERR, date('c') . " ig: IG_ACCESS_TOKEN / IG_BUSINESS_ACCOUNT_ID / CTX_SITE_URL not configured\n");
        flock($lock, LOCK_UN);
        fclose($lock);
        exit(1);
    }
    try {
        $media_id = ig_publish($url, $caption);
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
