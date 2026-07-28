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
//    all.log            one line per unique visitor, ever
//    YYYY-MM-DD.log     one line per unique visitor that day
//
//  A line is  hash <tab> country <tab> region <tab> city.  The locale is
//  resolved from the address at the moment of the visit and the address is
//  then discarded — so "Austin, Texas, US" is stored and nothing that
//  identifies the person is. Counting is still `wc -l`.
// ═══════════════════════════════════════════════════════════════════════════

function ctx_visits_dir() {
    return dirname(__DIR__) . '/data/visits';
}

// Day boundaries are Austin's, stated explicitly rather than inherited.
// This file is deliberately standalone — it does not pull in database.php,
// which is what calls date_default_timezone_set() — so a caller that skipped
// that (bin/visits.php did) computed its date keys in UTC while the site wrote
// them in Chicago. After 7pm they disagreed about which day it was, and the
// readout quietly reported the wrong file.
const CTX_VISITS_TZ = 'America/Chicago';

/** The Y-m-d key for today, or $offset days back. */
function ctx_visits_day($offset = 0) {
    $d = new DateTime('@' . (time() - $offset * 86400));
    $d->setTimezone(new DateTimeZone(CTX_VISITS_TZ));
    return $d->format('Y-m-d');
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

/**
 * Geolocate, using a local database — no request leaves this machine.
 *
 * Called once per new visitor per day, never per request, so three small
 * subprocesses are cheaper than they look. Returns empty strings when the
 * database is absent, which is the case in development, and everything
 * downstream treats that as "Unknown" rather than failing.
 */
function ctx_visits_locate($ip) {
    $db = '/usr/local/share/dbip/dbip-city.mmdb';
    if ($ip === '' || !is_readable($db)) return ['', '', ''];

    $ask = function ($path) use ($db, $ip) {
        $cmd = 'mmdblookup --file ' . escapeshellarg($db) . ' --ip ' . escapeshellarg($ip)
             . ' ' . $path . ' 2>/dev/null';
        // The value sits on its own line, quoted, after a blank one.
        if (!preg_match('/"([^"]*)"/', (string)@shell_exec($cmd), $m)) return '';
        return trim($m[1]);
    };

    return [$ask('country iso_code'), $ask('subdivisions 0 names en'), $ask('city names en')];
}

/**
 * Append a record keyed on its first field, if that key is not already present.
 * Returns true if it was added.
 */
function ctx_visits_remember($file, $key, $line) {
    $existing = is_readable($file) ? file_get_contents($file) : '';
    if ($existing !== '' && strpos($existing, $key) !== false) return false;
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
        // path almost every request takes — no lookup, no subprocess.
        $today = $dir . '/' . ctx_visits_day() . '.log';
        $seen  = is_readable($today) ? file_get_contents($today) : '';
        if ($seen !== '' && strpos($seen, $hash) !== false) return;

        [$country, $region, $city] = ctx_visits_locate($_SERVER['REMOTE_ADDR'] ?? '');
        $line = implode("\t", [$hash, $country, $region, $city]);

        ctx_visits_remember($today, $hash, $line);
        ctx_visits_remember($dir . '/all.log', $hash, $line);
    } catch (Throwable $e) {
        error_log('visit counter: ' . $e->getMessage());
    }
}

/** Every record in a log file, as [hash, country, region, city]. */
function ctx_visits_read($file) {
    if (!is_readable($file)) return [];
    $rows = [];
    foreach (explode("\n", file_get_contents($file)) as $line) {
        if ($line === '') continue;
        $f = explode("\t", $line);
        $rows[$f[0]] = [$f[1] ?? '', $f[2] ?? '', $f[3] ?? ''];
    }
    return $rows;
}

/**
 * Unique visitors across a window of days.
 *
 * A union of the hashes, not a sum of the daily figures — summing counts
 * anyone who came back on Tuesday and again on Thursday twice, which would
 * make "this week" larger than it is.
 */
function ctx_visits_window($days) {
    $dir = ctx_visits_dir();
    $seen = [];
    for ($i = 0; $i < $days; $i++) {
        foreach (ctx_visits_read($dir . '/' . ctx_visits_day($i) . '.log') as $h => $loc) {
            $seen[$h] = $loc;
        }
    }
    return $seen;
}

/**
 * Which of these people are near enough to actually turn up?
 *
 * The bands are the point of the whole exercise — a list of cities does not
 * answer "is this reaching Austin", and a percentage of a country does not
 * either.
 */
function ctx_visits_bands(array $rows) {
    $bands = ['Austin' => 0, 'Elsewhere in Texas' => 0, 'Elsewhere in the US' => 0,
              'International' => 0, 'Unknown' => 0];
    foreach ($rows as [$country, $region, $city]) {
        if ($country === '')                       $bands['Unknown']++;
        elseif ($country !== 'US')                 $bands['International']++;
        elseif ($region !== 'Texas')               $bands['Elsewhere in the US']++;
        elseif (stripos($city, 'Austin') === 0)    $bands['Austin']++;
        else                                       $bands['Elsewhere in Texas']++;
    }
    return $bands;
}

/** Places, most frequent first. */
function ctx_visits_places(array $rows, $limit = 12) {
    $places = [];
    foreach ($rows as [$country, $region, $city]) {
        $label = $city !== '' ? $city : ($region !== '' ? $region : 'Unknown');
        if ($country !== '' && $country !== 'US') $label .= ', ' . $country;
        elseif ($region !== '' && $city !== '')   $label .= ', ' . $region;
        $places[$label] = ($places[$label] ?? 0) + 1;
    }
    arsort($places);
    return array_slice($places, 0, $limit, true);
}

/** Everything the admin readout needs, in one call. */
function ctx_visit_stats($days = 14) {
    $dir = ctx_visits_dir();
    $all = ctx_visits_read($dir . '/all.log');

    $recent = [];
    for ($i = 0; $i < $days; $i++) {
        $d = ctx_visits_day($i);
        $recent[$d] = count(ctx_visits_read($dir . '/' . $d . '.log'));
    }

    return [
        'total'  => count($all),
        'today'  => $recent[ctx_visits_day()] ?? 0,
        'week'   => count(ctx_visits_window(7)),
        'month'  => count(ctx_visits_window(30)),
        'recent' => $recent,
        'bands'  => ctx_visits_bands($all),
        'places' => ctx_visits_places($all),
        'since'  => is_readable($dir . '/all.log') ? filemtime($dir . '/all.log') : null,
    ];
}
