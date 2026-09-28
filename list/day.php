<?php
// ═══════════════════════════════════════════════════════════════════════════
//  The front page's own "See more" — one additional day, on request.
//
//  index.php renders today (offset 0) itself, server-side, on first paint.
//  Every day after that is fetched here instead of being pre-rendered and
//  hidden: the front page is meant to stay light, and most visits never
//  click past today at all. ctx_day_section() is the same renderer either
//  way, so a folded card behaves identically whether it arrived on first
//  paint or three clicks later.
// ═══════════════════════════════════════════════════════════════════════════
require dirname(__DIR__) . '/v7/_lib.php';

header('Content-Type: application/json');

$offset = filter_input(INPUT_GET, 'day', FILTER_VALIDATE_INT);

// Anything outside the site's own look-ahead window has nothing scraped for
// it yet — same boundary /list/ itself stops at, not a new one invented here.
if ($offset === null || $offset === false || $offset < 1 || $offset >= CTX_LOOKAHEAD_DAYS) {
    http_response_code(400);
    echo json_encode(['error' => 'bad day']);
    exit;
}

$day = ctx_day_films($conn, $CTX_NOW, $offset);

echo json_encode([
    'html'     => ctx_day_section($day, $CTX_NOW),
    // Appended to <body> directly, not into the section above — see
    // ctx_day_sheets()'s own note on why a sheet can't safely nest inside
    // the scrolling card body.
    'sheets'   => ctx_day_sheets($day),
    'hasMore'  => $day['has_more'],
    'nextDay'  => $offset + 1,
]);
