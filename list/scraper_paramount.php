<?php
function fetch_paramount_films() {
    $cache_file = __DIR__ . '/cache_paramount.json';
    $cache_ttl  = 6 * 3600;

    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
        return json_decode(file_get_contents($cache_file), true) ?: [];
    }

    $ch = curl_init('https://www.austintheatre.org/events/2026-summer-classic-film-series/');
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
    $cards = $xpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," filmCard ")]');
    $films = [];
    $tz    = new DateTimeZone('America/Chicago');
    $year  = (int)date('Y');

    foreach ($cards as $card) {
        $link_node  = $xpath->query('.//a[contains(@class,"links__overlay")]', $card)->item(0);
        $dt_node    = $xpath->query('.//div[contains(@class,"color--patina-gold")]', $card)->item(0);
        $title_node = $xpath->query('.//div[contains(@class,"tw-text-xxl")]', $card)->item(0);
        if (!$dt_node || !$title_node) continue;

        $url = $link_node ? trim($link_node->getAttribute('href')) : null;

        // textContent already decodes entities; normalize non-breaking spaces and whitespace
        $raw   = str_replace("\xc2\xa0", ' ', $dt_node->textContent);
        $raw   = preg_replace('/\s+/', ' ', trim($raw));
        $title = trim($title_node->textContent);

        // Split "Thu · Jun 18 · 7:00pm" on the middle dot
        $parts     = preg_split('/\s*·\s*/', $raw);
        $timestamp = null;

        if (count($parts) >= 3) {
            $date_str = trim($parts[1]) . ' ' . $year . ' ' . trim($parts[2]);
            $dt = DateTime::createFromFormat('M j Y g:ia', $date_str, $tz);
            if ($dt) {
                // If the date already passed this year, try next year
                if ($dt->getTimestamp() < time() - 86400) {
                    $dt = DateTime::createFromFormat(
                        'M j Y g:ia',
                        trim($parts[1]) . ' ' . ($year + 1) . ' ' . trim($parts[2]),
                        $tz
                    );
                }
                $timestamp = $dt ? $dt->getTimestamp() : null;
            }
        }

        $films[] = [
            'title'        => $title,
            'url'          => $url,
            'datetime_raw' => $raw,
            'timestamp'    => $timestamp,
            'display_date' => $timestamp
                ? (new DateTime('@' . $timestamp))->setTimezone($tz)->format('D, M j · g:ia')
                : $raw,
        ];
    }

    file_put_contents($cache_file, json_encode($films));
    return $films;
}
