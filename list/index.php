<?php
error_reporting(0);
session_start();
require dirname(__DIR__) . '/database.php';
require __DIR__ . '/fetch_screenings.php';

$tz       = new DateTimeZone('America/Chicago');
$now      = time();
$week_end = $now + 7 * 86400;

// Theatre 1 — current/next film (same queries as /directory and /users/profile.php)
$sql_now1  = $conn->query("SELECT * FROM `showtimes` WHERE $now > `showtime` AND $now < `endtime` AND `theatre` = 1");
$playing1  = $sql_now1->fetch();

if ($playing1) {
    $sql5      = $conn->prepare("SELECT * FROM `showtimes` WHERE $now > `showtime` AND $now < `endtime` AND `theatre` = 1");
    $sql5->execute();
    $showtime5 = $sql5->fetch();
    $film_th1  = $conn->query("SELECT * FROM `films` WHERE `id` = {$showtime5['f_id']}")->fetch();
} else {
    $sql9      = $conn->prepare("SELECT * FROM `showtimes` WHERE $now < `showtime` AND `theatre` = 1 ORDER BY `showtime` ASC LIMIT 1");
    $sql9->execute();
    $showtime9 = $sql9->fetch();
    $film_th1  = $showtime9 ? $conn->query("SELECT * FROM `films` WHERE `id` = {$showtime9['f_id']}")->fetch() : [];
}

$motw_showtime_ts = 0;
if ($playing1 && isset($showtime5['showtime']))      $motw_showtime_ts = $showtime5['showtime'];
elseif (!$playing1 && isset($showtime9['showtime'])) $motw_showtime_ts = $showtime9['showtime'];
$motw_time = $motw_showtime_ts ? date('g:ia', $motw_showtime_ts) : '';

$sql12_film = $film_th1['id'] ?? 0;
$sql12 = $conn->prepare("SELECT * FROM `showtimes` WHERE `endtime` > :now AND `theatre` = 1 AND `f_id` = :fid ORDER BY `showtime` ASC");
$sql12->bindValue(':now', $now);
$sql12->bindValue(':fid', $sql12_film, PDO::PARAM_INT);
$sql12->execute();

$note_q = $conn->prepare("SELECT * FROM `notes` WHERE `f_id` = :fid ORDER BY `stamp` DESC LIMIT 1");
$note_q->bindValue(':fid', $sql12_film, PDO::PARAM_INT);
$note_q->execute();
$note_th1 = $note_q->fetch();

$all_films = fetch_all_screenings($conn, $now, $week_end);

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

