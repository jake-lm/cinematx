<?php
error_reporting(0);
session_start();
require '../database.php';
require '../roles.php';

if (!isset($_SESSION['username'])) {
  header('Location: /?error=auth'); exit;
}

$user  = $_SESSION['username'];
$sql11 = $conn->prepare("SELECT * FROM `users` WHERE `email` = :email");
$sql11->execute([':email' => $user]);
$qUser = $sql11->fetch();

if (!$qUser || $qUser['active'] == 0) {
  header('Location: /'); exit;
}

$now      = time();
$tomorrow = $now + 172800;

// Theatre 1 — current/next film (same queries as index.php)
$sql_now1  = $conn->query("SELECT * FROM `showtimes` WHERE $now > `showtime` AND $now < `endtime` AND `theatre` = 1");
$playing1  = $sql_now1->fetch();

if ($playing1) {
  $sql5     = $conn->prepare("SELECT * FROM `showtimes` WHERE $now > `showtime` AND $now < `endtime` AND `theatre` = 1");
  $sql5->execute();
  $showtime5 = $sql5->fetch();
  $film_th1  = $conn->query("SELECT * FROM `films` WHERE `id` = {$showtime5['f_id']}")->fetch();
} else {
  $sql9     = $conn->prepare("SELECT * FROM `showtimes` WHERE $now < `showtime` AND `theatre` = 1 ORDER BY `showtime` ASC LIMIT 1");
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

// Directory
$community_count   = $conn->query("SELECT count(*) FROM `users` WHERE `active` = 1 AND `name` != ''")->fetchColumn();
$community_q       = $conn->prepare("SELECT `name`, `dept` FROM `users` WHERE `active` = 1 AND `name` != '' ORDER BY `sign_date` DESC LIMIT 3");
$community_q->execute();
$community_members = $community_q->fetchAll(PDO::FETCH_ASSOC);

$limiter = 8;
$qDept   = $qUser['dept'];
$sql2    = $conn->prepare("SELECT * FROM `users` WHERE `active` = 1 AND `dept` != '0' AND `name` != '' ORDER BY `sign_date` DESC LIMIT 25");
$sql2->execute();
?>
<html>
<head>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <link rel="stylesheet" href="/css/sass.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/7.0.0/normalize.css" />
  <link href="https://vjs.zencdn.net/7.8.3/video-js.css" rel="stylesheet" />
  <link rel="icon" href="/img/iconimg.png" type="image/x-icon"/>
  <script src="https://kit.fontawesome.com/7ea7b5f42f.js" crossorigin="anonymous"></script>
  <script src="https://code.jquery.com/jquery-3.2.1.min.js" integrity="sha256-hwg4gsxgFZhOsEEamdOYGBf13FyQuiTwlAQgxVSNgt4=" crossorigin="anonymous"></script>
  <script src="https://vjs.zencdn.net/7.8.3/video.js"></script>
  <script src="/js/script.js?v=<?php echo filemtime('../js/script.js'); ?>"></script>
  <script src="/js/script-jlm.js?v=<?php echo filemtime('../js/script-jlm.js'); ?>"></script>
  <script>
    var motwShowtime = <?php echo (int)$motw_showtime_ts; ?>;
    var motwDur      = <?php echo (int)($film_th1['dur'] ?? 0); ?>;
    var motwFilename = <?php echo json_encode($film_th1['filename'] ?? ''); ?>;
  </script>
  <title>Directory — Cinema, TX</title>
</head>
<body id="directory">

<div class="main-content">

<?php require '../header.php'; ?>

<div class="home-base">

  <div class="content-block-w">

    <div class="community-panel no-toggle active" id="community-panel">
      <div class="community-header">
        <div class="lower-bar" style="text-align:right;">
          <span class="txt">The Directory</span>
        </div>
        <div class="info-txt"><?php echo $community_count; ?> members</div>
        <div class="since-txt">
          <?php foreach($community_members as $m): ?>
            <?php echo htmlspecialchars($m['name']); ?><?php if(!empty($m['dept'])): ?> — <?php echo htmlspecialchars($m['dept']); ?><?php endif; ?><br>
          <?php endforeach; ?>
        </div>
        <div class="bg-gradient"></div>
      </div>
      <div class="community-body">
        <div class="thelist">
          <span class="subtitle">Sort By:
            <select id="loadsort" class="subtle" onchange="loadSort()">
              <option name="sign_date">New</option>
              <option name="last_date">Most Active</option>
              <option name="dept" value="<?php echo htmlspecialchars($qDept); ?>">My Role</option>
              <option name="mylist">My List</option>
            </select>
            &nbsp;
            <input class="subtle list_search" name="list_search" placeholder="Search" onkeyup="list_search()" />
            <sup><i class="fa-solid fa-circle-info hover-info">
              <div class="info-box" style="width:175px;text-align:left;">
                Ex. area codes, names, or roles.
              </div>
            </i></sup>
          </span>
          <div class="list_entry">
            <?php
            for ($i = 0; $i < $limiter; ++$i) {
              $lUser = $sql2->fetch();
              if (!$lUser) break;
              $name = $lUser['name'];
              $type = ($i % 2 == 0) ? 'odd' : 'even';
              include '../entries.php';
            }
            ?>
          </div>
          <div id="reContain"></div>
          <div class="loadmore-hold">
            <button class="entry loadmoretxt" onclick="loadMore()">Load more</button>
          </div>
        </div>
      </div>
    </div>

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

</div><!-- /.home-base -->

<!-- Member overlay -->
<div class="member-overlay" id="member-overlay">
  <div class="member-overlay-card" id="member-overlay-card">
    <button class="member-overlay-close" id="member-overlay-close">&#x2715;</button>
    <div class="member-overlay-content" id="member-overlay-content"></div>
  </div>
</div>

</div><!-- /.main-content -->

</body>
</html>
