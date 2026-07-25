<?php
// ═══════════════════════════════════════════════════════════════════════════
//  v2 REFERENCE PAGE — homepage, both states.
//  Parallel to /index.php; the live page is untouched. Data queries are
//  copied verbatim from index.php so this renders real content. When the
//  redesign lands for real, that block moves to a shared include rather than
//  living in two places.
// ═══════════════════════════════════════════════════════════════════════════
error_reporting(0);
session_start();
require dirname(__DIR__) . '/database.php';
require dirname(__DIR__) . '/roles.php';

// ── Theatre / showtime data ────────────────────────────────────────────────
$now      = time();
$tomorrow = $now + 172800;

$sql12 = null; $film_th1 = null; $note_th1 = null; $showtime5 = null; $showtime9 = null;

$playing1      = $conn->query("SELECT * FROM `showtimes` WHERE $now > `showtime` AND $now < `endtime` AND `theatre` = 1")->fetch();
$playing_next1 = $conn->query("SELECT * FROM `showtimes` WHERE $now < `showtime` AND `theatre` = 1 LIMIT 1")->fetch();

if (!$playing1) {
  if ($playing_next1) {
    $sql9 = $conn->prepare("SELECT * FROM `showtimes` WHERE $now < `showtime` AND `theatre` = 1 ORDER BY `showtime` ASC LIMIT 1");
    $sql9->execute();
    $showtime9 = $sql9->fetch();
    $sql6 = $conn->prepare("SELECT * FROM `films` WHERE `id` = " . (int)$showtime9['f_id']);
    $sql6->execute();
    $film_th1 = $sql6->fetch();
  }
} else {
  $sql5 = $conn->prepare("SELECT * FROM `showtimes` WHERE $now > `showtime` AND $now < `endtime` AND `theatre` = 1");
  $sql5->execute();
  $showtime5 = $sql5->fetch();
  $sql6 = $conn->prepare("SELECT * FROM `films` WHERE `id` = " . (int)$showtime5['f_id']);
  $sql6->execute();
  $film_th1 = $sql6->fetch();
}

$sql12_film = $film_th1['id'] ?? 0;

$sql12 = $conn->prepare("SELECT * FROM `showtimes` WHERE `endtime` > :now AND `theatre` = 1 AND `f_id` = :film_id ORDER BY `showtime` ASC");
$sql12->bindValue(':now', $now, PDO::PARAM_STR);
$sql12->bindValue(':film_id', $sql12_film, PDO::PARAM_INT);
$sql12->execute();

$note_q = $conn->prepare("SELECT * FROM `notes` WHERE `f_id` = :film_id ORDER BY `stamp` DESC LIMIT 1");
$note_q->bindValue(':film_id', $sql12_film, PDO::PARAM_INT);
$note_q->execute();
$note_th1 = $note_q->fetch();

$community_count = $conn->query("SELECT count(*) FROM `users` WHERE `active` = 1 AND `name` != ''")->fetchColumn();

// resolved showtime for the theatre embed
$motw_showtime_ts = 0;
if ($playing1 && isset($showtime5['showtime']))        $motw_showtime_ts = $showtime5['showtime'];
elseif (!$playing1 && isset($showtime9['showtime']))   $motw_showtime_ts = $showtime9['showtime'];
$motw_time = $motw_showtime_ts ? date('g:ia', $motw_showtime_ts) : '';

