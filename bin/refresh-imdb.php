<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Download IMDb's ratings file — run by hand, never from the web
//
//    php bin/refresh-imdb.php
//
//  About 9 MB. IMDb republishes it daily; weekly is plenty for a rating
//  that only decorates a hovercard. The next bin/warm-cache.php run picks
//  up the new file on its own.
// ═══════════════════════════════════════════════════════════════════════════

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/list/imdb.php';

$result = imdb_refresh_ratings();
if ($result !== true) {
    fwrite(STDERR, date('c') . " imdb: $result\n");
    exit(1);
}
printf("%s imdb: ratings file refreshed (%.1f MB)\n", date('c'), filesize(imdb_ratings_path()) / 1048576);
