<?php
error_reporting(0);
session_start();

require __DIR__ . '/scraper_paramount.php';
require __DIR__ . '/scraper_afs.php';
require __DIR__ . '/scraper_hyperreal.php';

$tz       = new DateTimeZone('America/Chicago');
$now      = time();
$week_end = $now + 7 * 86400;

function filter_week($films, $now, $week_end) {
    $out = array_values(array_filter($films, function($f) use ($now, $week_end) {
        return isset($f['timestamp']) && $f['timestamp'] >= $now && $f['timestamp'] <= $week_end;
    }));
    usort($out, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
    return $out;
}

// Merge all venues into one chronological list
$sources = [
    ['films' => filter_week(fetch_paramount_films(), $now, $week_end), 'venue' => 'Paramount Theatre'],
    ['films' => filter_week(fetch_afs_films(),        $now, $week_end), 'venue' => 'Austin Film Society'],
    ['films' => filter_week(fetch_hyperreal_films(),  $now, $week_end), 'venue' => 'Hyperreal Film Club'],
];
$all_films = [];
foreach ($sources as $src) {
    foreach ($src['films'] as $film) {
        $film['venue'] = $src['venue'];
        $all_films[]   = $film;
    }
}
usort($all_films, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);

// Group by calendar day
$days = [];
foreach ($all_films as $film) {
    $dt      = (new DateTime('@' . $film['timestamp']))->setTimezone($tz);
    $day_key = $dt->format('Ymd');
    if (!isset($days[$day_key])) {
        $days[$day_key] = ['label' => $dt->format('l, M j'), 'films' => []];
    }
    $film['display_time'] = $dt->format('g:ia');
    $days[$day_key]['films'][] = $film;
}

$today_key = (new DateTime('today', $tz))->format('Ymd');
?>
<html>
<head>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <link rel="stylesheet" href="/css/sass.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/7.0.0/normalize.css" />
  <link rel="icon" href="/img/iconimg.png" type="image/x-icon"/>
  <link rel="shortcut icon" href="/img/iconimg.png" type="image/x-icon"/>
  <script src="https://kit.fontawesome.com/7ea7b5f42f.js" crossorigin="anonymous"></script>
  <script src="https://code.jquery.com/jquery-3.2.1.min.js" integrity="sha256-hwg4gsxgFZhOsEEamdOYGBf13FyQuiTwlAQgxVSNgt4=" crossorigin="anonymous"></script>
  <script src="/js/script.js?v=<?php echo filemtime(dirname(__DIR__) . '/js/script.js'); ?>"></script>
  <script src="/js/script-jlm.js?v=<?php echo filemtime(dirname(__DIR__) . '/js/script-jlm.js'); ?>"></script>
  <script>
    var motwShowtime = 0;
    var motwDur      = 0;
    var motwFilename = '';
  </script>
  <title>The List — Cinema, TX</title>
</head>
<body id="list">
<div class="main-content">

<?php require dirname(__DIR__) . '/header.php'; ?>

<div class="home-base">
  <div class="content-block-w">
    <div class="list-page">

      <div class="list-header">
        <span class="list-stamp" id="list-stamp">Today</span>
        <h2 class="list-title">Screenings</h2>
      </div>

      <div class="list-filter-bar">
        <button class="list-filter-btn active" data-filter="today">Today</button>
        <button class="list-filter-btn" data-filter="week">This Week</button>
      </div>

      <?php foreach ($days as $day_key => $day): ?>
      <div class="list-section list-day-section" data-day="<?php echo $day_key; ?>">
        <div class="list-day-label"><?php echo htmlspecialchars($day['label']); ?></div>

        <?php foreach ($day['films'] as $film): ?>
        <div class="list-card">
          <div class="list-card-date"><?php echo htmlspecialchars($film['display_time']); ?></div>
          <div class="list-card-title">
            <?php if (!empty($film['url'])): ?>
            <a href="<?php echo htmlspecialchars($film['url']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($film['title']); ?></a>
            <?php else: ?>
            <?php echo htmlspecialchars($film['title']); ?>
            <?php endif; ?>
          </div>
          <div class="list-card-venue"><?php echo htmlspecialchars($film['venue']); ?></div>
        </div>
        <?php endforeach; ?>

      </div><!-- /.list-day-section -->
      <?php endforeach; ?>

      <div class="list-empty" id="list-empty-today" style="display:none;">
        No screenings today &mdash; check back soon.
      </div>

    </div><!-- /.list-page -->
  </div>
</div>

</div>

<script>
(function () {
  var todayKey = '<?php echo $today_key; ?>';
  var stamp    = document.getElementById('list-stamp');
  var emptyMsg = document.getElementById('list-empty-today');

  function applyFilter(filter) {
    stamp.textContent = filter === 'today' ? 'Today' : 'This Week';

    var anyVisible = false;
    document.querySelectorAll('.list-day-section').forEach(function (section) {
      var show = filter === 'week' || section.dataset.day === todayKey;
      section.style.display = show ? '' : 'none';
      if (show) anyVisible = true;
    });

    emptyMsg.style.display = (filter === 'today' && !anyVisible) ? '' : 'none';
  }

  document.querySelectorAll('.list-filter-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('.list-filter-btn').forEach(function (b) {
        b.classList.remove('active');
      });
      this.classList.add('active');
      applyFilter(this.dataset.filter);
    });
  });

  applyFilter('today');
}());
</script>

</body>
</html>