// ── Member data ────────────────────────────────────────────────────────────
if (isset($_SESSION['username'])) {
  $user = $_SESSION['username'];
  $sql11 = $conn->prepare("SELECT * FROM `users` WHERE `email` = :email");
  $sql11->execute([':email' => $user]);
  $qUser = $sql11->fetch();

  $featured_post_q = $conn->prepare(
    "SELECT p.*, u.name AS author_name FROM posts p LEFT JOIN users u ON p.uid = u.id
     WHERE p.active = 1 AND p.featured = 1 AND p.type IN ('review','essay') ORDER BY p.stamp DESC LIMIT 1");
  $featured_post_q->execute();
  $featured_post = $featured_post_q->fetch(PDO::FETCH_ASSOC);

  $posts_front_q = $conn->prepare(
    "SELECT p.id, p.uid, p.title, p.subtitle, p.type, p.image, p.stamp, p.edited, u.name AS author_name
     FROM posts p LEFT JOIN users u ON p.uid = u.id
     WHERE p.active = 1 AND p.type IN ('review','essay')
     ORDER BY COALESCE(p.edited, p.stamp) DESC LIMIT 4");
  $posts_front_q->execute();
  $posts_front = $posts_front_q->fetchAll(PDO::FETCH_ASSOC);

  $posts_feed_q = $conn->prepare(
    "SELECT p.id, p.uid, p.content, p.type, p.stamp, u.name AS author_name, u.photo AS author_photo
     FROM posts p LEFT JOIN users u ON p.uid = u.id
     WHERE p.active = 1 AND p.type = 'post' ORDER BY p.stamp DESC LIMIT 4");
  $posts_feed_q->execute();
  $posts_feed = $posts_feed_q->fetchAll(PDO::FETCH_ASSOC);
}
// ── Guest data ─────────────────────────────────────────────────────────────
else {
  require dirname(__DIR__) . '/list/fetch_screenings.php';
  $today_end        = strtotime('tomorrow', $now) - 1;
  $today_screenings = array_slice(fetch_all_screenings($conn, $now, $today_end), 0, 6);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Cinema, TX</title>

  <link rel="stylesheet" href="/css/redesign.css?v=<?php echo filemtime(dirname(__DIR__) . '/css/redesign.css'); ?>" />
  <link href="https://vjs.zencdn.net/7.8.3/video-js.css" rel="stylesheet" />
  <link rel="icon" href="/img/iconimg.png" type="image/x-icon" />

  <script src="https://kit.fontawesome.com/7ea7b5f42f.js" crossorigin="anonymous"></script>
  <script src="https://code.jquery.com/jquery-3.2.1.min.js"
          integrity="sha256-hwg4gsxgFZhOsEEamdOYGBf13FyQuiTwlAQgxVSNgt4=" crossorigin="anonymous"></script>
  <script src="https://vjs.zencdn.net/7.8.3/video.js"></script>
  <script>
    var motwShowtime = <?php echo (int)$motw_showtime_ts; ?>;
    var motwDur      = <?php echo (int)($film_th1['dur'] ?? 0); ?>;
    var motwFilename = <?php echo json_encode($film_th1['filename'] ?? ''); ?>;
  </script>
  <script src="/js/script-jlm.js?v=<?php echo filemtime(dirname(__DIR__) . '/js/script-jlm.js'); ?>"></script>
  <script src="/js/redesign.js?v=<?php echo filemtime(dirname(__DIR__) . '/js/redesign.js'); ?>"></script>
</head>
<body id="index">

<div class="main-content">

<?php require __DIR__ . '/header.php'; ?>

<div class="home-base">

<?php if (isset($_SESSION['username'])): ?>
<!-- ══════════════════════════════════════════════════════════ MEMBER HOME ── -->

  <div class="member-layout">

    <!-- ── Reading column ─────────────────────────────────────────────── -->
    <div class="home-main">

      <?php if ($featured_post):
        $fp_date = date('F j, Y', $featured_post['edited'] ?: $featured_post['stamp']);
      ?>
      <div class="featured-hero">
        <div class="featured-tag-row">
          <span class="featured-tag">Featured</span>
          <?php if ($featured_post['type']): ?>
          <span class="post-type-pill"><?php echo htmlspecialchars($featured_post['type']); ?></span>
          <?php endif; ?>
        </div>

        <h1 class="post-headline"><?php echo htmlspecialchars($featured_post['title'] ?? ''); ?></h1>

        <?php if ($featured_post['subtitle']): ?>
        <p class="post-sub"><?php echo htmlspecialchars($featured_post['subtitle']); ?></p>
        <?php endif; ?>

        <div class="post-meta">
          <?php if ($featured_post['author_name']): ?>
          <a class="author-link" href="/users/profile.php?id=<?php echo $featured_post['uid']; ?>"><?php echo htmlspecialchars($featured_post['author_name']); ?></a>
          <span class="post-meta-dot">&middot;</span>
          <?php endif; ?>
          <span><?php echo $fp_date; ?></span>
        </div>

        <?php if ($featured_post['image']): ?>
        <div class="post-hero-wrap">
          <img class="post-hero" src="/uploads/posts/<?php echo htmlspecialchars($featured_post['image']); ?>"
               alt="<?php echo htmlspecialchars($featured_post['title'] ?? ''); ?>" />
          <div class="post-share">
            <a class="share-btn share-twitter"   href="#" title="Share on X"><span class="share-label">Share</span> <i class="fa-brands fa-x-twitter"></i></a>
            <a class="share-btn share-instagram" href="#" title="Share on Instagram"><span class="share-label">Share</span> <i class="fa-brands fa-instagram"></i></a>
            <a class="share-btn share-reddit"    href="#" title="Share on Reddit"><span class="share-label">Share</span> <i class="fa-brands fa-reddit-alien"></i></a>
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

      <div class="home-section-label">Recent writing</div>

      <div class="post-panels-row">
        <?php foreach ($posts_front as $pf): ?>
        <?php
          $pf_slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($pf['title'] ?? '')), '-');
          $pf_url  = '/posts/' . $pf_slug . '-' . $pf['id'];
        ?>
        <div class="post-panel" data-post-id="<?php echo $pf['id']; ?>" data-url="<?php echo htmlspecialchars($pf_url); ?>">
          <div class="post-panel-header">
            <div class="bg-gradient" <?php if ($pf['image']): ?>style="background-image:url('/uploads/posts/<?php echo htmlspecialchars($pf['image']); ?>');"<?php endif; ?>></div>
            <div class="info-txt">
              <?php if ($pf['type']): ?><?php echo htmlspecialchars($pf['type']); ?> &middot; <?php endif; ?>
              <?php if ($pf['author_name']): ?><a class="author-link" href="/users/profile.php?id=<?php echo $pf['uid']; ?>"><?php echo htmlspecialchars($pf['author_name']); ?></a><?php endif; ?>
            </div>
            <div class="lower-bar">
              <span class="txt"><?php echo htmlspecialchars($pf['title']); ?></span>
              <?php if ($pf['subtitle']): ?>
              <div class="pp-subtitle"><?php echo htmlspecialchars($pf['subtitle']); ?></div>
              <?php endif; ?>
            </div>
          </div>
          <div class="post-panel-body">
            <div class="post-panel-content"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

    </div><!-- /.home-main -->

    <!-- ── Social column ──────────────────────────────────────────────── -->
    <div class="home-side">

      <div class="quick-post-wrap">
        <div class="qp-selector" id="qp-selector">
          <div class="qp-selector-current">
            <span id="qp-current-label">Set Status</span>
          </div>
        </div>
        <span class="qp-status-reveal">Only one status is active at once</span>

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

      <div class="home-section-label">From the floor</div>

      <div class="post-feed" id="post-feed">
        <?php foreach ($posts_feed as $pf): ?>
        <div class="feed-card" data-post-id="<?php echo $pf['id']; ?>">
          <?php if ($pf['uid'] == $qUser['id']): ?>
          <button class="feed-card-delete" data-post-id="<?php echo $pf['id']; ?>" title="Delete post">&times;</button>
          <?php endif; ?>
          <a class="feed-card-avatar" href="/users/profile.php?id=<?php echo $pf['uid']; ?>">
            <?php if (!empty($pf['author_photo'])): ?>
            <img src="/uploads/profiles/<?php echo htmlspecialchars($pf['author_photo']); ?>" alt="" />
            <?php else: ?>
            <i class="fa-solid fa-user"></i>
            <?php endif; ?>
          </a>
          <div class="feed-card-main">
            <div class="feed-card-body">
              <div class="feed-card-content"><?php echo nl2br(htmlspecialchars($pf['content'])); ?></div>
            </div>
            <div class="feed-card-foot">
              <button class="feed-read-more">Read &rarr;</button>
              <span class="feed-card-meta">
                <a class="author-link" href="/users/profile.php?id=<?php echo $pf['uid']; ?>"><?php echo htmlspecialchars($pf['author_name'] ?? 'Member'); ?></a>
                &middot; <?php echo date('M j', $pf['stamp']); ?>
              </span>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

    </div><!-- /.home-side -->

  </div><!-- /.member-layout -->

<?php else: ?>
<!-- ══════════════════════════════════════════════════════════ LANDING ────── -->

  <div class="landing">

    <?php $error = $_GET['error'] ?? null; ?>
    <?php if ($error === "100"): ?><div class="error">Retry email or password.</div>
    <?php elseif ($error === "102"): ?><div class="error">Registration error.</div>
    <?php elseif ($error === "104"): ?><div class="error">Email already registered.</div>
    <?php endif; ?>

    <div class="landing-intro">
      <div class="landing-eyebrow">Austin, Texas</div>
      <h1 class="landing-headline">A cinema that keeps <em>its own hours</em>.</h1>
      <p class="landing-lede">
        One screen, one schedule, everybody watching together. Between showtimes,
        a room full of people who care about what's playing in town — and who write
        about it afterward.
      </p>
    </div>

    <div class="landing-cols">

      <div class="content-box blue" id="signup-panel">
        <div class="signup-header">
          <div class="bg-gradient"></div>
          <div class="info-txt"><?php echo $community_count; ?> members</div>
          <div class="lower-bar"><span class="txt">Become a member</span></div>
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
                <?php foreach ($roles as $role): ?>
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
      </div>

      <div class="landing-screenings-panel" id="landing-screenings-panel">
        <div class="landing-panel-header">
          <div class="bg-gradient"></div>
          <div class="info-txt"><?php echo count($today_screenings); ?> screenings</div>
          <div class="lower-bar"><span class="txt">Playing today</span></div>
        </div>

        <div class="landing-panel-body">
          <?php if ($today_screenings): ?>
          <div class="landing-view-tabs">
            <button type="button" class="landing-view-tab active" data-view="posters">Posters</button>
            <button type="button" class="landing-view-tab" data-view="list">List</button>
          </div>

          <div class="landing-view-posters active">
            <?php foreach ($today_screenings as $film): ?>
            <?php $film_time = date('g:ia', $film['timestamp']); ?>
            <?php if (!empty($film['url'])): ?>
            <a class="landing-poster" href="<?php echo htmlspecialchars($film['url']); ?>" target="_blank" rel="noopener">
            <?php else: ?>
            <div class="landing-poster">
            <?php endif; ?>
              <?php if (!empty($film['poster'])): ?>
              <img src="<?php echo htmlspecialchars($film['poster']); ?>" alt="<?php echo htmlspecialchars($film['title']); ?>" />
              <?php else: ?>
              <span class="landing-poster-title"><?php echo htmlspecialchars($film['title']); ?></span>
              <?php endif; ?>
              <div class="landing-poster-date"><?php echo htmlspecialchars($film_time); ?></div>
            <?php if (!empty($film['url'])): ?>
            </a>
            <?php else: ?>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
          </div>

          <div class="landing-view-list">
            <?php foreach ($today_screenings as $film): ?>
            <div class="landing-list-row">
              <span class="landing-list-time"><?php echo htmlspecialchars(date('g:ia', $film['timestamp'])); ?></span>
              <span class="landing-list-title"><?php echo htmlspecialchars($film['title']); ?></span>
              <span class="landing-list-venue"><?php echo htmlspecialchars($film['venue']); ?></span>
            </div>
            <?php endforeach; ?>
          </div>

          <a class="landing-panel-more" href="/list">See full list &rarr;</a>
          <?php else: ?>
          <p class="landing-panel-empty">No screenings today — check back soon.</p>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /.landing-cols -->
  </div><!-- /.landing -->

<?php endif; ?>

  <!-- ══════════════════════════════════════════════════════ THEATRE RAIL ── -->
  <div class="motw" id="motw-banner">
    <span class="stamp">The Theatre</span>

    <div class="banner" style="background-image:url('/motw/<?php echo $film_th1['poster'] ?? ''; ?>.png')">
      <div class="overlay">
        <span class="txt">Showtimes</span>
        <?php
        $showtime3 = $sql12->fetch();
        if ($showtime3) echo '<span class="subtxt">' . date('D, h:ia', $showtime3['showtime']) . '</span>';
        ?>
      </div>
    </div>

    <span class="title"><?php echo $film_th1['title'] ?? ''; ?></span>
    <span class="subtitle"><?php echo $film_th1['director'] ?? ''; ?></span>

    <?php if (!empty($note_th1['note'])): ?>
    <div class="motw-note">
      <?php echo $note_th1['note']; ?>
      <?php if (!empty($film_th1['program'])): ?><span class="motw-programmer"> — <?php echo $film_th1['program']; ?></span><?php endif; ?>
    </div>
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
                <a href="<?php echo $film_th1['wiki']; ?>" target="_blank"><?php echo $film_th1['title']; ?></a><?php if ($motw_time): ?> — <?php echo $motw_time; ?><?php endif; ?>
              <?php else: ?>Thanks for watching<?php endif; ?>
            </span>
            <div class="bottombar">
              <span class="directed"><?php echo $film_th1['director'] ?? 'More movies every night.'; ?></span>
              <span class="dur"><?php echo gmdate('g\hi\m', $film_th1['dur'] ?? 0); ?></span>
            </div>
          </div>

          <div class="info-txt">
            <?php echo $note_th1['note'] ?? ''; ?>
            <?php if (!empty($film_th1['program'])): ?><span class="motw-programmer">— <?php echo $film_th1['program']; ?></span><?php endif; ?>
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
