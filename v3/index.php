<?php
// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — v3 front page
//  Written from scratch. Reads the same tables and calls the same endpoints
//  as the live site; /index.php is untouched.
// ═══════════════════════════════════════════════════════════════════════════
error_reporting(0);
session_start();
require dirname(__DIR__) . '/database.php';
require dirname(__DIR__) . '/roles.php';
require dirname(__DIR__) . '/list/fetch_screenings.php';

$now    = time();
$signed = isset($_SESSION['username']);

// ── The theatre: what is on screen 1 now, or next ──────────────────────────
$playing = $conn->query("SELECT * FROM `showtimes` WHERE $now > `showtime` AND $now < `endtime` AND `theatre` = 1")->fetch();
$slot    = $playing;

if (!$slot) {
  $q = $conn->prepare("SELECT * FROM `showtimes` WHERE `showtime` > :now AND `theatre` = 1 ORDER BY `showtime` ASC LIMIT 1");
  $q->execute([':now' => $now]);
  $slot = $q->fetch();
}

$film = null;
if ($slot) {
  $q = $conn->prepare("SELECT * FROM `films` WHERE `id` = :id");
  $q->execute([':id' => (int)$slot['f_id']]);
  $film = $q->fetch();
}

$note = null;
if ($film) {
  $q = $conn->prepare("SELECT * FROM `notes` WHERE `f_id` = :id ORDER BY `stamp` DESC LIMIT 1");
  $q->execute([':id' => (int)$film['id']]);
  $note = $q->fetch();
}

$show_ts   = $slot ? (int)$slot['showtime'] : 0;
$is_live   = (bool)$playing;

// ── Tonight in Austin ──────────────────────────────────────────────────────
// Late in the evening most of the night has already started, so a strict
// "today" window leaves the bill nearly empty. Widen it until there is
// actually something worth reading, and say so in the label.
$day_end    = strtotime('tomorrow', $now) - 1;
$screenings = fetch_all_screenings($conn, $now, $day_end);
$bill_label = 'Tonight in Austin';

if (count($screenings) < 5) {
  $wider = fetch_all_screenings($conn, $now, $now + 172800);
  if (count($wider) > count($screenings)) {
    $screenings = $wider;
    $bill_label = $screenings ? 'Tonight & tomorrow' : $bill_label;
  }
}

$bill = array_slice($screenings, 0, 8);

$members = $conn->query("SELECT count(*) FROM `users` WHERE `active` = 1 AND `name` != ''")->fetchColumn();

// ── Writing ────────────────────────────────────────────────────────────────
$q = $conn->prepare(
  "SELECT p.*, u.name AS author_name FROM posts p LEFT JOIN users u ON p.uid = u.id
   WHERE p.active = 1 AND p.featured = 1 AND p.type IN ('review','essay')
   ORDER BY p.stamp DESC LIMIT 1");
$q->execute();
$lead = $q->fetch(PDO::FETCH_ASSOC);

$q = $conn->prepare(
  "SELECT p.id, p.uid, p.title, p.subtitle, p.type, p.image, p.stamp, p.edited, u.name AS author_name
   FROM posts p LEFT JOIN users u ON p.uid = u.id
   WHERE p.active = 1 AND p.type IN ('review','essay') " . ($lead ? "AND p.id != " . (int)$lead['id'] . " " : "") .
  "ORDER BY COALESCE(p.edited, p.stamp) DESC LIMIT 4");
$q->execute();
$pieces = $q->fetchAll(PDO::FETCH_ASSOC);

// ── The floor ──────────────────────────────────────────────────────────────
$me = null;
if ($signed) {
  $q = $conn->prepare("SELECT * FROM `users` WHERE `email` = :e");
  $q->execute([':e' => $_SESSION['username']]);
  $me = $q->fetch();
}

$q = $conn->prepare(
  "SELECT p.id, p.uid, p.content, p.stamp, u.name AS author_name, u.photo AS author_photo
   FROM posts p LEFT JOIN users u ON p.uid = u.id
   WHERE p.active = 1 AND p.type = 'post' ORDER BY p.stamp DESC LIMIT 6");
$q->execute();
$floor = $q->fetchAll(PDO::FETCH_ASSOC);

function post_url($id, $title) {
  $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($title ?? '')), '-');
  return '/posts/' . $slug . '-' . (int)$id;
}
$e = fn($s) => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Cinema, TX — <?php echo $e($bill_label); ?></title>

