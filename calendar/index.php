<?php
// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — Calendar
//
//  The same screenings as The List, laid out as an actual month-shaped grid
//  instead of a day-by-day feed — a different lens on the same data, not a
//  second source of it. Rolling rather than paged: it always runs exactly
//  CTX_LOOKAHEAD_DAYS wide and never grows a "next month" arrow, because
//  there is nothing scraped past that edge to page into.
// ═══════════════════════════════════════════════════════════════════════════
require dirname(__DIR__) . '/v7/_lib.php';

$now = $CTX_NOW;
$tz  = new DateTimeZone('America/Chicago');
$end = $now + CTX_LOOKAHEAD_DAYS * 86400;

$films        = ctx_enrich(fetch_all_screenings($conn, $now, $end));
$n_screenings = count($films);

// Same fallback shape as /list/'s own header — spells out small numbers,
// numerals once the window is wider than the map bothers covering.
$span_word  = [7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten', 11 => 'eleven', 12 => 'twelve'][CTX_LOOKAHEAD_DAYS] ?? (string)CTX_LOOKAHEAD_DAYS;
$span_label = 'next ' . $span_word . ' days';

// Grouped by calendar day, chronological within each — the same shape
// /list/'s own $days uses, just keyed for grid lookup instead of iteration.
$by_day = [];
foreach ($films as $f) {
    $dt  = (new DateTime('@' . $f['timestamp']))->setTimezone($tz);
    $key = $dt->format('Ymd');
    $by_day[$key][] = $f;
}
foreach ($by_day as &$day_films) {
    usort($day_films, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
}
unset($day_films);

// The grid always starts on the Sunday of today's own week and always ends
// on a complete week too, so weekday columns line up top to bottom. The
// padding cells that creates — this week's already-past days, and whatever
// trails past the CTX_LOOKAHEAD_DAYS edge — render blank rather than
// skipped, the same way a wall calendar grays out the neighboring month
// instead of leaving a ragged edge.
$today     = new DateTime('today', $tz);
$grid_start = (clone $today)->modify('-' . (int)$today->format('w') . ' days');
$last_day   = (new DateTime('@' . ($end - 1)))->setTimezone($tz)->setTime(0, 0);
$days_span  = (int)$grid_start->diff($last_day)->days + 1;
$weeks      = (int)ceil($days_span / 7);

$today_key  = $today->format('Ymd');
$cutoff_key = $last_day->format('Ymd');

// Shell
$ctx_title  = 'Calendar — Cinema, TX';
$ctx_active = 'calendar';
$ctx_scroll = true;
$ctx_video  = false;

$e = 'ctx_e';

require dirname(__DIR__) . '/v7/_head.php';
require dirname(__DIR__) . '/v7/_chrome.php';
?>

  <main class="canvas">
    <div class="listing cal" id="calendar">
      <div class="listing__head">
        <div class="listing__title">
          <h1 class="listing__h">Calendar</h1>
          <span class="listing__sub"><?php echo $n_screenings; ?> <?php echo $n_screenings === 1 ? 'screening' : 'screenings'; ?> &middot; <?php echo $span_label; ?></span>
        </div>
      </div>

      <div class="cal-grid">
        <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $wd): ?>
        <div class="cal-wd"><?php echo $wd; ?></div>
        <?php endforeach; ?>

        <?php
        $cursor = clone $grid_start;
        for ($i = 0; $i < $weeks * 7; $i++, $cursor->modify('+1 day')):
            $key       = $cursor->format('Ymd');
            $in_range  = $key >= $today_key && $key <= $cutoff_key;
            $is_today  = $key === $today_key;
            $day_films = $in_range ? ($by_day[$key] ?? []) : [];
        ?>
        <div class="cal-day<?php echo $in_range ? '' : ' cal-day--pad'; ?><?php echo $is_today ? ' cal-day--today' : ''; ?><?php echo $day_films ? '' : ' cal-day--empty'; ?>">
          <span class="cal-day__n"><?php echo (int)$cursor->format('j'); ?></span>
          <?php if ($day_films): ?>
          <span class="cal-day__wd"><?php echo $cursor->format('D j'); ?></span>
          <div class="cal-day__films">
            <?php foreach ($day_films as $s): $href = !empty($s['url']) ? $s['url'] : '#'; ?>
            <a class="cal-event" href="<?php echo $e($href); ?>" target="_blank" rel="noopener">
              <span class="cal-event__title"><?php echo $e($s['display_title']); ?></span><span class="cal-event__dot">&middot;</span><span class="cal-event__time"><?php echo date('g:ia', $s['timestamp']); ?></span>
            </a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endfor; ?>
      </div>
    </div>
  </main>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
