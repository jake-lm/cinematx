<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin gate
//
//  Every file in this directory includes this first, before any output and
//  before reading a single request parameter. Until now there was no check at
//  all: on a public host, anyone who tried /_admin/ got the panel, and
//  delete.php unlinks the actual .mp4 and .png.
//
//  An admin flag on the member, not a shared password — there is already a
//  session and a users table, and a shared secret cannot be revoked from one
//  person without changing it for everyone.
//
//  Requests that are not authorised get 404, not 403. A 403 confirms the path
//  is real and worth attacking; a 404 says nothing.
// ═══════════════════════════════════════════════════════════════════════════

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../database.php';

function admin_deny() {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Not found</title>'
       . '<p style="font:14px/1.6 system-ui;padding:3rem">Not found.</p>';
    exit;
}

if (!isset($_SESSION['username'])) admin_deny();

$q = $conn->prepare("SELECT `id`, `name`, `admin`, `active` FROM `users` WHERE `email` = :e LIMIT 1");
$q->execute([':e' => $_SESSION['username']]);
$admin_user = $q->fetch(PDO::FETCH_ASSOC);

if (!$admin_user || (int)$admin_user['active'] !== 1 || (int)$admin_user['admin'] !== 1) admin_deny();

/**
 * Anything that writes must also prove the request came from our own form.
 * A logged-in admin visiting another site is otherwise enough to trigger a
 * delete, since the browser sends the session cookie either way.
 */
if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

function admin_csrf_field() {
    return '<input type="hidden" name="csrf" value="'
         . htmlspecialchars($_SESSION['admin_csrf'], ENT_QUOTES, 'UTF-8') . '" />';
}

/** Call at the top of every state-changing handler. */
function admin_check_csrf() {
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['admin_csrf'], $sent)) {
        http_response_code(400);
        exit('Bad request.');
    }
}