<link rel="stylesheet" href="/css/v3.css?v=<?php echo filemtime(dirname(__DIR__) . '/css/v3.css'); ?>" />
<link rel="stylesheet" href="https://vjs.zencdn.net/7.8.3/video-js.css" />
<link rel="icon" href="/img/iconimg.png" type="image/x-icon" />
<script src="https://kit.fontawesome.com/7ea7b5f42f.js" crossorigin="anonymous"></script>
<script src="https://vjs.zencdn.net/7.8.3/video.js"></script>
<script>
  window.CTX_SHOWTIME = <?php echo (int)$show_ts; ?>;
  window.CTX_DUR      = <?php echo (int)($film['dur'] ?? 0); ?>;
  window.CTX_FILE     = <?php echo json_encode($film['filename'] ?? ''); ?>;
</script>
<script src="/js/v3.js?v=<?php echo filemtime(dirname(__DIR__) . '/js/v3.js'); ?>" defer></script>
</head>
<body>

<!-- ══ MASTHEAD ═══════════════════════════════════════════════════════════ -->
<header class="masthead">
  <div class="masthead__meta">
    <span class="masthead__mark">Austin, Texas</span>
    <span class="rule-fill"></span>
    <span class="masthead__date"><?php echo date('D j M Y'); ?></span>
  </div>

  <h1 class="masthead__word">Cinema<span class="comma">,</span> TX</h1>

  <div class="masthead__strap">
    <span>One screen &middot; one schedule &middot; everybody at once</span>
    <span><?php echo (int)$members; ?> members &middot; <?php echo count($screenings); ?> screenings listed</span>
  </div>
</header>

<!-- ══ NAV ════════════════════════════════════════════════════════════════ -->
<nav class="nav" id="nav">
  <div class="nav__links">
    <a class="nav__link is-active" href="/v3/">Index</a>
    <a class="nav__link" href="/list">Listings</a>
    <a class="nav__link" href="/about">About</a>
    <?php if ($signed): ?>
    <a class="nav__link" href="/directory">Directory</a>
    <a class="nav__link" href="/dashboard">Dashboard</a>
    <?php endif; ?>
  </div>

  <span class="nav__spacer"></span>

  <div class="nav__actions">
    <?php if ($signed): ?>
      <div style="position:relative;display:flex;">
        <button class="nav__btn" id="recent-btn" title="Recent activity"><i class="fa-solid fa-clock-rotate-left"></i></button>
        <div class="nav__drop" id="recent-drop"><div class="drop__empty">Loading…</div></div>
      </div>
      <a class="nav__btn is-primary" href="/dashboard" title="Write"><i class="fa-solid fa-plus"></i></a>
      <form action="/dashboard/signup.php?action=logout" method="post" style="display:flex;">
        <button class="nav__btn" type="submit" title="Sign out"><i class="fa-solid fa-arrow-right-from-bracket"></i></button>
      </form>
    <?php else: ?>
      <a class="nav__link nav__link--cta" href="#join">Join</a>
    <?php endif; ?>
    <button class="nav__btn nav__toggle" id="nav-toggle" title="Menu"><i class="fa-solid fa-bars"></i></button>
  </div>
</nav>

<!-- ══ MARQUEE ════════════════════════════════════════════════════════════ -->
<?php if ($screenings): ?>
<div class="marquee">
  <div class="marquee__track" id="marquee-track">
    <?php for ($pass = 0; $pass < 2; $pass++): ?>
    <div class="marquee__group" <?php if ($pass): ?>aria-hidden="true"<?php endif; ?>>
      <?php foreach (array_slice($screenings, 0, 14) as $s): ?>
      <span class="marquee__item">
        <span class="t"><?php echo date('g:ia', $s['timestamp']); ?></span>
        <span class="f"><?php echo $e($s['title']); ?></span>
        <span class="v"><?php echo $e($s['venue']); ?></span>
        <span class="marquee__sep">✦</span>
      </span>
      <?php endforeach; ?>
    </div>
    <?php endfor; ?>
  </div>
</div>
<?php endif; ?>

