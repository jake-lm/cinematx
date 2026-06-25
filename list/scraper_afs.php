<?php
function fetch_afs_films() {
    $cache_file = __DIR__ . '/cache_afs.json';
    $cache_ttl  = 6 * 3600;

    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
        return json_decode(file_get_contents($cache_file), true) ?: [];
    }

    $ch = curl_init('https://www.austinfilm.org/calendar/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; CinemaTX/1.0)',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $html = curl_exec($ch);
    curl_close($ch);

    if (!$html) return [];

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $tz    = new DateTimeZone('America/Chicago');
    $films = [];

    // Each calendar day cell carries its date in the id attribute (YYYYMMDD)
    $days = $xpath->query('//td[contains(@class,"c-calendar__day") and not(contains(@class,"c-calendar__day--inactive"))]');

    foreach ($days as $day) {
        $date_id = $day->getAttribute('id'); // e.g. "20260621"
        if (!preg_match('/^\d{8}$/', $date_id)) continue;

        // Only screenings (red text) — skip afs_event entries entirely
        $screenings = $xpath->query(
            './/div[contains(@class,"afs_screening") and contains(@class,"current") and not(contains(@class,"expired"))]',
            $day
        );

        foreach ($screenings as $event) {
            $link_node  = $xpath->query('.//a[contains(@class,"afs_screening_link")]', $event)->item(0);
            $time_node  = $xpath->query('.//p[contains(@class,"t-smaller")]', $event)->item(0);
            if (!$link_node) continue;

            $title    = trim($link_node->textContent);
            $url      = $link_node->getAttribute('href') ?: null;
            $time_str = $time_node ? trim($time_node->textContent) : '';

            $timestamp = null;
            if ($time_str) {
                $dt = DateTime::createFromFormat('Ymd g:i A', "$date_id $time_str", $tz);
                $timestamp = $dt ? $dt->getTimestamp() : null;
            }
            if (!$timestamp) {
                // Fallback: midnight of the day
                $dt = DateTime::createFromFormat('Ymd', $date_id, $tz);
                $timestamp = $dt ? $dt->getTimestamp() : null;
            }

            $films[] = [
                'title'        => $title,
                'url'          => $url,
                'timestamp'    => $timestamp,
                'display_date' => $timestamp
                    ? (new DateTime('@' . $timestamp))->setTimezone($tz)->format('D, M j · g:ia')
                    : $date_id,
            ];
        }
    }

    file_put_contents($cache_file, json_encode($films));
    return $films;
}
