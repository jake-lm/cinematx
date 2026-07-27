<?php
// ═══════════════════════════════════════════════════════════════════════════
//  v7 — shared bootstrap and data access
//
//  Every v7 page requires this first. It owns the database connection, the
//  escaping helper, and one function per content pillar, so the queries stop
//  being copy-pasted into each page the way they were across the five
//  reference builds.
// ═══════════════════════════════════════════════════════════════════════════

if (!defined('CTX_LIB')) {
define('CTX_LIB', 1);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/roles.php';
require_once dirname(__DIR__) . '/list/fetch_screenings.php';
require_once __DIR__ . '/screenings.php';

$CTX_NOW    = time();
$CTX_SIGNED = isset($_SESSION['username']);

// Where "home" points. Flipped from /v7/ to / at the Phase 1 cutover — the
// root now serves the v7 front page. Kept as a constant because the partials
// are still under /v7/ until the conversion finishes and that directory is
// flattened into the project root.
define('CTX_HOME', '/');

/** Escape for HTML. Every page uses this rather than rolling its own. */
function ctx_e($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

/** Canonical post URL. Mirrors the rewrite rule in router.php / .htaccess. */
function ctx_post_url($id, $title) {
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string)$title)), '-');
    return '/posts/' . $slug . '-' . (int)$id;
}

// ── The signed-in member ───────────────────────────────────────────────────

function ctx_me($conn) {
    static $me = false;                       // false = not yet looked up
    if ($me !== false) return $me;
    if (!isset($_SESSION['username'])) return $me = null;
    $q = $conn->prepare("SELECT * FROM `users` WHERE `email` = :e LIMIT 1");
    $q->execute([':e' => $_SESSION['username']]);
    return $me = ($q->fetch(PDO::FETCH_ASSOC) ?: null);
}

/**
 * Which state a visitor is in. The front page has to branch four ways, not
 * two — a fact the earlier reference builds missed entirely.
 *   guest     — not signed in
 *   onboard   — signed in, but no name or role yet
 *   gated     — signed in and named, but active = 0 (needs an access code)
 *   member    — full access
 */
function ctx_state($conn) {
    if (!isset($_SESSION['username'])) return 'guest';
    $me = ctx_me($conn);
    if (!$me) return 'guest';
    $noName = trim((string)($me['name'] ?? '')) === '';
    $noRole = in_array((string)($me['dept'] ?? ''), ['', '0'], true);
    if ($noName || $noRole)      return 'onboard';
    if ((int)($me['active']) === 0) return 'gated';
    return 'member';
}

// ── 01 · The List ──────────────────────────────────────────────────────────

/**
 * Tonight's screenings, widened into tomorrow when the evening is nearly
 * over so the page never shows a near-empty list late at night.
 * Returns ['screenings' => [...], 'label' => 'Tonight'|'Tonight & tomorrow'].
 */
function ctx_tonight($conn, $now) {
    $films = fetch_all_screenings($conn, $now, strtotime('tomorrow', $now) - 1);
    $label = 'Tonight';
    if (count($films) < 5) {
        $wider = fetch_all_screenings($conn, $now, $now + 172800);
        if (count($wider) > count($films)) { $films = $wider; $label = 'Tonight & tomorrow'; }
    }
    return ['screenings' => ctx_enrich($films), 'label' => $label];
}

/** Screening counts keyed by venue slug, for the filter chips. */
function ctx_venue_counts(array $films) {
    $c = [];
    foreach ($films as $f) {
        $k = ctx_slug($f['venue'] ?? '');
        $c[$k] = ($c[$k] ?? 0) + 1;
    }
    return $c;
}

// ── 03 · The Theatre ───────────────────────────────────────────────────────

/**
 * What is on screen now, or next. `notes` joins films.id — the internal
 * library, not the scraped listings — so the programmer's note belongs here.
 */
function ctx_theatre($conn, $now, $theatre = 1) {
    $t = (int)$theatre;
    $q = $conn->prepare("SELECT * FROM `showtimes`
        WHERE `showtime` < :n1 AND `endtime` > :n2 AND `theatre` = $t LIMIT 1");
    $q->execute([':n1' => $now, ':n2' => $now]);
    $playing = $q->fetch(PDO::FETCH_ASSOC);

    $slot = $playing;
    if (!$slot) {
        $q = $conn->prepare("SELECT * FROM `showtimes`
            WHERE `showtime` > :n AND `theatre` = $t ORDER BY `showtime` ASC LIMIT 1");
        $q->execute([':n' => $now]);
        $slot = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $film = null;
    if ($slot) {
        $q = $conn->prepare("SELECT * FROM `films` WHERE `id` = :i LIMIT 1");
        $q->execute([':i' => (int)$slot['f_id']]);
        $film = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $note = null;
    if ($film) {
        $q = $conn->prepare("SELECT * FROM `notes` WHERE `f_id` = :i ORDER BY `stamp` DESC LIMIT 1");
        $q->execute([':i' => (int)$film['id']]);
        $note = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    return [
        'slot'    => $slot,
        'film'    => $film,
        'note'    => $note,
        'is_live' => (bool)$playing,
        'show_ts' => $slot ? (int)$slot['showtime'] : 0,
    ];
}

// ── 02 · The Journal ───────────────────────────────────────────────────────

function ctx_journal($conn, $more = 4) {
    $q = $conn->prepare("SELECT p.*, u.name AS author_name
        FROM posts p LEFT JOIN users u ON p.uid = u.id
        WHERE p.active = 1 AND p.featured = 1 AND p.type IN ('review','essay')
        ORDER BY p.stamp DESC LIMIT 1");
    $q->execute();
    $lead = $q->fetch(PDO::FETCH_ASSOC) ?: null;

    $sql = "SELECT p.id, p.uid, p.title, p.type, p.stamp, p.edited, u.name AS author_name
            FROM posts p LEFT JOIN users u ON p.uid = u.id
            WHERE p.active = 1 AND p.type IN ('review','essay')"
         . ($lead ? " AND p.id != " . (int)$lead['id'] : "")
         . " ORDER BY COALESCE(p.edited, p.stamp) DESC LIMIT " . (int)$more;
    $q = $conn->prepare($sql);
    $q->execute();

    return ['lead' => $lead, 'items' => $q->fetchAll(PDO::FETCH_ASSOC)];
}

// ── 04 · The Lobby ─────────────────────────────────────────────────────────

function ctx_lobby($conn, $limit = 10) {
    $q = $conn->prepare("SELECT p.id, p.uid, p.content, p.stamp, u.name AS author_name
        FROM posts p LEFT JOIN users u ON p.uid = u.id
        WHERE p.active = 1 AND p.type = 'post'
        ORDER BY p.stamp DESC LIMIT " . (int)$limit);
    $q->execute();
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

// ── 05 · Members ───────────────────────────────────────────────────────────

function ctx_members($conn, $limit = 7) {
    $q = $conn->prepare("SELECT id, name, photo FROM `users`
        WHERE `active` = 1 AND `name` != '' ORDER BY `id` DESC LIMIT " . (int)$limit);
    $q->execute();
    return [
        'members' => $q->fetchAll(PDO::FETCH_ASSOC),
        'count'   => (int)$conn->query("SELECT count(*) FROM `users` WHERE `active` = 1 AND `name` != ''")->fetchColumn(),
    ];
}

} // CTX_LIB