<!-- ══ ROW 1 — the theatre + the bill ═════════════════════════════════════ -->
<div class="grid">

  <section class="mod mod--7">
    <div class="mod__head"><span class="idx">01</span><span>The Theatre</span><span class="fill"></span><span>Screen 1</span></div>

    <div class="now" id="theatre-open">
      <?php if ($film): ?>
      <div class="now__art" style="background-image:url('/motw/<?php echo $e($film['poster']); ?>.png')"></div>
      <?php endif; ?>
      <div class="now__scrim"></div>

      <div class="now__inner">
        <?php if ($is_live): ?>
        <span class="now__live"><span class="dot"></span> On screen now</span>
        <?php else: ?>
        <span class="now__kicker">Next screening<?php echo $show_ts ? ' — ' . date('D g:ia', $show_ts) : ''; ?></span>
        <?php endif; ?>

        <h2 class="now__title"><?php echo $film ? $e($film['title']) : 'Dark tonight'; ?></h2>

        <?php if ($film): ?>
        <div class="now__credits">
          <?php if (!empty($film['director'])): ?><span><?php echo $e($film['director']); ?></span><?php endif; ?>
          <?php if (!empty($film['dur'])): ?><span><?php echo gmdate('G\hi\m', (int)$film['dur']); ?></span><?php endif; ?>
          <?php if ($show_ts): ?><span><?php echo date('g:ia', $show_ts); ?></span><?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($note['note'])): ?>
        <p class="now__note">
          <?php echo $e($note['note']); ?>
          <?php if (!empty($film['program'])): ?><span class="prog">— <?php echo $e($film['program']); ?></span><?php endif; ?>
        </p>
        <?php endif; ?>

        <?php if ($film): ?>
        <span class="now__cta"><?php echo $is_live ? 'Enter the theatre →' : 'Preview →'; ?></span>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="mod mod--5">
    <div class="mod__head"><span class="idx">02</span><span><?php echo $e($bill_label); ?></span><span class="fill"></span><span><?php echo count($screenings); ?></span></div>

    <div class="bill">
      <?php if ($bill): foreach ($bill as $s): ?>
      <?php $href = !empty($s['url']) ? $s['url'] : '/list'; ?>
      <a class="bill__row" href="<?php echo $e($href); ?>"<?php echo empty($s['url']) ? '' : ' target="_blank" rel="noopener"'; ?>>
        <span class="bill__time"><?php echo date('g:ia', $s['timestamp']); ?></span>
        <span>
          <span class="bill__title"><?php echo $e($s['title']); ?></span>
          <span class="bill__venue"><?php echo $e($s['venue']); ?><?php echo date('j M', $s['timestamp']) !== date('j M', $now) ? ' · ' . date('D j M', $s['timestamp']) : ''; ?></span>
        </span>
      </a>
      <?php endforeach; else: ?>
      <p class="bill__empty">Nothing listed — check back.</p>
      <?php endif; ?>
    </div>

    <a class="bill__more" href="/list">All listings →</a>
  </section>

</div>

<!-- ══ THE PAPER ══════════════════════════════════════════════════════════ -->
<?php if ($lead): ?>
<div class="paper-wrap">
  <article class="paper">
    <span class="paper__stamp">Featured</span>
    <div class="paper__inner">

      <div class="paper__kicker">
        <span class="type"><?php echo $e($lead['type'] ?: 'Essay'); ?></span>
        <span class="fill"></span>
        <span><?php echo date('j F Y', $lead['edited'] ?: $lead['stamp']); ?></span>
      </div>

      <h2 class="paper__title"><?php echo $e($lead['title']); ?></h2>

      <?php if (!empty($lead['subtitle'])): ?>
      <p class="paper__sub"><?php echo $e($lead['subtitle']); ?></p>
      <?php endif; ?>

      <div class="paper__byline">
        <?php if (!empty($lead['author_name'])): ?>
        <a href="/users/profile.php?id=<?php echo (int)$lead['uid']; ?>"><?php echo $e($lead['author_name']); ?></a>
        <?php endif; ?>
        <span>Cinema, TX</span>
      </div>

      <?php if (!empty($lead['image'])): ?>
      <figure class="paper__figure">
        <img class="paper__img" src="/uploads/posts/<?php echo $e($lead['image']); ?>" alt="<?php echo $e($lead['title']); ?>" />
      </figure>
      <?php endif; ?>

      <div class="paper__read">
        <div class="paper__body" id="paper-body"><?php echo nl2br($e($lead['content'])); ?></div>
        <div class="paper__fade" id="paper-fade"></div>
      </div>

      <div class="paper__foot">
        <a class="paper__more" id="paper-more" href="<?php echo $e(post_url($lead['id'], $lead['title'])); ?>">Read in full →</a>
        <div class="paper__share">
          <a class="share" href="#" title="Share on X"><i class="fa-brands fa-x-twitter"></i></a>
          <a class="share" href="#" title="Share on Instagram"><i class="fa-brands fa-instagram"></i></a>
          <a class="share" href="#" title="Share on Reddit"><i class="fa-brands fa-reddit-alien"></i></a>
        </div>
      </div>

    </div>
  </article>
