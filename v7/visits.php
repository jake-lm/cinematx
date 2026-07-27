<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Visitor counting
//
//  Server-side, no cookie, no JavaScript, no third party. It runs during page
//  render, so requests for CSS, JS and images never reach it and cannot pad
//  the numbers.
//
//  What is stored is a salted SHA-256 of the IP and user-agent — never the
//  address itself. The salt is 32 random bytes generated once and kept at
//  0600 outside the served tree; without it the hashes cannot be walked back
//  to an address, and it is the reason this can honestly sit under an About
//  page promising no unvolunteered data is collected.
//
//  The salt is persistent rather than daily-rotating, which is the one real
//  trade-off here. A rotating salt would be stronger, but it makes the same
//  person a different hash tomorrow, and then an all-time total is impossible
//  — only a sum of daily figures, which double-counts everyone who returns.
//  A stable count was the point, so: persistent salt, kept secret.
//
//  Files, under data/visits/ (gitignored, denied in .htaccess):
//    .salt              the secret
//    all.log            one hash per unique visitor, ever
//    YYYY-MM-DD.log     one hash per unique visitor that day
//
//  Counting is therefore `wc -l`, and nothing needs parsing.
// ═══════════════════════════════════════════════════════════════════════════

function ctx_visits_dir() {
    return dirname(__DIR__) . '/data/visits';
}

/** The secret salt, created on first use. */
function ctx_visits_salt() {
    $path = ctx_visits_dir() . '/.salt';
    if (is_readable($path)) {
        $salt = file_get_contents($path);
        if (strlen($salt) >= 32) return $salt;
    }
    $salt = random_bytes(32);
    file_put_contents($path, $salt, LOCK_EX);
    @chmod($path, 0600);
    return $salt;
}

/**
 * Is this request plausibly a person?
 *
 * Worth being strict. On the day this was written the raw logs held 58 unique
 * addresses, of which two were us and most of the rest were crawlers — a count
 * that does not exclude them is not measuring anything.
 */
function ctx_visits_is_human() {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if ($ua === '') return false;

    // Self-declared automation. Catches MJ12bot, facebookexternalhit, curl,
    // and the great majority of scanners, which do not bother lying.
    if (preg_match('~bot|crawl|spider|slurp|search|fetch|curl|wget|python|java|go-http|okhttp|scan|monitor|headless|lighthouse|preview|facebookexternal|embedly|whatsapp|telegram~i', $ua)) {
        return false;
    }

    // A browser asks for HTML and states a language. Scanners posing as
    // iPhones — which is most of the noise from the cloud ranges — typically
    // send neither.
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (strpos($accept, 'text/html') === false) return false;
    if (empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) return false;

    return true;
}

/** Append $line to $file if it is not already there. Returns true if added. */
function ctx_visits_remember($file, $line) {
    $existing = is_readable($file) ? file_get_contents($file) : '';
    if ($existing !== '' && strpos($existing, $line) !== false) return false;
    file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    return true;
}

/**
 * Count this request, if it looks like a person we have not seen.
 * Never throws and never blocks the page — a counter is not worth an error.
 */
function ctx_visit() {
    try {
        if (PHP_SAPI === 'cli' || !ctx_visits_is_human()) return;

        $dir = ctx_visits_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return;
        if (!is_writable($dir)) return;

        $hash = hash('sha256', ctx_visits_salt()
                              . ($_SERVER['REMOTE_ADDR'] ?? '')
                              . ($_SERVER['HTTP_USER_AGENT'] ?? ''));

        // Seen today already? Then there is nothing to do, and this is the
        // path almost every request takes.
        $today = $dir . '/' . date('Y-m-d') . '.log';
        if (!ctx_visits_remember($today, $hash)) return;

        ctx_visits_remember($dir . '/all.log', $hash);
    } catch (Throwable $e) {
        error_log('visit counter: ' . $e->getMessage());
    }
}

/** Totals for the admin readout. */
function ctx_visit_stats($days = 14) {
    $dir = ctx_visits_dir();
    $lines = function ($f) {
        if (!is_readable($f)) return 0;
        return max(0, substr_count(file_get_contents($f), "\n"));
    };

    $recent = [];
    for ($i = 0; $i < $days; $i++) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $recent[$d] = $lines($dir . '/' . $d . '.log');
    }

    return [
        'total'  => $lines($dir . '/all.log'),
        'today'  => $recent[date('Y-m-d')] ?? 0,
        'recent' => $recent,
        'since'  => is_readable($dir . '/all.log') ? filemtime($dir . '/all.log') : null,
    ];
}
