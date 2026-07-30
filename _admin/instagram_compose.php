<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — save today's Instagram composition choice
//
//  Mode/per-page/feature-panels, same pattern as instagram_caption.php: the
//  admin preview, and the cron job all read this back through
//  ig_compose_read() in list/instagram.php, so what you approve here is
//  what actually posts.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();
require dirname(__DIR__) . '/list/instagram.php';

$now  = time();
$mode = $_POST['mode'] ?? 'default';
if (!in_array($mode, ['default', 'auto', 'manual'], true)) $mode = 'default';

$per_page = null;
if ($mode === 'manual' && isset($_POST['per_page']) && $_POST['per_page'] !== '') {
    $per_page = max(1, (int) $_POST['per_page']);
}

ig_compose_write($now, [
    'mode'     => $mode,
    'per_page' => $per_page,
    'features' => !empty($_POST['features']),
]);

header('Location: /_admin/instagram.php');
exit;
