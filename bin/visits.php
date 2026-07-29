<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Visitor counts, from the command line.
//
//    php bin/visits.php          last fortnight
//    php bin/visits.php 30       last thirty days
// ═══════════════════════════════════════════════════════════════════════════

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__) . '/v7/visits.php';

// Same clock the counter writes with, so the dates printed here name the same
// days as the files behind them.
date_default_timezone_set(CTX_VISITS_TZ);

$days  = max(1, (int)($argv[1] ?? 14));
$stats = ctx_visit_stats($days);

printf("\n  Unique visitors: %d all time, %d today\n", $stats['total'], $stats['today']);
printf("  Connections:     %d all time, %d today  (bots: %d / %d)\n",
       $stats['hits']['total'], $stats['hits']['today'],
       $stats['bots']['total'], $stats['bots']['today']);
if ($stats['since']) printf("  Counting since %s\n", date('j M Y', $stats['since']));
echo "\n";

// Bar length tracks connections, the larger of the two figures, so the
// visitor count reads as the share of it that it is.
$max = max(1, max($stats['recent']), max(array_column($stats['recent_hits'], 'human')));
foreach (array_reverse($stats['recent'], true) as $day => $n) {
    $hits = $stats['recent_hits'][$day]['human'] ?? 0;
    printf("  %s  %-4d %-5d %s\n", date('D j M', strtotime($day)), $n, $hits,
           str_repeat('▄', (int)round($hits / $max * 40)));
}
echo "\n";
