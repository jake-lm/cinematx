<?php
require_once __DIR__ . '/tmdb.php';

function fetch_letterboxd_profile($username) {
    $username = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$username);
    $empty    = ['favorites' => [], 'recent' => []];
    if (!$username) return $empty;

    $cache_file = __DIR__ . '/cache_lb_' . $username . '.json';
    $cache_ttl  = 24 * 3600;

    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
        return json_decode(file_get_contents($cache_file), true) ?: $empty;
    }

    $result = [
        'favorites' => lb_fetch_favorites($username),
        'recent'    => lb_fetch_recent_from_rss($username),
    ];

    file_put_contents($cache_file, json_encode($result));
    return $result;
}

function lb_fetch_favorites($username) {
    $html = lb_curl_get('https://letterboxd.com/' . $username . '/');
    if (!$html) return [];

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query(
        '//div[contains(@class,"favourite-production-poster-container")]//div[@data-component-class="LazyPoster"]'
    );

    $favorites = [];
    foreach ($nodes as $node) {
        $item_name = $node->getAttribute('data-item-name');
        $item_link = $node->getAttribute('data-item-link');
        if (!$item_name || !$item_link) continue;

        $title = $item_name;
        $year  = null;
        if (preg_match('/^(.*)\s\((\d{4})\)$/', $item_name, $m)) {
            $title = trim($m[1]);
            $year  = $m[2];
        }

        $favorites[] = [
            'title'  => $title,
            'year'   => $year,
            'link'   => 'https://letterboxd.com' . $item_link,
            'poster' => fetch_tmdb_poster($title, $year),
        ];
    }
    return $favorites;
}

// RSS is Letterboxd's own sanctioned feed for external consumption — no bot
// wall, and it includes the watched date, which the profile page's HTML does not.
function lb_fetch_recent_from_rss($username, $limit = 3) {
    $xml = lb_curl_get('https://letterboxd.com/' . $username . '/rss/', 'application/rss+xml,application/xml;q=0.9,*/*;q=0.8');
    if (!$xml) return [];

    libxml_use_internal_errors(true);
    $feed = simplexml_load_string($xml);
    libxml_clear_errors();
    if (!$feed || !isset($feed->channel->item)) return [];

    $items = [];
    foreach ($feed->channel->item as $item) {
        $lb = $item->children('https://letterboxd.com');

        $items[] = [
            'title'        => (string)$lb->filmTitle,
            'year'         => (string)$lb->filmYear ?: null,
            'link'         => (string)$item->link,
            'watched_date' => (string)$lb->watchedDate ?: null,
        ];

        if (count($items) >= $limit) break;
    }
    return $items;
}

function lb_curl_get($url, $accept = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8') {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: ' . $accept,
            'Accept-Language: en-US,en;q=0.9',
        ],
        CURLOPT_ENCODING       => '',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response ?: null;
}
