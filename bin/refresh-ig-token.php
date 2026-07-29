<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Long-lived Instagram token refresh — run from cron, never from the web
//
//    0 3 1 * *  /usr/bin/php /path/to/cinematx/bin/refresh-ig-token.php >> /var/log/cinematx-ig.log 2>&1
//
//  Meta's long-lived page token expires ~60 days after issue. This exchanges
//  the current token for a fresh 60-day one every month so post-instagram.php
//  never runs into a dead token, and rewrites IG_ACCESS_TOKEN in config.php
//  in place.
// ═══════════════════════════════════════════════════════════════════════════

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root        = dirname(__DIR__);
$config_path = $root . '/config.php';
require $config_path;
require $root . '/list/instagram.php'; // for IG_GRAPH_VERSION, ig_graph_call()

foreach (['META_APP_ID', 'META_APP_SECRET', 'IG_ACCESS_TOKEN'] as $const) {
    if (!defined($const) || !constant($const)) {
        fwrite(STDERR, date('c') . " ig-refresh: $const not configured, skipping\n");
        exit(1);
    }
}

$result = ig_graph_call('https://graph.facebook.com/' . IG_GRAPH_VERSION . '/oauth/access_token', [
    'grant_type'    => 'fb_exchange_token',
    'client_id'     => META_APP_ID,
    'client_secret' => META_APP_SECRET,
    'fb_exchange_token' => IG_ACCESS_TOKEN,
]);

if (empty($result['access_token'])) {
    fwrite(STDERR, date('c') . ' ig-refresh: exchange failed: ' . json_encode($result) . "\n");
    exit(1);
}

$new_token = $result['access_token'];
$contents  = file_get_contents($config_path);
$updated   = preg_replace(
    "/define\\('IG_ACCESS_TOKEN',\\s*'[^']*'\\);/",
    "define('IG_ACCESS_TOKEN', '{$new_token}');",
    $contents,
    1,
    $count
);

if ($count !== 1) {
    fwrite(STDERR, date('c') . " ig-refresh: could not find IG_ACCESS_TOKEN define in config.php, not writing\n");
    exit(1);
}

file_put_contents($config_path, $updated);
$days = isset($result['expires_in']) ? round($result['expires_in'] / 86400) : '?';
printf("%s ig-refresh: token refreshed, valid ~%s days\n", date('c'), $days);
