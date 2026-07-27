<?php
// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — front door
//
//  The v7 front page now serves the root. The page itself and its partials
//  live in /v7/ while the rest of the site is converted; once every surface
//  is on v7 that directory gets flattened into the project root and this
//  shim goes away.
//
//  The previous homepage is preserved at git tag `v1-ui` and branch
//  `legacy-ui`, and in the 2026-07-26 archive as both rendered HTML and
//  full-page screenshots.
// ═══════════════════════════════════════════════════════════════════════════

require __DIR__ . '/v7/index.php';