</div>
<?php endif; ?>

<!-- ══ ROW 2 — writing + the floor / join ═════════════════════════════════ -->
<div class="grid">

  <section class="mod mod--7">
    <div class="mod__head"><span class="idx">03</span><span>Writing</span><span class="fill"></span><a href="/list">Archive</a></div>
    <?php if ($pieces): foreach ($pieces as $p): ?>
    <a class="piece" href="<?php echo $e(post_url($p['id'], $p['title'])); ?>">
      <?php if (!empty($p['image'])): ?>
      <span class="piece__art" style="background-image:url('/uploads/posts/<?php echo $e($p['image']); ?>')"></span>
      <?php endif; ?>
      <span class="piece__in">
        <span class="piece__kicker">
          <span class="type<?php echo $p['type'] === 'review' ? ' type--review' : ''; ?>"><?php echo $e($p['type']); ?></span>
          <span><?php echo $e($p['author_name'] ?: 'Member'); ?></span>
          <span><?php echo date('j M', $p['edited'] ?: $p['stamp']); ?></span>
        </span>
        <h3 class="piece__title"><?php echo $e($p['title']); ?></h3>
        <?php if (!empty($p['subtitle'])): ?><p class="piece__sub"><?php echo $e($p['subtitle']); ?></p><?php endif; ?>
      </span>
    </a>
    <?php endforeach; else: ?>
    <p class="bill__empty">Nothing published yet.</p>
    <?php endif; ?>
    <a class="mod__tail" href="/list">The archive →</a>
  </section>

  <section class="mod mod--5" id="join">
    <?php if ($signed): ?>

      <div class="mod__head"><span class="idx">04</span><span>The Floor</span><span class="fill"></span><span><?php echo $e($me['name'] ?? ''); ?></span></div>

      <div class="composer">
        <textarea class="composer__area" id="composer-area" placeholder="What did you see?" maxlength="600"></textarea>
        <div class="composer__foot">
          <button class="btn" id="composer-send" disabled>Post</button>
          <span class="composer__count" id="composer-count">0 / 600</span>
        </div>
      </div>

      <div id="feed">
        <?php foreach ($floor as $f): ?>
        <div class="post" data-post-id="<?php echo (int)$f['id']; ?>">
          <?php if ($me && $f['uid'] == $me['id']): ?>
          <button class="post__del" data-post-id="<?php echo (int)$f['id']; ?>" title="Delete">&times;</button>
          <?php endif; ?>
          <a class="post__av" href="/users/profile.php?id=<?php echo (int)$f['uid']; ?>">
            <?php if (!empty($f['author_photo'])): ?>
            <img src="/uploads/profiles/<?php echo $e($f['author_photo']); ?>" alt="" />
            <?php else: ?><i class="fa-solid fa-user"></i><?php endif; ?>
          </a>
          <div class="post__main">
            <div class="post__body"><?php echo nl2br($e($f['content'])); ?></div>
            <div class="post__meta">
              <a href="/users/profile.php?id=<?php echo (int)$f['uid']; ?>"><?php echo $e($f['author_name'] ?: 'Member'); ?></a>
              <span><?php echo date('j M', $f['stamp']); ?></span>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

    <?php else: ?>

      <div class="mod__head"><span class="idx">04</span><span>Become a member</span><span class="fill"></span><span><?php echo (int)$members; ?></span></div>

      <p class="join__lede">A room of people who care what's playing — and who <em>write about it</em> afterward.</p>

      <?php
      $err = $_GET['error'] ?? null;
      $msg = ['100' => 'Retry your email or password.', '102' => 'Registration error.', '104' => 'That email is already registered.'][$err] ?? null;
      if ($msg): ?><div class="alert"><?php echo $e($msg); ?></div><?php endif; ?>

      <div id="join-step-1">
        <div class="alert" id="join-error" style="display:none;"></div>
        <form id="join-form">
          <input type="hidden" name="ajax" value="1" />
          <div class="field"><label class="field__label" for="j-email">Email</label>
            <input class="field__input" id="j-email" type="email" name="email" placeholder="you@example.com" /></div>
          <div class="field"><label class="field__label" for="j-pw">Password</label>
            <input class="field__input" id="j-pw" type="password" name="pw" placeholder="••••••••" /></div>
          <div class="field"><label class="field__label" for="j-pw2">Confirm</label>
            <input class="field__input" id="j-pw2" type="password" name="pw2" placeholder="••••••••" /></div>
          <div class="field"><label class="field__label" for="j-code">Access code</label>
            <input class="field__input" id="j-code" type="text" name="code" placeholder="Ask around." /></div>
          <div class="field__submit"><input class="btn" type="submit" value="Sign up" /></div>
        </form>
      </div>

      <div id="join-step-2" style="display:none;">
        <form action="/dashboard/signup.php?action=firstcontact" method="post">
          <input type="hidden" name="uid" id="join-uid" value="" />
          <div class="field"><label class="field__label" for="j-name">Name</label>
            <input class="field__input" id="j-name" type="text" name="uname" placeholder="Required" /></div>
          <div class="field"><label class="field__label" for="j-role">Role</label>
            <select class="field__input" id="j-role" name="dept">
              <option value="0">Select one</option>
              <?php foreach ($roles as $r): ?><option value="<?php echo $e($r); ?>"><?php echo $e($r); ?></option><?php endforeach; ?>
            </select></div>
          <div class="field"><label class="field__label" for="j-lb">Letterboxd</label>
            <input class="field__input" id="j-lb" type="text" name="lb" placeholder="Optional" /></div>
          <div class="field__submit"><input class="btn" type="submit" value="Continue" /></div>
        </form>
      </div>

      <div class="signin">
        <form action="/dashboard/signup.php?action=login" method="post" style="display:contents;">
          <div class="field"><label class="field__label" for="s-email">Already a member</label>
            <input class="field__input" id="s-email" type="email" name="email" placeholder="Email" /></div>
          <div class="field"><label class="field__label" for="s-pw">&nbsp;</label>
            <input class="field__input" id="s-pw" type="password" name="pw" placeholder="Password" /></div>
          <div class="field__submit"><button class="btn btn--ghost" type="submit">Sign in</button></div>
        </form>
      </div>

    <?php endif; ?>
  </section>

