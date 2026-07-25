<?php
require_once __DIR__ . '/scraper_paramount.php';
require_once __DIR__ . '/scraper_afs.php';
require_once __DIR__ . '/scraper_hyperreal.php';
require_once __DIR__ . '/tmdb.php';

function filter_screenings($films, $now, $end) {
    $out = array_values(array_filter($films, function($f) use ($now, $end) {
        return isset($f['timestamp']) && $f['timestamp'] >= $now && $f['timestamp'] <= $end;
    }));
    usort($out, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
    return $out;
}

// Merges official venue listings (scraped) with user-submitted screenings
// (events table) into one chronological array, filtered to [$now, $end].
function fetch_all_screenings($conn, $now, $end) {
    $sources = [
        ['films' => filter_screenings(fetch_paramount_films(), $now, $end), 'venue' => 'Paramount Theatre'],
        ['films' => filter_screenings(fetch_afs_films(),        $now, $end), 'venue' => 'Austin Film Society'],
        ['films' => filter_screenings(fetch_hyperreal_films(),  $now, $end), 'venue' => 'Hyperreal Film Club'],
    ];
    $all_films = [];
    foreach ($sources as $src) {
        foreach ($src['films'] as $film) {
            $film['venue']  = $src['venue'];
            $film['poster'] = fetch_tmdb_poster($film['title']);
            $film['source'] = 'official';
            $all_films[]    = $film;
        }
    }

    $events_q = $conn->prepare(
        "SELECT e.id, e.title, e.poster, e.screentime, e.location
         FROM events e
         WHERE e.active = 1 AND e.screentime >= :now AND e.screentime <= :end
         ORDER BY e.screentime ASC"
    );
    $events_q->execute([':now' => $now, ':end' => $end]);
    foreach ($events_q->fetchAll(PDO::FETCH_ASSOC) as $event) {
        $all_films[] = [
            'title'     => $event['title'],
            'timestamp' => (int)$event['screentime'],
            'venue'     => $event['location'],
            'poster'    => $event['poster'] ? '/uploads/events/' . $event['poster'] : null,
            'url'       => '/events/?id=' . $event['id'],
            'source'    => 'user',
        ];
    }

    usort($all_films, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
    return $all_films;
}
