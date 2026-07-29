<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Visitor counting
//
//  Server-side, no cookie, no JavaScript, no third party. It runs during page
//  render, so requests for CSS, JS and images never reach it and cannot pad
//  the numbers.
//
//  What is stored is a salted SHA-256 of the /24 network and the user-agent —
//  never the address itself, and not even the whole network address. The salt is 32 random bytes generated once and kept at
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
//    all.hits           humanHits <tab> botHits, ever
//    YYYY-MM-DD.hits    humanHits <tab> botHits, that day
//
//  A .log line is  hash <tab> country <tab> region <tab> city.  The locale is
//  resolved from the address at the moment of the visit and the address is
//  then discarded — so "Austin, Texas, US" is stored and nothing that
//  identifies the person is. Counting is still `wc -l`.
//
//  The .hits counters answer a question the logs cannot: the logs record a
//  person the first time they appear each day and then say nothing, so a
//  visitor who reads nine pages is indistinguishable from one who bounced.
//  Connections are therefore counted separately, before that dedup, and bots
//  are counted in their own column rather than discarded — crawler load is
//  worth seeing, but it would swamp the human figure if the two were summed.
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

/**
 * The Y-m-d key for today, or $offset days back.
 *
 * Calendar arithmetic in the zone, not a subtraction of 86400s from the clock:
 * two days a year are 23 or 25 hours long, and fixed-width days walking back
 * across one of them repeat a date key or skip one — which shows up as a
 * doubled or missing column in the fourteen-day graph.
 */
function ctx_visits_day($offset = 0) {
    $d = new DateTime('now', new DateTimeZone(CTX_VISITS_TZ));
    if ($offset) $d->modify('-' . (int)$offset . ' days');
    return $d->format('Y-m-d');
}

/**
 * The network an address sits on, rather than the address itself.
 *
 * Mobile carriers hand out a different address every few minutes. On the first
 * evening this ran, one small group of people on T-Mobile produced eleven
 * addresses between them — and eleven "unique visitors". Hashing the /24
 * collapses that to roughly one per group while keeping separate households
 * apart, since two of them sharing both a /24 and an identical user-agent
 * string is unlikely.
 *
 * It is a trade, not a cure: someone moving between 172.56.40.x and
 * 172.56.41.x still counts twice. Coarser than /24 starts merging strangers,
 * which is the worse error.
 */
