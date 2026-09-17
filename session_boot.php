<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Central session bootstrap
//
//  Every entry point that touches $_SESSION calls ctx_session_start() instead
//  of session_start() directly, so the cookie lifetime and idle timeout live
//  in exactly one place — the same reasoning as database.php's error policy
//  comment: a per-file ini_set() is how things drift.
//
//  PHP's stock session.gc_maxlifetime (24 minutes) was the real complaint
//  behind "logged-in users time out too fast": the host's own session-cleanup
//  timer reads gc_maxlifetime straight from php.ini and deletes any session
//  file untouched that long, and it ignores anything set here at runtime — so
//  reaching the intended 24-hour window also requires raising
//  session.gc_maxlifetime on the server itself (see DEPLOY.md). This file is
//  the half of the fix that travels with the code:
//    - the cookie itself is issued for 24 hours instead of "until browser
//      close" (session.cookie_lifetime's default of 0)
//    - it's re-issued on every request so an admin active at least once a day
//      never hits the wall mid-task — without this, PHP only sends Set-Cookie
//      when a session is first created, so the cookie would otherwise still
//      expire 24h after login regardless of activity in between
//    - an explicit last-activity check acts as a backstop that doesn't depend
//      on server config at all (e.g. local dev, or a host where php.ini
//      hasn't been touched)
// ═══════════════════════════════════════════════════════════════════════════

const CTX_SESSION_LIFETIME = 86400; // 24 hours

function ctx_session_start() {
    if (session_status() !== PHP_SESSION_NONE) return;

    // Local dev serves plain http:// — a cookie marked Secure is silently
    // dropped by the browser there, which would break every sign-in.
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_set_cookie_params([
        'lifetime' => CTX_SESSION_LIFETIME,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Lax',
    ]);
    session_start();

    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > CTX_SESSION_LIFETIME) {
        $_SESSION = [];
        session_destroy();
        session_start();
    }
    $_SESSION['last_activity'] = time();

    setcookie(session_name(), session_id(), time() + CTX_SESSION_LIFETIME, '/', '', $https, true);
}
