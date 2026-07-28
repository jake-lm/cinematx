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
if ($stats['since']) printf("  Counting since %s\n", date('j M Y', $stats['since']));
echo "\n";

$max = max(1, max($stats['recent']));
foreach (array_reverse($stats['recent'], true) as $day => $n) {
    printf("  %s  %-4d %s\n", date('D j M', strtotime($day)), $n, str_repeat('▄', (int)round($n / $max * 40)));
}
echo "\n";
