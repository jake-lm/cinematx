<?php
error_reporting(0);
session_start();
require '../database.php';
require '../list/scraper_letterboxd.php';

if (!isset($_SESSION['username'])) {
  header('Location: /?error=auth'); exit;
}

$me_q = $conn->prepare("SELECT * FROM `users` WHERE `email` = :email");
$me_q->execute([':email' => $_SESSION['username']]);
$qUser = $me_q->fetch();

if (!$qUser || $qUser['active'] == 0) {
  header('Location: /'); exit;
}

$profile_id = (int)($_GET['id'] ?? 0);
$profile_q  = $conn->prepare("SELECT * FROM `users` WHERE `id` = :id AND `active` = 1");
$profile_q->execute([':id' => $profile_id]);
$profileUser = $profile_q->fetch();

if (!$profileUser) {
  header('Location: /directory'); exit;
}

// latest status — most recent generic post by this user
$status_q = $conn->prepare(
  "SELECT p.id, p.uid, p.content, p.type, p.stamp, u.name AS author_name, u.photo AS author_photo
   FROM posts p LEFT JOIN users u ON p.uid = u.id
   WHERE p.active = 1 AND p.uid = :uid AND p.type = 'post'
   ORDER BY p.stamp DESC LIMIT 1"
);
$status_q->execute([':uid' => $profile_id]);
$latest_status = $status_q->fetch(PDO::FETCH_ASSOC);

// personal hero — latest review/essay by this user, regardless of featured flag
$hero_q = $conn->prepare(
  "SELECT p.*, u.name AS author_name FROM posts p LEFT JOIN users u ON p.uid = u.id
   WHERE p.active = 1 AND p.uid = :uid AND p.type IN ('review','essay')
   ORDER BY p.stamp DESC LIMIT 1"
);
$hero_q->execute([':uid' => $profile_id]);
$personal_hero = $hero_q->fetch(PDO::FETCH_ASSOC);

// Letterboxd
$lb_data      = !empty($profileUser['lb']) ? fetch_letterboxd_profile($profileUser['lb']) : ['favorites' => [], 'recent' => []];
$lb_favorites = $lb_data['favorites'];
$lb_recent    = $lb_data['recent'];

// Theatre 1 — current/next film (same queries as /directory)
$now = time();
$sql_now1  = $conn->query("SELECT * FROM `showtimes` WHERE $now > `showtime` AND $now < `endtime` AND `theatre` = 1");
$playing1  = $sql_now1->fetch();