$today_key    = (new DateTime('today', $tz))->format('Ymd');
$tomorrow_key = (new DateTime('tomorrow', $tz))->format('Ymd');
?>
<html>
<head>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <link rel="stylesheet" href="/css/sass.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/7.0.0/normalize.css" />
  <link href="https://vjs.zencdn.net/7.8.3/video-js.css" rel="stylesheet" />
  <link rel="icon" href="/img/iconimg.png" type="image/x-icon"/>
  <link rel="shortcut icon" href="/img/iconimg.png" type="image/x-icon"/>
  <script src="https://kit.fontawesome.com/7ea7b5f42f.js" crossorigin="anonymous"></script>
  <script src="https://code.jquery.com/jquery-3.2.1.min.js" integrity="sha256-hwg4gsxgFZhOsEEamdOYGBf13FyQuiTwlAQgxVSNgt4=" crossorigin="anonymous"></script>
  <script src="https://vjs.zencdn.net/7.8.3/video.js"></script>
  <script src="/js/script.js?v=<?php echo filemtime(dirname(__DIR__) . '/js/script.js'); ?>"></script>
  <script src="/js/script-jlm.js?v=<?php echo filemtime(dirname(__DIR__) . '/js/script-jlm.js'); ?>"></script>
  <script>
    var motwShowtime = <?php echo (int)$motw_showtime_ts; ?>;
    var motwDur      = <?php echo (int)($film_th1['dur'] ?? 0); ?>;
    var motwFilename = <?php echo json_encode($film_th1['filename'] ?? ''); ?>;
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
        <button class="list-filter-btn" data-filter="tmrw">Tmrw</button>
        <button class="list-filter-btn" data-filter="week">This Week</button>
      </div>

      <div class="list-filter-bar list-source-filter-bar">
        <button class="list-filter-btn active" data-source="all">All</button>
        <button class="list-filter-btn" data-source="user">User</button>
      </div>

      <?php foreach ($days as $day_key => $day): ?>
      <div class="list-section list-day-section" data-day="<?php echo $day_key; ?>">
        <div class="list-day-label"><?php echo htmlspecialchars($day['label']); ?></div>

        <div class="list-card-grid">
        <?php foreach ($day['films'] as $film): ?>
        <div class="list-card" data-source="<?php echo htmlspecialchars($film['source'] ?? 'official'); ?>">
          <?php if (!empty($film['url'])): ?>
          <a class="list-card-poster" href="<?php echo htmlspecialchars($film['url']); ?>" target="_blank" rel="noopener">
          <?php else: ?>
          <div class="list-card-poster">
          <?php endif; ?>
            <div class="list-card-date"><?php echo htmlspecialchars($film['display_time']); ?></div>
            <?php if (($film['source'] ?? 'official') === 'user'): ?>
            <span class="list-card-badge">Community</span>
            <?php endif; ?>
            <?php if (!empty($film['poster'])): ?>
            <img src="<?php echo htmlspecialchars($film['poster']); ?>" alt="<?php echo htmlspecialchars($film['title']); ?>" />
            <?php else: ?>
            <span class="list-card-poster-title"><?php echo htmlspecialchars($film['title']); ?></span>
            <?php endif; ?>
          <?php if (!empty($film['url'])): ?>
          </a>
          <?php else: ?>
          </div>
          <?php endif; ?>
          <div class="list-card-info">
            <div class="list-card-title"><?php echo htmlspecialchars($film['title']); ?></div>
            <div class="list-card-venue"><?php echo htmlspecialchars($film['venue']); ?></div>
          </div>
        </div>
        <?php endforeach; ?>
        </div><!-- /.list-card-grid -->

      </div><!-- /.list-day-section -->
      <?php endforeach; ?>

      <div class="list-empty" id="list-empty-today" style="display:none;">
        No screenings today &mdash; check back soon.
      </div>

    </div><!-- /.list-page -->
  </div>

  <div class="motw" id="motw-banner">
    <span class="stamp">The Theatre</span>
    <div class="banner" style="background-image:url('/motw/<?php echo $film_th1['poster'] ?? ''; ?>.png')">
      <span class="marquee-border"></span>
      <div class="overlay">
        <span class="txt">Showtimes</span>
        <?php
        for ($i = 1; $i <= 1; ++$i) {
          $showtime3 = $sql12->fetch();
          if ($showtime3) echo '<span class="subtxt">' . date('D, h:ia', $showtime3['showtime']) . '</span>';
        }
        ?>
      </div>
    </div>
    <span class="title"><?php echo $film_th1['title'] ?? ''; ?></span>
    <span class="subtitle"><?php echo $film_th1['director'] ?? ''; ?></span>
    <?php if (!empty($note_th1['note'])): ?>
    <div class="motw-note"><?php echo $note_th1['note']; ?><?php if (!empty($film_th1['program'])): ?><span class="motw-programmer"> — <?php echo $film_th1['program']; ?></span><?php endif; ?></div>
    <?php endif; ?>

    <div class="motw-theatre" id="motw-theatre">
      <button class="motw-close" id="motw-close" title="Close theatre">&#x2715;</button>
      <div class="motw-theatre-inner">
        <div class="motw-video-hold" id="motw-home-hold">
          <video id="motw-home" class="video-js" oncontextmenu="return false;" controls preload
            poster="/motw/<?php echo $film_th1['poster'] ?? ''; ?>.png"></video>
        </div>
        <div class="motw-theatre-info">
          <div class="topbar">
            <span class="title">
              <?php if (isset($film_th1['title'])): ?>
                <a href="<?php echo $film_th1['wiki']; ?>" target="_blank"><?php echo $film_th1['title']; ?></a><?php if ($motw_time): ?> - <?php echo $motw_time; ?><?php endif; ?>
              <?php else: ?>Thanks for watching<?php endif; ?>
            </span>
            <div class="bottombar">
              <span class="directed"><?php echo $film_th1['director'] ?? 'More movies every night.'; ?></span>
              <span class="dur"><?php echo gmdate('g\hi\m', $film_th1['dur'] ?? 0); ?></span>
            </div>
          </div>
          <div class="info-txt">
            <?php echo $note_th1['note'] ?? ''; ?>
            <?php if (!empty($film_th1['program'])): ?>
            <span class="motw-programmer">— <?php echo $film_th1['program']; ?></span>
            <?php endif; ?>
          </div>
          <div class="player-controls" data-player="motw-home">
            <button class="ctrl-btn ctrl-mute" title="Mute / Unmute"><i class="fa-solid fa-volume-high"></i></button>
            <input class="ctrl-volume-slider" type="range" min="0" max="1" step="0.05" value="1" />
            <button class="ctrl-btn ctrl-fullscreen" title="Fullscreen"><i class="fa-solid fa-expand"></i></button>
          </div>
          <div class="motw-th1-link"><a href="/th1">Open in Theatre 1 &rarr;</a></div>
        </div>
      </div>
    </div>
  </div>

</div>

</div>

<script>
(function () {
  var todayKey    = '<?php echo $today_key; ?>';
  var tomorrowKey = '<?php echo $tomorrow_key; ?>';
  var stamp       = document.getElementById('list-stamp');
  var emptyMsg    = document.getElementById('list-empty-today');
  var labels      = { today: 'Today', tmrw: 'Tomorrow', week: 'This Week' };

  var timeFilter   = 'today';
  var sourceFilter = 'all';

  function applyFilters() {
    stamp.textContent = labels[timeFilter] || 'Today';
    var dayKey = timeFilter === 'tmrw' ? tomorrowKey : todayKey;

    var anyVisible = false;
    document.querySelectorAll('.list-day-section').forEach(function (section) {
      var dayMatches = timeFilter === 'week' || section.dataset.day === dayKey;
      var sectionHasVisible = false;

      section.querySelectorAll('.list-card').forEach(function (card) {
        var sourceMatches = sourceFilter === 'all' || card.dataset.source === sourceFilter;
        var show = dayMatches && sourceMatches;
        card.style.display = show ? '' : 'none';
        if (show) sectionHasVisible = true;
      });

      section.style.display = sectionHasVisible ? '' : 'none';
      if (sectionHasVisible) anyVisible = true;
    });

    emptyMsg.textContent = 'No screenings ' + (timeFilter === 'tmrw' ? 'tomorrow' : 'today') + ' — check back soon.';
    emptyMsg.style.display = (timeFilter !== 'week' && !anyVisible) ? '' : 'none';
  }

  document.querySelectorAll('.list-filter-bar').forEach(function (bar) {
    bar.querySelectorAll('.list-filter-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        bar.querySelectorAll('.list-filter-btn').forEach(function (b) {
          b.classList.remove('active');
        });
        this.classList.add('active');

        if (this.dataset.filter) timeFilter = this.dataset.filter;
        if (this.dataset.source) sourceFilter = this.dataset.source;

        applyFilters();
      });
    });
  });

  applyFilters();
}());
</script>

</body>
</html>
