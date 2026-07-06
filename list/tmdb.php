<?php
function fetch_tmdb_poster($title, $year = null) {
    if (!defined('TMDB_API_KEY') || !TMDB_API_KEY) return null;

    $cache_file = __DIR__ . '/cache_tmdb.json';
    $cache_key  = $title . '|' . ($year ?: '');
    $cache      = file_exists($cache_file) ? (json_decode(file_get_contents($cache_file), true) ?: []) : [];

    if (array_key_exists($cache_key, $cache)) {
        return $cache[$cache_key];
    }

    $url = 'https://api.themoviedb.org/3/search/movie?api_key=' . TMDB_API_KEY
         . '&query=' . urlencode($title)
         . ($year ? '&year=' . urlencode($year) : '');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) return null; // transient failure — don't cache, retry next time

    $data        = json_decode($response, true);
    $poster_path = $data['results'][0]['poster_path'] ?? null;
    $poster      = $poster_path ? 'https://image.tmdb.org/t/p/w300' . $poster_path : null;

    $cache[$cache_key] = $poster;
    file_put_contents($cache_file, json_encode($cache));

    return $poster;
}