if ($playing1) {
  $sql5    = $conn->prepare("SELECT * FROM `showtimes` WHERE $now > `showtime` AND $now < `endtime` AND `theatre` = 1");
  $sql5->execute();
  $showtime5 = $sql5->fetch();
  $film_th1  = $conn->query("SELECT * FROM `films` WHERE `id` = {$showtime5['f_id']}")->fetch();
} else {
  $sql9    = $conn->prepare("SELECT * FROM `showtimes` WHERE $now < `showtime` AND `theatre` = 1 ORDER BY `showtime` ASC LIMIT 1");
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
  <title><?php echo htmlspecialchars($profileUser['name'] ?? 'Profile'); ?> — Cinema, TX</title>
</head>
<body id="index">

<div class="main-content">

<?php require '../header.php'; ?>

<div class="home-base">

  <div class="content-block-w member-layout">

    <!-- LEFT COLUMN -->
    <div class="home-left">

      <div class="profile-card">
        <div class="profile-card-photo">
          <?php if (!empty($profileUser['photo'])): ?>
            <img src="/uploads/profiles/<?php echo htmlspecialchars($profileUser['photo']); ?>" alt="<?php echo htmlspecialchars($profileUser['name']); ?>" />
          <?php else: ?>
            <i class="fa-solid fa-user"></i>
          <?php endif; ?>
        </div>
        <div class="profile-card-info">
          <div class="profile-card-name"><?php echo htmlspecialchars($profileUser['name']); ?></div>
          <div class="profile-card-meta">
            <?php if (!empty($profileUser['dept']) && $profileUser['dept'] !== '0'): ?>
              <?php echo htmlspecialchars($profileUser['dept']); ?>
            <?php endif; ?>
            <?php if (!empty($profileUser['sign_date'])): ?>
              <?php if (!empty($profileUser['dept']) && $profileUser['dept'] !== '0'): ?> &middot; <?php endif; ?>
              Member since <?php echo date('M Y', $profileUser['sign_date']); ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php if ($latest_status): ?>
      <?php $ls_date = date('M j', $latest_status['stamp']); ?>
      <div class="feed-card" data-post-id="<?php echo $latest_status['id']; ?>">
        <?php if ($latest_status['uid'] == $qUser['id']): ?>
        <button class="feed-card-delete" data-post-id="<?php echo $latest_status['id']; ?>" title="Delete post">&times;</button>
        <?php endif; ?>
        <a class="feed-card-avatar" href="/users/profile.php?id=<?php echo $latest_status['uid']; ?>">
          <?php if (!empty($latest_status['author_photo'])): ?>
          <img src="/uploads/profiles/<?php echo htmlspecialchars($latest_status['author_photo']); ?>" alt="" />
          <?php else: ?>
          <i class="fa-solid fa-user"></i>
          <?php endif; ?>
        </a>
        <div class="feed-card-main">
          <div class="feed-card-body">
            <div class="feed-card-content"><?php echo nl2br(htmlspecialchars($latest_status['content'])); ?></div>
          </div>
          <div class="feed-card-foot">
            <button class="feed-read-more">Read &rarr;</button>
            <span class="feed-card-meta"><a class="author-link" href="/users/profile.php?id=<?php echo $latest_status['uid']; ?>"><?php echo htmlspecialchars($latest_status['author_name'] ?? 'Member'); ?></a> &middot; <?php echo $ls_date; ?></span>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($lb_favorites || $lb_recent): ?>
      <div class="letterboxd-panel">
        <div class="letterboxd-panel-header">Letterboxd</div>

        <?php if ($lb_favorites): ?>
        <div class="lb-subtitle">Top 4</div>
        <div class="lb-favorites">
          <?php foreach ($lb_favorites as $fav): ?>
          <a class="lb-favorite-card" href="<?php echo htmlspecialchars($fav['link']); ?>" target="_blank" rel="noopener" title="<?php echo htmlspecialchars($fav['title']); ?><?php echo $fav['year'] ? ' (' . $fav['year'] . ')' : ''; ?>">
            <?php if (!empty($fav['poster'])): ?>
            <img src="<?php echo htmlspecialchars($fav['poster']); ?>" alt="<?php echo htmlspecialchars($fav['title']); ?>" />
            <?php else: ?>
            <span class="lb-favorite-title"><?php echo htmlspecialchars($fav['title']); ?></span>
            <?php endif; ?>
          </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($lb_recent): ?>
        <div class="lb-recent-section">
        <div class="lb-subtitle">Recently Watched</div>
        <ul class="lb-recent-list">
          <?php foreach ($lb_recent as $r): ?>
          <li class="lb-recent-item">
            <a class="lb-recent-link" href="<?php echo htmlspecialchars($r['link']); ?>" target="_blank" rel="noopener">
              <?php echo htmlspecialchars($r['title']); ?><?php if ($r['year']): ?> <span class="lb-recent-year">(<?php echo htmlspecialchars($r['year']); ?>)</span><?php endif; ?>
            </a>
            <?php if (!empty($r['watched_date'])): ?>
            <span class="lb-recent-date"><?php echo date('M j', strtotime($r['watched_date'])); ?></span>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
        </div><!-- /.lb-recent-section -->
        <?php endif; ?>
      </div>
      <?php endif; ?>

    </div><!-- /.home-left -->

    <!-- RIGHT COLUMN -->
    <div class="home-right">

      <?php if ($personal_hero): ?>
      <?php
        $ph_date = date('F j, Y', $personal_hero['edited'] ?: $personal_hero['stamp']);
      ?>
      <div class="featured-hero">
        <div class="featured-tag-row">
          <?php if ($personal_hero['type']): ?><span class="post-type-pill"><?php echo htmlspecialchars($personal_hero['type']); ?></span><?php endif; ?>
        </div>

        <h1 class="post-headline"><?php echo htmlspecialchars($personal_hero['title'] ?? ''); ?></h1>

        <?php if ($personal_hero['subtitle']): ?>
        <p class="post-sub"><?php echo htmlspecialchars($personal_hero['subtitle']); ?></p>
        <?php endif; ?>

        <div class="post-meta">
          <?php if ($personal_hero['author_name']): ?>
            <span><?php echo htmlspecialchars($personal_hero['author_name']); ?></span>
            <span class="post-meta-dot">&middot;</span>
          <?php endif; ?>
          <span><?php echo $ph_date; ?></span>
        </div>

        <?php if ($personal_hero['image']): ?>
        <div class="post-hero-wrap" style="margin-bottom:14px;">
          <img class="post-hero" src="/uploads/posts/<?php echo htmlspecialchars($personal_hero['image']); ?>" alt="<?php echo htmlspecialchars($personal_hero['title'] ?? ''); ?>" />
          <div class="post-share">
            <a class="share-btn share-twitter" href="#" title="Share on X">
              <span class="share-label">Share</span>
              <i class="fa-brands fa-x-twitter"></i>
            </a>
            <a class="share-btn share-instagram" href="#" title="Share on Instagram">
              <span class="share-label">Share</span>
              <i class="fa-brands fa-instagram"></i>
            </a>
            <a class="share-btn share-reddit" href="#" title="Share on Reddit">
              <span class="share-label">Share</span>
              <i class="fa-brands fa-reddit-alien"></i>
            </a>
          </div>
        </div>
        <?php endif; ?>

        <div class="featured-body-wrap">
          <div class="featured-body"><?php echo nl2br(htmlspecialchars($personal_hero['content'])); ?></div>
          <div class="featured-fade"></div>
        </div>

        <button class="featured-read-more" id="featured-read-btn">Read &rarr;</button>
      </div>
      <?php endif; ?>

    </div><!-- /.home-right -->

  </div><!-- /.member-layout -->

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

</div><!-- /.main-content -->

</body>
</html>
