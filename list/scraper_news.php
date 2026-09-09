<?php
require_once __DIR__ . '/cache.php';

// ═══════════════════════════════════════════════════════════════════════════
//  Film news aggregator — Austin Chronicle + Austin Film Festival
//
//  A curation feed for the admin panel (_admin/news.php), not the public
//  site — something to skim for Instagram post ideas, not a source that
//  feeds The List. Both sources are real RSS, so this is much simpler than
//  the venue scrapers: no DOMDocument/XPath, just simplexml_load_string().
//
//  Longer TTL than a screening scrape (12h, not 6h) — news doesn't change
//  by the hour, and bin/warm-news.php force-refreshes once a day on its own
//  cadence, independent of bin/warm-cache.php's.
// ═══════════════════════════════════════════════════════════════════════════

const NEWS_CACHE_TTL = 12 * 3600;

/**
 * Fetches and parses one RSS feed into a flat, normalised array: title, url,
 * published (unix timestamp or null), excerpt (plain text, tags stripped).
 * Returns [] on any failure — a malformed feed shouldn't crash the whole
 * page, and ctx_cached_scrape()'s own stale-beats-empty fallback covers a
 * transient outage the same way it already does for every venue scraper.
 */
function fetch_rss_items($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; CinemaTX/1.0)',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $xml = curl_exec($ch);
    curl_close($ch);
    if (!$xml) return [];

    libxml_use_internal_errors(true);
    $feed = simplexml_load_string($xml);
    libxml_clear_errors();
    if (!$feed || !isset($feed->channel->item)) return [];

    $items = [];
    foreach ($feed->channel->item as $item) {
        $title = trim((string) $item->title);
        $link  = trim((string) $item->link);
        if ($title === '' || $link === '') continue;

        // Entities decoded before stripping tags, not after — WordPress
        // escapes markup inside <description> (e.g. "&lt;i&gt;Title&lt;/i&gt;"),
        // so strip_tags() run first never recognises it as a tag at all and
        // the literal "&lt;i&gt;" leaks straight through. Also drops
        // WordPress's own "The post X appeared first on Y." boilerplate,
        // appended to every excerpt on both feeds.
        $excerpt = html_entity_decode((string) $item->description, ENT_QUOTES, 'UTF-8');
        $excerpt = trim(preg_replace('/\s+/', ' ', strip_tags($excerpt)));
        $excerpt = trim(preg_replace('/\s*The post .+ appeared first on .+\.\s*$/is', '', $excerpt));

        $items[] = [
            'title'     => $title,
            'url'       => $link,
            'published' => strtotime((string) $item->pubDate) ?: null,
            'excerpt'   => $excerpt !== '' ? $excerpt : null,
        ];
    }
    return $items;
}

function fetch_chronicle_news($force = false) {
    return ctx_cached_scrape('cache_news_chronicle.json', NEWS_CACHE_TTL, 'fetch_chronicle_news_scrape', $force);
}

// The Chronicle has no dedicated Screens RSS feed — every section shares one
// site-wide feed. Every Screens piece's URL contains "/screens/" though
// (news is "/news/", music is "/music/", and so on), so filtering by that
// is a clean, reliable way to isolate film coverage from the rest of the
// paper without parsing article content.
function fetch_chronicle_news_scrape() {
    $items = fetch_rss_items('https://www.austinchronicle.com/feed/');
    $items = array_values(array_filter($items, fn($i) => strpos($i['url'], '/screens/') !== false));
    foreach ($items as &$i) $i['source'] = 'Austin Chronicle';
    unset($i);
    return $items;
}

function fetch_aff_news($force = false) {
    return ctx_cached_scrape('cache_news_aff.json', NEWS_CACHE_TTL, 'fetch_aff_news_scrape', $force);
}

// Already entirely on-topic — Austin Film Festival's own feed is programming
// announcements, fellowships, and screenwriting-competition news, nothing
// to filter out.
function fetch_aff_news_scrape() {
    $items = fetch_rss_items('https://austinfilmfestival.com/feed/');
    foreach ($items as &$i) $i['source'] = 'Austin Film Festival';
    unset($i);
    return $items;
}

// Both sources merged and sorted newest first — what _admin/news.php
// actually renders. $force passes straight through to both, so the manual
// "Refresh now" button and bin/warm-news.php can force a real re-fetch of
// everything with one call.
function fetch_all_news($force = false) {
    $items = array_merge(fetch_chronicle_news($force), fetch_aff_news($force));
    usort($items, fn($a, $b) => ($b['published'] ?? 0) <=> ($a['published'] ?? 0));
    return $items;
}