</div>

<!-- ══ COLOPHON ═══════════════════════════════════════════════════════════ -->
<footer class="colophon">
  <span>Cinema, TX</span>
  <span>Austin</span>
  <span class="fill"></span>
  <a href="#">Discord</a>
  <a href="#">Instagram</a>
  <span><?php echo date('Y'); ?></span>
</footer>

<!-- ══ THEATRE ════════════════════════════════════════════════════════════ -->
<div class="theatre" id="theatre">
  <div class="theatre__bar">
    <span class="theatre__name">
      <?php echo $film ? $e($film['title']) : 'Cinema, TX'; ?>
      <?php if ($show_ts): ?><span class="sep"> / </span><?php echo date('g:ia', $show_ts); ?><?php endif; ?>
    </span>
    <button class="theatre__close" id="theatre-close">Close ✕</button>
  </div>

  <div class="theatre__stage" id="theatre-stage">
    <video id="theatre-player" class="video-js" oncontextmenu="return false;" preload="none"
           poster="<?php echo $film ? '/motw/' . $e($film['poster']) . '.png' : ''; ?>"></video>
  </div>

  <div class="theatre__foot">
    <button class="ctrl" id="ctrl-mute" title="Mute"><i class="fa-solid fa-volume-high"></i></button>
    <input class="vol" id="ctrl-vol" type="range" min="0" max="1" step="0.05" value="1" />
    <button class="ctrl" id="ctrl-full" title="Fullscreen"><i class="fa-solid fa-expand"></i></button>
    <span class="theatre__meta">
      <?php echo $film ? $e($film['director']) : ''; ?>
      <?php if ($film && !empty($film['dur'])): ?> · <?php echo gmdate('G\hi\m', (int)$film['dur']); ?><?php endif; ?>
      · Synced to showtime
    </span>
  </div>
</div>

</body>
</html>
