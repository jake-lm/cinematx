<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Render the animated list-page videos for one day, in the background
//
//    php bin/ig-render-animations.php --date=YYYY-MM-DD
//
//  Launched detached by _admin/instagram.php whenever the saved composition
//  has an animated slide whose video isn't current (see
//  ig_spawn_animation_render()), so the 96-frame render and encode never
//  hold up a page load. It renders the *saved* composition — the same one
//  the post step publishes — and exits straight away if there is nothing to
//  do or another render of the same file is already running. The post step
//  renders anything this hasn't finished, so this is purely a head start.
// ═══════════════════════════════════════════════════════════════════════════
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/config.php';
require $root . '/database.php';
require $root . '/list/instagram.php';

$date = null;
foreach ($argv as $arg) {
    if (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) $date = strtotime($m[1]);
}
if (!$date) {
    fwrite(STDERR, "Usage: ig-render-animations.php --date=YYYY-MM-DD\n");
    exit(1);
}

$films   = ig_today_films($conn, $date);
$compose = ig_compose_read($date);
$plan    = ig_plan_pages($films, $date, $compose);

$pending = ig_pending_animations($plan, $date);
if (!$pending) exit(0);

foreach ($pending as $i) {
    $start = microtime(true);
    try {
        ig_render_anim($plan[$i], $date, $i, false);
        printf("%s ig-anim: page %d rendered for %s in %.1fs\n", date('c'), $i + 1, date('Y-m-d', $date), microtime(true) - $start);
    } catch (Throwable $e) {
        printf("%s ig-anim: page %d skipped (%s)\n", date('c'), $i + 1, $e->getMessage());
    }
}
