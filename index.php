<?php
error_reporting(0);
session_start();
require 'database.php';
require 'roles.php';

// ******* theatre support start ------------------------------

$now = time();
$today = strtotime('today', $now);  $today = $today + 21600;
$tomorrow = $now + 172800; // 48 hours from now

// $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // set the PDO error mode to exception

$sql_q1 = $conn->query("SELECT * FROM `showtimes` WHERE `showtime` >
    $now AND `showtime` < $tomorrow AND `theatre` = 2"); // select from now to tomorrow.

  if(! $sql_q1) { // if it doesn't exist

  }
  else { // if it does
    $sql = $conn->prepare("SELECT * FROM `showtimes` WHERE `endtime` >
      $now AND `showtime` < $tomorrow AND `theatre` = 2 ORDER BY `showtime` ASC"); // select from now (endtime) to tomorrow.
    $sql->execute();
    $films_count = $conn->query("SELECT count(*) FROM `showtimes` WHERE `endtime` > $now AND `showtime` < $tomorrow AND `theatre` = 2")->fetchColumn();
  }

  $sql_q2 = $conn->query("SELECT * FROM `showtimes` WHERE `endtime` >
    $now AND `showtime` < $tomorrow AND `theatre` = 1");

  if(! $sql_q2) { // if it doesn't exist

    }
  else { // if it does
      $sql1 = $conn->prepare("SELECT * FROM `showtimes` WHERE `endtime` >
        $now AND `showtime` < $tomorrow AND `theatre` = 1 ORDER BY `showtime` ASC");
      $sql1->execute();
      $films_count1 = $conn->query("SELECT count(*) FROM `showtimes` WHERE `endtime` > $now AND `showtime` < $tomorrow AND `theatre` = 1")->fetchColumn();
      $films_count2 = $conn->query("SELECT count(*) FROM `showtimes` WHERE `endtime` > $now AND `theatre` = 1")->fetchColumn();
    }
    $sql_now1 = $conn->query("SELECT * FROM `showtimes` WHERE $now > `showtime` AND $now < `endtime` AND `theatre` = 1");
    $playing1 = $sql_now1->fetch();
  
    $sql_next1 = $conn->query("SELECT * FROM `showtimes` WHERE $now < `showtime` AND `theatre` = 1 LIMIT 1");
    $playing_next1 = $sql_next1->fetch();
  
    $sql_now2 = $conn->query("SELECT * FROM `showtimes` WHERE $now > `showtime` AND $now < `endtime` AND `theatre` = 2");
    $playing2 = $sql_now2->fetch();
  
    $sql_next2 = $conn->query("SELECT * FROM `showtimes` WHERE $now < `showtime` AND `theatre` = 2 LIMIT 1");
    $playing_next2 = $sql_next2->fetch();

  
    if(! $playing1) {
      if(! $playing_next1) {
        // Why aren't any films next?
      }
      else {
        $sql9 = $conn->prepare("SELECT * FROM `showtimes` WHERE $now < `showtime` AND `theatre` = 1 ORDER BY `showtime` ASC LIMIT 1");
        $sql9->execute();
        $showtime9 = $sql9->fetch();
        $f_idth1 = $showtime9['f_id'];
        $sql6 = $conn->prepare("SELECT * FROM `films` WHERE `id` = $f_idth1");
        $sql6->execute();
        $film_th1 = $sql6->fetch();
      }
    }
    else {
      $sql5 = $conn->prepare("SELECT * FROM `showtimes` WHERE $now > `showtime` AND $now < `endtime` AND `theatre` = 1");
      $sql5->execute();
      $showtime5 = $sql5->fetch();
      $f_idth1 = $showtime5['f_id'];
      $sql6 = $conn->prepare("SELECT * FROM `films` WHERE `id` = $f_idth1");
      $sql6->execute();
      $film_th1 = $sql6->fetch();
    }
  
    if(! $playing2) {
      if(! $playing_next2) {
        // Why aren't any films next?
      }
      else {
        $sql10 = $conn->prepare("SELECT * FROM `showtimes` WHERE $now < `showtime` AND `theatre` = 2 ORDER BY `showtime` ASC LIMIT 1");
        $sql10->execute();
        $showtime10 = $sql10->fetch();
        $f_idth1 = $showtime10['f_id'];
        $sql6 = $conn->prepare("SELECT * FROM `films` WHERE `id` = $f_idth1");
        $sql6->execute();
        $film_th2 = $sql6->fetch();
      }
    }
    else {
      $sql7 = $conn->prepare("SELECT * FROM `showtimes` WHERE $now > `showtime` AND $now < `endtime` AND `theatre` = 2");
      $sql7->execute();
      $showtime6 = $sql7->fetch();
      $f_idth2 = $showtime6['f_id'];
      $sql8 = $conn->prepare("SELECT * FROM `films` WHERE `id` = $f_idth2");
      $sql8->execute();
      $film_th2 = $sql8->fetch();
    }

    $sql12_film = $film_th1['id'];

    $sql12 = $conn->prepare("SELECT * FROM `showtimes` WHERE `endtime` > :now AND `theatre` = 1 AND `f_id` = :film_id ORDER BY `showtime` ASC");
    $sql12->bindValue(':now', $now, PDO::PARAM_STR);
    $sql12->bindValue(':film_id', $sql12_film, PDO::PARAM_INT);
    $sql12->execute();

    $note_q = $conn->prepare("SELECT * FROM `notes` WHERE `f_id` = :film_id ORDER BY `stamp` DESC LIMIT 1");
    $note_q->bindValue(':film_id', $sql12_film, PDO::PARAM_INT);
    $note_q->execute();
    $note_th1 = $note_q->fetch();

    $community_count = $conn->query("SELECT count(*) FROM `users` WHERE `active` = 1 AND `name` != ''")->fetchColumn();

    $featured_post_q = $conn->prepare(
      "SELECT p.*, u.name AS author_name FROM posts p LEFT JOIN users u ON p.uid = u.id
       WHERE p.active = 1 AND p.featured = 1 AND p.type IN ('review', 'essay') ORDER BY p.stamp DESC LIMIT 1"
    );
    $featured_post_q->execute();
    $featured_post = $featured_post_q->fetch(PDO::FETCH_ASSOC);

    $posts_front_q = $conn->prepare("SELECT p.id, p.title, p.subtitle, p.type, p.image, p.stamp, p.edited, u.name AS author_name
                                     FROM posts p LEFT JOIN users u ON p.uid = u.id
                                     WHERE p.active = 1 AND p.type IN ('review', 'essay')
                                     ORDER BY COALESCE(p.edited, p.stamp) DESC LIMIT 2");
    $posts_front_q->execute();
    $posts_front = $posts_front_q->fetchAll(PDO::FETCH_ASSOC);

    $posts_feed_q = $conn->prepare(
      "SELECT p.id, p.uid, p.content, p.type, p.stamp, u.name AS author_name
       FROM posts p LEFT JOIN users u ON p.uid = u.id
       WHERE p.active = 1 AND p.type = 'post'
       ORDER BY p.stamp DESC LIMIT 3"
    );
    $posts_feed_q->execute();
    $posts_feed = $posts_feed_q->fetchAll(PDO::FETCH_ASSOC);

    // resolved showtime for motw theatre embed
    $motw_showtime_ts = 0;
    if ($playing1 && isset($showtime5['showtime'])) {
      $motw_showtime_ts = $showtime5['showtime'];
    } elseif (!$playing1 && isset($showtime9['showtime'])) {
      $motw_showtime_ts = $showtime9['showtime'];
    }
    $motw_time = $motw_showtime_ts ? date('g:ia', $motw_showtime_ts) : '';

// ******* theatre support end ----------------------------

if(isset($_SESSION['username'])) {
$user = $_SESSION['username'];
$sql11 = $conn->prepare("SELECT * FROM `users` WHERE `email` = '$user'");
$sql11->execute();
$qUser=$sql11->fetch();
$isName = isset($qUser['name']);

$users_count = $conn->query("SELECT count(*) FROM `users` WHERE `active` = 1")->fetchColumn();
if($users_count < $limit) {
  $limit = $users_count;
}

$view = $_GET['view'];
$limitview = $_GET['limit'];
$limit = 25;
switch($limitview){ // replace w/function
  case $users_count: $limit = $users_count;
  case 25: $limit=50; break;
  case 50: $limit=75; break;
  case 75: $limit=100; break;
  case 100: $limit=125; break;
  case 125: $limit=150; break;
  case 150: $limit=175; break;
  case 175: $limit=200; break;
  case 200: $limit=225; break;
}

$fName = substr($qUser['name'],0,strrpos($qUser['name'], " "));
if($fName == "") $fName = null;
}
?>
<html>
<head>
  <meta name="author" content="Dr. Zoidberg" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <meta property="og:title" content="" />
  <meta property="og:description" content="" />
  <link rel="stylesheet" href="css/sass.css" />
  <!--link rel="stylesheet" href="css/sass-jlm.scss" /-->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/7.0.0/normalize.css" />
  <link href="https://vjs.zencdn.net/7.8.3/video-js.css" rel="stylesheet" />
  <link rel="icon" href="/img/iconimg.png" type="image/x-icon"/>
  <link rel="shortcut icon" href="/img/iconimg.png" type="image/x-icon"/>
  <script src="https://kit.fontawesome.com/7ea7b5f42f.js" crossorigin="anonymous"></script>
  <script src="https://code.jquery.com/jquery-3.2.1.min.js" integrity="sha256-hwg4gsxgFZhOsEEamdOYGBf13FyQuiTwlAQgxVSNgt4=" crossorigin="anonymous"></script>
  <script src="https://vjs.zencdn.net/7.8.3/video.js"></script>
  <script src="js/script.js?v=<?php echo filemtime('js/script.js'); ?>"></script>
  <script src="js/script-jlm.js?v=<?php echo filemtime('js/script-jlm.js'); ?>"></script>
  <script>
    var motwShowtime = <?php echo (int)$motw_showtime_ts; ?>;
    var motwDur      = <?php echo (int)($film_th1['dur'] ?? 0); ?>;
    var motwFilename = <?php echo json_encode($film_th1['filename'] ?? ''); ?>;
  </script>
  <title>Cinema, TX</title>
</head>
<body id="index">

<div class="main-content">

<?php require 'header.php'; ?>

<div class="home-base">

  <div class="content-block-w">
    <?php
    if(isset($_SESSION['username'])){ // if logged in
    /*if($qUser['name'] != "" || $isName = false) {
      echo '<div class="title">Welcome, '.$qUser['name'].'.</div>';
    }
    else {
      echo '<div class="title">Welcome.</div>';
    }*/
    if($qUser['dept'] == null || $qUser['dept'] == "0" || $qUser['name'] == null || $qUser['name'] == "") { // if info is empty
      $error = $_GET['error'] ?? null;
      if($error === "106") { // login issue
        echo "<div class='error'>Please enter a Role and Name.</div><br>";
      }
    ?>
    <form action="dashboard/signup.php?action=firstcontact" method="post">
      <div class="info-hold">
        <div class="smallbox">
          Please select the role that best describes you.
          <br><br>
          Required to proceed.
        </div>

        <div class="input-hold">
          <label>Role</label>
          <select class="input-2" name="dept" id="role-select">
            <option value="0">Select one:</option>
            <?php foreach($roles as $role): ?>
            <option value="<?php echo $role; ?>"><?php echo $role; ?></option>
            <?php endforeach; ?>
          </select>
          <span class="role-desc" id="role-desc"></span>
          <!-- position field — archived indefinitely -->
          <!-- <input class="input-2" type="text" name="position" placeholder="Specify position*" /> -->
        </div>
      </div>

      <br>

      <div class="info-hold">
        <div class="smallbox">
          Tell us a little about yourself.
          <br><br>
          (name is required)
        </div>
      <div class="input-hold">
        <label>Name</label>
        <input class="input-2" value="<?php echo $qUser['name']; ?>" type="text" name="uname" placeholder="Required" />
        <label>Phone</label>
        <input class="input-2" value="<?php echo $qUser['phone']; ?>" type="tel" name="phone" placeholder="Optional" />
        <label>Website</label>
        <input class="input-2" value="<?php echo $qUser['website']; ?>" type="text" name="website" placeholder="Optional" />
        <label>Letterboxd</label>
        <input class="input-2" value="<?php echo $qUser['lb']; ?>" type="text" name="lb" placeholder="Optional" />
        <input type="hidden" value="<?php echo $qUser['id']; ?>" name="uid" />
        <input type="submit" class="submit" value="Continue" />
      </div>
      </div>
    </form>
    <?php
    }
    else {

    if($qUser['active'] == 0) { // if w/o access
      ?>
      <form action="dashboard/signup.php?action=activateacct" method="post">
        <div class="info-hold">

            <div class="smallbox">
              Please submit an access code to view and be included on the list.
              If you don't have one, feel free to email us or search around your
              local coffee shop.
            </div>

            <div class="input-hold">

          <input class="input-2" type="text" name="code" placeholder="Access code" /><br>
          <input type="hidden" value="<?php echo $qUser['id']; ?>" name="uid" />
          <input class="submit" value="Submit" type="submit" />

            </div>
        </div>

      </form>
      <?php
    }
    else {
      ?>
      <div class="content-block-w member-layout">

        <!-- LEFT COLUMN -->
        <div class="home-left">

          <!-- Quick post box -->
          <div class="quick-post-wrap">
            <div class="qp-selector" id="qp-selector">
              <div class="qp-selector-current">
                <span id="qp-current-label">Post</span>
              </div>
            </div>
            <div class="quick-post-box">
              <div class="qp-mode active" id="qp-mode-post">
                <textarea class="qp-textarea" id="qp-content-post" name="content" placeholder="What's on your mind?"></textarea>
                <div class="qp-footer">
                  <button type="button" class="qp-submit" id="qp-submit-post" data-type="post">Post</button>
                </div>
              </div>
              <div class="qp-mode" id="qp-mode-review">
                <input type="text" class="qp-title-input" id="qp-title-review" placeholder="Title" />
                <textarea class="qp-textarea" id="qp-content-review" placeholder="Write your review..."></textarea>
                <div class="qp-footer">
                  <button type="button" class="qp-submit" data-type="review">Publish Review</button>
                </div>
              </div>
              <div class="qp-mode" id="qp-mode-essay">
                <input type="text" class="qp-title-input" id="qp-title-essay" placeholder="Title" />
                <input type="text" class="qp-subtitle-input" id="qp-subtitle-essay" placeholder="Subtitle — optional" />
                <textarea class="qp-textarea" id="qp-content-essay" placeholder="Write your essay..."></textarea>
                <div class="qp-footer">
                  <button type="button" class="qp-submit" data-type="essay">Publish Essay</button>
                </div>
              </div>
            </div>
          </div>

          <!-- Post feed -->
          <div class="post-feed" id="post-feed">
            <?php foreach($posts_feed as $pf): ?>
            <?php $pf_date = date('M j', $pf['stamp']); ?>
            <div class="feed-card" data-post-id="<?php echo $pf['id']; ?>">
              <?php if($pf['uid'] == $qUser['id']): ?>
              <button class="feed-card-delete" data-post-id="<?php echo $pf['id']; ?>" title="Delete post">&times;</button>
              <?php endif; ?>
              <div class="feed-card-body">
                <div class="feed-card-content"><?php echo nl2br(htmlspecialchars($pf['content'])); ?></div>
              </div>
              <div class="feed-card-foot">
                <button class="feed-read-more">Read &rarr;</button>
                <span class="feed-card-meta"><?php echo htmlspecialchars($pf['author_name'] ?? 'Member'); ?> &middot; <?php echo $pf_date; ?></span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>

        </div><!-- /.home-left -->

        <!-- RIGHT COLUMN -->
        <div class="home-right">

          <?php if($featured_post): ?>
          <?php
            $fp_date = date('F j, Y', $featured_post['edited'] ?: $featured_post['stamp']);
            $fp_excerpt = mb_strlen($featured_post['content']) > 280
              ? mb_substr($featured_post['content'], 0, 280) . '...'
              : $featured_post['content'];
            $fp_slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(trim($featured_post['title'] ?? 'post')));
            $fp_slug = trim($fp_slug, '-');
            $fp_url  = '/posts/' . $fp_slug . '-' . $featured_post['id'];
          ?>
          <div class="featured-hero">
            <div class="featured-tag-row">
              <span class="featured-tag">Featured</span>
              <?php if($featured_post['type']): ?><span class="post-type-pill"><?php echo htmlspecialchars($featured_post['type']); ?></span><?php endif; ?>
            </div>

            <h1 class="post-headline"><?php echo htmlspecialchars($featured_post['title'] ?? ''); ?></h1>

            <?php if($featured_post['subtitle']): ?>
            <p class="post-sub"><?php echo htmlspecialchars($featured_post['subtitle']); ?></p>
            <?php endif; ?>

            <div class="post-meta">
              <?php if($featured_post['author_name']): ?>
                <span><?php echo htmlspecialchars($featured_post['author_name']); ?></span>
                <span class="post-meta-dot">&middot;</span>
              <?php endif; ?>
              <span><?php echo $fp_date; ?></span>
            </div>

            <?php if($featured_post['image']): ?>
            <div class="post-hero-wrap" style="margin-bottom:14px;">
              <img class="post-hero" src="/uploads/posts/<?php echo htmlspecialchars($featured_post['image']); ?>" alt="<?php echo htmlspecialchars($featured_post['title'] ?? ''); ?>" />
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
              <div class="featured-body"><?php echo nl2br(htmlspecialchars($featured_post['content'])); ?></div>
              <div class="featured-fade"></div>
            </div>

            <button class="featured-read-more" id="featured-read-btn">Read &rarr;</button>
          </div>
          <hr class="post-divider" />
          <?php endif; ?>

          <div class="post-panels-row">
          <?php foreach($posts_front as $pf): ?>
          <?php
            $pf_slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($pf['title'] ?? '')), '-');
            $pf_url  = '/posts/' . $pf_slug . '-' . $pf['id'];
          ?>
          <div class="post-panel" data-post-id="<?php echo $pf['id']; ?>" data-url="<?php echo htmlspecialchars($pf_url); ?>">
            <div class="post-panel-header">
              <div class="lower-bar">
                <span class="txt"><span class="pp-title-bg"></span><?php echo htmlspecialchars($pf['title']); ?></span>
                <?php if($pf['subtitle']): ?>
                <div class="pp-subtitle"><div class="pp-sub-bg"></div><?php echo htmlspecialchars($pf['subtitle']); ?></div>
                <?php endif; ?>
              </div>
              <div class="info-txt">
                <span class="pp-meta-bg<?php echo $pf['type'] ? ' pp-type-' . htmlspecialchars($pf['type']) : ''; ?>"></span><?php if($pf['type']): ?><?php echo htmlspecialchars($pf['type']); ?> &middot; <?php endif; ?><?php echo htmlspecialchars($pf['author_name'] ?? ''); ?>
              </div>
              <div class="bg-gradient" <?php if($pf['image']): ?>style="background-image:url('/uploads/posts/<?php echo htmlspecialchars($pf['image']); ?>');"<?php endif; ?>></div>
            </div>
            <div class="post-panel-body">
              <div class="post-panel-content"></div>
            </div>
          </div>
          <?php endforeach; ?>
          </div><!-- /.post-panels-row -->

        </div><!-- /.home-right -->

        <!-- Member overlay -->
        <div class="member-overlay" id="member-overlay">
          <div class="member-overlay-card" id="member-overlay-card">
            <button class="member-overlay-close" id="member-overlay-close">&#x2715;</button>
            <div class="member-overlay-content" id="member-overlay-content"></div>
          </div>
        </div>

      </div><!-- /.member-layout -->

      <?php
    }
    }
    }
    else {
      $error = $_GET['error'];
      if($error === "100") { // login issue
        echo "<div class='error'>Retry email or password.</div><br>";
      }
      else if($error === "102") { // sign up issue
        echo "<div class='error'>Registration error.</div><br>";
      }
      else if($error === "104") { // sign up issue
        echo "<div class='error'>Email already registered.</div><br>";
      }
    ?>
    <div class="guest-row">
    <div class="content-box blue" id="signup-panel">
      <div class="signup-header">
        <div class="lower-bar" style="text-align:right;">
          <span class="txt">Become a member</span>
        </div>
        <div class="info-txt"><?php echo $community_count; ?> members</div>
        <div class="bg-gradient"></div>
      </div>
      <div class="signup-body">
        <div id="signup-step-1">
          <div id="signup-error" class="error" style="display:none;"></div>
          <form id="signup-form-1">
            <input type="hidden" name="ajax" value="1" />
            <label>Email</label>
            <input class="input" type="text" name="email" placeholder="Email" />
            <label>Password</label>
            <input class="input" type="password" name="pw" placeholder="Password" />
            <label>Confirm Password</label>
            <input class="input" type="password" name="pw2" placeholder="Confirm password" />
            <label>Access Code</label>
            <input class="input" type="text" name="code" placeholder="Ask around." />
            <input type="submit" class="submit" value="Sign up" />
          </form>
        </div>
        <div id="signup-step-2" style="display:none;">
          <form action="/dashboard/signup.php?action=firstcontact" method="post">
            <input type="hidden" name="uid" id="signup-uid" value="" />
            <label>Name</label>
            <input class="input" type="text" name="uname" placeholder="Required" />
            <label>Role</label>
            <select class="input" name="dept" id="role-select">
              <option value="0">Select one</option>
              <?php foreach($roles as $role): ?>
              <option value="<?php echo $role; ?>"><?php echo $role; ?></option>
              <?php endforeach; ?>
            </select>
            <span class="role-desc" id="role-desc"></span>
            <label>Phone</label>
            <input class="input" type="tel" name="phone" placeholder="Optional" />
            <label>Website</label>
            <input class="input" type="text" name="website" placeholder="Optional" />
            <label>Letterboxd</label>
            <input class="input" type="text" name="lb" placeholder="Optional" />
            <input type="submit" class="submit" value="Continue" />
          </form>
        </div>
      </div>
    </div><div class="text">
      New movies <i>every night</i>.<br>
      Streamed simultaneously for everyone online.<br>
      Become a member to access <a href="/about">our directory</a>.
    </div>
    </div><!-- /.guest-row -->

    <?php
    }
    ?>
  </div>
  <!--div class="content-block-t">
    <div class="main-piece">
      <div class="main-img" style="background-image: url('img/');">
        <div class="overlay"></div>
      </div>
    </div>
  </div-->

  <div class="motw" id="motw-banner">
    <span class="stamp">The Theatre</span>
    <div class="banner" style="background-image:url('/motw/<?php echo $film_th1['poster']; ?>.png')">
      <span class="marquee-border"></span>
      <div class="overlay">
        <span class="txt">Showtimes</span>
        <?php
        for($i = 1;$i <= 1 ;++$i) {
          $showtime3 = $sql12->fetch();
          if($showtime3) {
            $showtime4 = date('D, h:ia', $showtime3['showtime']);
            echo '<span class="subtxt">'.$showtime4.'</span>';
          }
        }
        ?>
      </div>
    </div>
    <span class="title"><?php echo $film_th1['title']; ?></span>
    <span class="subtitle"><?php echo $film_th1['director']; ?></span>
    <?php if(!empty($note_th1['note'])): ?>
    <div class="motw-note"><?php echo $note_th1['note']; ?><?php if(!empty($film_th1['program'])): ?><span class="motw-programmer"> — <?php echo $film_th1['program']; ?></span><?php endif; ?></div>
    <?php endif; ?>

    <div class="motw-theatre" id="motw-theatre">
      <button class="motw-close" id="motw-close" title="Close theatre">&#x2715;</button>
      <div class="motw-theatre-inner">
        <div class="motw-video-hold" id="motw-home-hold">
          <video
            id="motw-home"
            class="video-js"
            oncontextmenu="return false;"
            controls
            preload
            poster="/motw/<?php echo $film_th1['poster']; ?>.png"
          ></video>
        </div>
        <div class="motw-theatre-info">
          <div class="topbar">
            <span class="title">
              <?php if(isset($film_th1['title'])): ?>
                <a href="<?php echo $film_th1['wiki']; ?>" target="_blank"><?php echo $film_th1['title']; ?></a><?php if($motw_time): ?> - <?php echo $motw_time; ?><?php endif; ?>
              <?php else: ?>Thanks for watching<?php endif; ?>
            </span>
            <div class="bottombar">
              <span class="directed"><?php echo isset($film_th1['director']) ? $film_th1['director'] : 'More movies every night.'; ?></span>
              <span class="dur"><?php echo gmdate('g\hi\m', $film_th1['dur'] ?? 0); ?></span>
            </div>
          </div>
          <div class="info-txt">
            <?php echo $note_th1['note']; ?>
            <?php if(!empty($film_th1['program'])): ?>
            <span class="motw-programmer">— <?php echo $film_th1['program']; ?></span>
            <?php endif; ?>
          </div>
          <div class="player-controls" data-player="motw-home">
            <button class="ctrl-btn ctrl-mute" title="Mute / Unmute">
              <i class="fa-solid fa-volume-high"></i>
            </button>
            <input class="ctrl-volume-slider" type="range" min="0" max="1" step="0.05" value="1" />
            <button class="ctrl-btn ctrl-fullscreen" title="Fullscreen">
              <i class="fa-solid fa-expand"></i>
            </button>
          </div>
          <div class="motw-th1-link"><a href="/th1">Open in Theatre 1 &rarr;</a></div>
        </div>
      </div>
    </div>
  </div>


</div>

</div>

</body>
</html>
