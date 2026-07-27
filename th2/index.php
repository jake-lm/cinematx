<?php
// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — Theatre 2
//
//  The old page carried its own queries, its own markup and its own copy of
//  the sync. All three now live in one place: the schedule lookup in
//  ctx_theatre(), the markup in v7/_screening.php, the sync in startSync(),
//  which the front page's overlay runs too. Two screens, one implementation.
// ═══════════════════════════════════════════════════════════════════════════
require dirname(__DIR__) . '/v7/_lib.php';

$ctx_screen = 2;
require dirname(__DIR__) . '/v7/_screening.php';
