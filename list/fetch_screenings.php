<?php
require_once __DIR__ . '/scraper_paramount.php';
require_once __DIR__ . '/scraper_afs.php';
require_once __DIR__ . '/scraper_hyperreal.php';
require_once __DIR__ . '/scraper_alamo.php';
require_once __DIR__ . '/scraper_fathom.php';
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
// $force re-scrapes regardless of TTL. Only the warm script passes it — page
// requests must never trigger a forced scrape.
function fetch_all_screenings($conn, $now, $end, $force = false) {
    $sources = [
        ['films' => filter_screenings(fetch_paramount_films($force), $now, $end), 'venue' => 'Paramount Theatre'],
        ['films' => filter_screenings(fetch_afs_films($force),       $now, $end), 'venue' => 'Austin Film Society'],
        ['films' => filter_screenings(fetch_hyperreal_films($force), $now, $end), 'venue' => 'Hyperreal Film Club'],
        // Five Austin locations under one venue name, so the filter bar gets
        // one chip rather than five. Which location is carried per screening.
        ['films' => filter_screenings(fetch_alamo_films($force),     $now, $end), 'venue' => 'Alamo Drafthouse'],
        // A distributor, not a cinema — one booking lands in six chains at
        // once, so it folds the same way Alamo does.
        ['films' => filter_screenings(fetch_fathom_films($force),    $now, $end), 'venue' => 'Fathom Events'],
    ];
    $all_films = [];
    foreach ($sources as $src) {
        foreach ($src['films'] as $film) {
            $film['venue']    = $src['venue'];
            $film['source']   = 'official';
            $film['location'] = $film['location'] ?? null;

            if (empty($film['no_tmdb'])) {
                $tmdb = fetch_tmdb($film['title']);
                $film['poster']   = $tmdb['poster'];
                $film['year']     = $tmdb['year'];
                $film['runtime']  = $tmdb['runtime'];
                $film['overview'] = $tmdb['overview'];
                $film['genres']   = $tmdb['genres'];
                $film['director'] = $tmdb['director'];
                $film['cast']     = $tmdb['cast'];
                $film['wiki']     = $tmdb['wiki'];
            } else {
                // A curated shorts anthology (see afs_is_short_program()) —
                // TMDB has no correct entry to borrow from, so only what the
                // scraper already found (its own poster, "Various" as
                // director, the runtime off the same page) survives;
                // everything else stays absent rather than showing a guess.
                $film['year']     = null;
                $film['overview'] = null;
                $film['genres']   = null;
                $film['cast']     = null;
                $film['wiki']     = null;
            }

            $all_films[] = $film;
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
            // Members submit a title and a time, not a filmography. The
            // renderer omits whatever is null rather than showing a gap.
            'year'      => null,
            'runtime'   => null,
            'location'  => null,
            'overview'  => null,
            'genres'    => null,
            'director'  => null,
            'cast'      => null,
            'wiki'      => null,
        ];
    }

    usort($all_films, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
    return $all_films;
}
