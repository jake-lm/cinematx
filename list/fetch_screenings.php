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
        // Fathom Events — parked, not removed. Uncomment to bring it back;
        // everything downstream (ctx_fold_venue('Fathom Events', …) in
        // v7/index.php and list/index.php, scraper_fathom.php itself) is
        // untouched and ready — this array entry was always the single
        // point deciding whether Fathom's screenings exist at all.
        // ['films' => filter_screenings(fetch_fathom_films($force), $now, $end), 'venue' => 'Fathom Events'],
    ];
    $all_films = [];
    foreach ($sources as $src) {
        foreach ($src['films'] as $film) {
            $film['venue']    = $src['venue'];
            $film['source']   = 'official';
            $film['location'] = $film['location'] ?? null;

            if (empty($film['no_tmdb'])) {
                // AFS-only for now (see scraper_afs.php) — absent for every
                // other venue, so this is a no-op there.
                $tmdb = fetch_tmdb($film['title'], null, $film['director_hint'] ?? null);
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
                // scraper already found off AFS's own page (poster,
                // "Various" as director, runtime, synopsis) survives;
                // everything else stays absent rather than showing a guess.
                $film['year']   = null;
                $film['genres'] = null;
                $film['cast']   = null;
                $film['wiki']   = null;
            }

            $all_films[] = $film;
        }
    }

    // Joined against users.admin so an admin-added screening (see
    // _admin/events.php) reads as 'source' => 'admin' rather than 'user' —
    // the one thing that lets it into Instagram's arthouse pipeline
    // (list/instagram.php's ig_arthouse_films()) and keeps a real member
    // submission out of it, without a schema change either way.
    $events_q = $conn->prepare(
        "SELECT e.id, e.title, e.director, e.year, e.runtime, e.genres, e.poster, e.screentime, e.location, e.ticket_url, e.synopsis, u.admin
         FROM events e
         LEFT JOIN users u ON u.id = e.uid
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
            // A ticket link (see _admin/events.php) behaves exactly like a
            // scraped screening's own url — clicking through from anywhere
            // on the site goes straight to it. Without one, the fallback is
            // this listing's own page, same as always.
            'url'       => $event['ticket_url'] ?: ('/events/?id=' . $event['id']),
            'source'    => !empty($event['admin']) ? 'admin' : 'user',
            // Members submit a title and a time, not a filmography — none
            // of these extra fields exist on that flow, only
            // _admin/events.php's. The renderer omits whatever is null
            // rather than showing a gap.
            'year'      => $event['year'] ?: null,
            'runtime'   => $event['runtime'] ?: null,
            'location'  => null,
            'overview'  => $event['synopsis'] ?: null,
            'genres'    => $event['genres'] ?: null,
            'director'  => $event['director'] ?: null,
            'cast'      => null,
            'wiki'      => null,
        ];
    }

    usort($all_films, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
    return $all_films;
}
