<?php
function fetch_hyperreal_films() {
    $cache_file = __DIR__ . '/cache_hyperreal.json';
    $cache_ttl  = 6 * 3600;

    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
        return json_decode(file_get_contents($cache_file), true) ?: [];
    }

    // Determine which month(s) to fetch — if the next 7 days spill into the next month, grab both
    $now    = time();
    $tz     = new DateTimeZone('America/Chicago');
    $months = [date('m-Y', $now)];
    if (date('n', $now + 7 * 86400) !== date('n', $now)) {
        $months[] = date('m-Y', strtotime('first day of next month', $now));
    }

    $seen  = [];
    $films = [];

    foreach ($months as $month) {
        $url  = 'https://hyperrealfilm.club/events?view=calendar&month=' . $month;
        $ch   = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; CinemaTX/1.0)',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $html = curl_exec($ch);
        curl_close($ch);
        if (!$html) continue;

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // li elements whose h1 link slug contains "movie-screening"
        $items = $xpath->query(
            '//main[contains(@class,"Main--events-list")]
             //li[.//h1/a[contains(@href,"movie-screening")]]'
        );

        foreach ($items as $item) {
            $link_node = $xpath->query('.//h1/a', $item)->item(0);
            if (!$link_node) continue;

            $href = $link_node->getAttribute('href');
            $abs_url = 'https://hyperrealfilm.club' . $href;

            if (isset($seen[$abs_url])) continue; // deduplicate across months
            $seen[$abs_url] = true;

            // Strip " at HYPERREAL FILM CLUB" suffix (with optional trailing space)
            $raw_title = trim($link_node->textContent);
            $title     = trim(preg_replace('/\s+at\s+hyperreal\s+film\s+club\s*$/i', '', $raw_title));

            // Find the date/time div — it's the one that contains "AM" or "PM"
            $date_text = '';
            $divs = $xpath->query('./div', $item);
            foreach ($divs as $div) {
                $text = trim($div->textContent);
                if (preg_match('/[AP]M/i', $text) && strlen($text) > 5) {
                    $date_text = html_entity_decode($text, ENT_HTML5, 'UTF-8');
                    // Normalize Unicode whitespace variants (narrow no-break space U+202F, etc.) to plain spaces
                    $date_text = preg_replace('/[\x{00A0}\x{202F}\x{2009}\x{200A}]/u', ' ', $date_text);
                    $date_text = preg_replace('/\s+/', ' ', trim($date_text));
                    break;
                }
            }

            // Parse "Tuesday, June 24, 2026, 7:30 PM – 11:00 PM" — we only need the start
            $timestamp = null;
            if ($date_text && preg_match('/(\w+, \w+ \d+, \d{4}, \d+:\d+ [AP]M)/i', $date_text, $m)) {
                $dt = DateTime::createFromFormat('l, F j, Y, g:i A', $m[1], $tz);
                $timestamp = $dt ? $dt->getTimestamp() : null;
            }

            $films[] = [
                'title'        => $title,
                'url'          => $abs_url,
                'timestamp'    => $timestamp,
                'display_date' => $timestamp
                    ? (new DateTime('@' . $timestamp))->setTimezone($tz)->format('D, M j · g:ia')
                    : trim($date_text),
            ];
        }
    }

    file_put_contents($cache_file, json_encode($films));
    return $films;
}