function ctx_visits_network($ip) {
    if (strpos($ip, ':') !== false) {                 // IPv6 — first four groups
        $parts = explode(':', $ip);
        return implode(':', array_slice($parts, 0, 4));
    }
    $parts = explode('.', $ip);
    return count($parts) === 4 ? implode('.', array_slice($parts, 0, 3)) : $ip;
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
 * Add one to a column of a two-integer counter file.
 *
 * Held open under LOCK_EX across the read and the write. A read, increment and
 * separate write would drop counts whenever two requests overlap, which on a
 * per-connection counter is most of them.
 */
function ctx_visits_bump($file, $column) {
    $fh = @fopen($file, 'c+');
    if (!$fh) return;

    if (flock($fh, LOCK_EX)) {
        $n = ctx_visits_hits_parse(stream_get_contents($fh));
        $n[$column]++;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $n[0] . "\t" . $n[1]);
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
}

/** "12\t3" (or junk, or nothing) as [humanHits, botHits]. */
function ctx_visits_hits_parse($raw) {
    $f = explode("\t", trim((string)$raw));
    return [(int)($f[0] ?? 0), (int)($f[1] ?? 0)];
}

/** A counter file as [humanHits, botHits]; zeroes when it does not exist. */
function ctx_visits_hits($file) {
    return is_readable($file) ? ctx_visits_hits_parse(file_get_contents($file)) : [0, 0];
}

/**
 * Count this request.
 *
 * Two things happen here, and the order matters. Every connection is counted
 * first — human or bot, seen before or not. Only then does the unique-visitor
 * path run, which returns early for anyone already recorded today; that early
 * return is why connections cannot be recovered from the logs alone.
 *
 * Never throws and never blocks the page — a counter is not worth an error.
 */
function ctx_visit() {
    try {
        if (PHP_SAPI === 'cli') return;

        $dir = ctx_visits_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return;
        if (!is_writable($dir)) return;

        // Column 0 is people, column 1 is everything else.
        $human  = ctx_visits_is_human();
        $column = $human ? 0 : 1;
        ctx_visits_bump($dir . '/' . ctx_visits_day() . '.hits', $column);
        ctx_visits_bump($dir . '/all.hits', $column);

        if (!$human) return;

        $hash = hash('sha256', ctx_visits_salt()
                              . ctx_visits_network($_SERVER['REMOTE_ADDR'] ?? '')
                              . ($_SERVER['HTTP_USER_AGENT'] ?? ''));

        // Seen today already? Then there is nothing more to do, and this is the
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

/**
 * The date counting actually began — the oldest day file on disk.
 *
 * This used to be filemtime(all.log), which is when the most recent *new*
 * visitor arrived: a number that advances, and reads as "since yesterday" on
 * a site that has been counting for a year.
 */
function ctx_visits_since() {
    $days = [];
    foreach (glob(ctx_visits_dir() . '/*.log') ?: [] as $file) {
        $key = basename($file, '.log');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) $days[] = $key;   // skips all.log
    }
    if (!$days) return null;
    sort($days);
    return strtotime($days[0] . ' 00:00:00');
}

/**
 * Everything the admin readout needs, in one call.
 *
 * Every day file is read exactly once here. The previous shape re-read them
 * three times over — once for the graph, again for the seven-day window,
 * again for the thirty — which is where the ~52 file reads per page load
 * came from.
 */
function ctx_visit_stats($days = 14) {
    $dir  = ctx_visits_dir();
    $all  = ctx_visits_read($dir . '/all.log');
    $span = max($days, 30);

    $rows = [];   // day => [hash => [country, region, city]]
    $hits = [];   // day => [humanHits, botHits]
    for ($i = 0; $i < $span; $i++) {
        $d = ctx_visits_day($i);
        $rows[$d] = ctx_visits_read($dir . '/' . $d . '.log');
        $hits[$d] = ctx_visits_hits($dir . '/' . $d . '.hits');
    }

    // A union of the hashes, not a sum of the daily figures — summing counts
    // anyone who came back on Tuesday and again on Thursday twice, which would
    // make "this week" larger than it is. Connections are the opposite: every
    // one of them is a separate event, so those do sum.
    $uniques = function ($n) use ($rows) {
        $seen = [];
        foreach (array_slice($rows, 0, $n, true) as $day) $seen += $day;
        return count($seen);
    };
    $connections = function ($n, $column) use ($hits) {
        $sum = 0;
        foreach (array_slice($hits, 0, $n, true) as $h) $sum += $h[$column];
        return $sum;
    };

    $today     = ctx_visits_day();
    $all_hits  = ctx_visits_hits($dir . '/all.hits');
    $recent    = array_map('count', array_slice($rows, 0, $days, true));

    $recent_hits = [];
    foreach (array_slice($hits, 0, $days, true) as $day => $h) {
        $recent_hits[$day] = ['human' => $h[0], 'bot' => $h[1]];
    }

    return [
        'total'  => count($all),
        'today'  => $recent[$today] ?? 0,
        'week'   => $uniques(7),
        'month'  => $uniques(30),
        'recent' => $recent,

        'hits' => [
            'today' => $hits[$today][0] ?? 0,
            'week'  => $connections(7, 0),
            'month' => $connections(30, 0),
            'total' => $all_hits[0],
        ],
        'bots' => [
            'today' => $hits[$today][1] ?? 0,
            'week'  => $connections(7, 1),
            'month' => $connections(30, 1),
            'total' => $all_hits[1],
        ],
        'recent_hits' => $recent_hits,

        'bands'  => ctx_visits_bands($all),
        'places' => ctx_visits_places($all),
        'since'  => ctx_visits_since(),
    ];
}
