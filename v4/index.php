<?php
// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — v4 front page
//
//  A film journal that is also a room of people. The front page carries a
//  taste of the whole site: the cinema, the list, the journal, the floor,
//  and the directory. Utility (sign in, join) lives in the chrome, never in
//  the page. /index.php is untouched.
// ═══════════════════════════════════════════════════════════════════════════
error_reporting(0);
session_start();
require dirname(__DIR__) . '/database.php';
require dirname(__DIR__) . '/roles.php';
require dirname(__DIR__) . '/list/fetch_screenings.php';

$now    = time();
$signed = isset($_SESSION['username']);
$e      = fn($s) => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

function post_url($id, $title) {
  $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($title ?? '')), '-');
  return '/posts/' . $slug . '-' . (int)$id;
}

// ── The cinema ─────────────────────────────────────────────────────────────
$playing = $conn->query("SELECT * FROM `showtimes` WHERE $now > `showtime` AND $now < `endtime` AND `theatre` = 1")->fetch();
$slot    = $playing;
if (!$slot) {
  $q = $conn->prepare("SELECT * FROM `showtimes` WHERE `showtime` > :n AND `theatre` = 1 ORDER BY `showtime` ASC LIMIT 1");
  $q->execute([':n' => $now]);
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

$show_ts = $slot ? (int)$slot['showtime'] : 0;
$is_live = (bool)$playing;

// ── The list ───────────────────────────────────────────────────────────────
// Widen the window when the night is nearly over, so the page never shows a
// near-empty bill just because it is late.
$screenings = fetch_all_screenings($conn, $now, strtotime('tomorrow', $now) - 1);
$list_label = 'Tonight';
if (count($screenings) < 5) {
  $wider = fetch_all_screenings($conn, $now, $now + 172800);
  if (count($wider) > count($screenings)) { $screenings = $wider; $list_label = 'Tonight & tomorrow'; }
}
$bill = array_slice($screenings, 0, 7);

// ── The journal ────────────────────────────────────────────────────────────
$q = $conn->prepare(
  "SELECT p.*, u.name AS author_name FROM posts p LEFT JOIN users u ON p.uid = u.id
   WHERE p.active = 1 AND p.featured = 1 AND p.type IN ('review','essay') ORDER BY p.stamp DESC LIMIT 1");
$q->execute();
$lead = $q->fetch(PDO::FETCH_ASSOC);

$q = $conn->prepare(
  "SELECT p.id, p.uid, p.title, p.subtitle, p.type, p.image, p.stamp, p.edited, u.name AS author_name
   FROM posts p LEFT JOIN users u ON p.uid = u.id
   WHERE p.active = 1 AND p.type IN ('review','essay') " . ($lead ? "AND p.id != " . (int)$lead['id'] . " " : "") .
  "ORDER BY COALESCE(p.edited, p.stamp) DESC LIMIT 4");
$q->execute();
$pieces = $q->fetchAll(PDO::FETCH_ASSOC);

// ── The room ───────────────────────────────────────────────────────────────
$me = null;
if ($signed) {
  $q = $conn->prepare("SELECT * FROM `users` WHERE `email` = :e");
  $q->execute([':e' => $_SESSION['username']]);
  $me = $q->fetch();
}

$q = $conn->prepare(
  "SELECT p.id, p.uid, p.content, p.stamp, u.name AS author_name, u.photo AS author_photo
   FROM posts p LEFT JOIN users u ON p.uid = u.id
   WHERE p.active = 1 AND p.type = 'post' ORDER BY p.stamp DESC LIMIT 5");
$q->execute();
$statuses = $q->fetchAll(PDO::FETCH_ASSOC);

// ── The directory ──────────────────────────────────────────────────────────
$q = $conn->prepare("SELECT id, name, photo FROM `users` WHERE `active` = 1 AND `name` != '' ORDER BY `id` DESC LIMIT 10");
$q->execute();
$members  = $q->fetchAll(PDO::FETCH_ASSOC);
$m_count  = $conn->query("SELECT count(*) FROM `users` WHERE `active` = 1 AND `name` != ''")->fetchColumn();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Cinema, TX</title>

<script>
  // Applied before first paint so the chosen theme never flashes.
  try { var t = localStorage.getItem('ctx-theme'); if (t) document.documentElement.setAttribute('data-theme', t); } catch (e) {}
</script>

<link rel="stylesheet" href="/css/v4.css?v=<?php echo filemtime(dirname(__DIR__) . '/css/v4.css'); ?>" />
<link rel="stylesheet" href="https://vjs.zencdn.net/7.8.3/video-js.css" />
<link rel="icon" href="/img/iconimg.png" type="image/x-icon" />
<script src="https://kit.fontawesome.com/7ea7b5f42f.js" crossorigin="anonymous"></script>
<script src="https://vjs.zencdn.net/7.8.3/video.js"></script>
<script>
  window.CTX_SHOWTIME = <?php echo (int)$show_ts; ?>;
  window.CTX_DUR      = <?php echo (int)($film['dur'] ?? 0); ?>;
  window.CTX_FILE     = <?php echo json_encode($film['filename'] ?? ''); ?>;
</script>
<script src="/js/v4.js?v=<?php echo filemtime(dirname(__DIR__) . '/js/v4.js'); ?>" defer></script>
</head>
<body>

<!-- ══ BAR — utility only ═════════════════════════════════════════════════ -->
<header class="bar" id="bar">
  <div class="sheet bar__in">
    <a class="bar__mark" href="/v4/">Cinema<span class="c">,</span> TX</a>

    <nav class="bar__nav">
      <a class="bar__link is-on" href="/v4/">Index</a>
      <a class="bar__link" href="/list">The List</a>
      <a class="bar__link" href="/about">About</a>
      <?php if ($signed): ?>
      <a class="bar__link" href="/directory">Directory</a>
      <a class="bar__link" href="/dashboard">Dashboard</a>
      <?php endif; ?>
    </nav>

    <div class="bar__end">
      <button class="icon-btn" id="theme-btn" title="Dark mode"><i class="fa-solid fa-moon"></i></button>
      <?php if ($signed): ?>
        <a class="icon-btn" href="/dashboard" title="Write"><i class="fa-solid fa-pen"></i></a>
        <form action="/dashboard/signup.php?action=logout" method="post" style="display:flex;">
          <button class="icon-btn" type="submit" title="Sign out"><i class="fa-solid fa-arrow-right-from-bracket"></i></button>
        </form>
      <?php else: ?>
        <button class="btn" data-panel-open>Sign in</button>
      <?php endif; ?>
      <button class="icon-btn bar__toggle" id="bar-toggle" title="Menu"><i class="fa-solid fa-bars"></i></button>
    </div>
  </div>
</header>

<main class="sheet">

  <!-- ══ NAMEPLATE ════════════════════════════════════════════════════════ -->
  <div class="nameplate">
    <h1 class="nameplate__word">Cinema<span class="c">,</span> TX</h1>
    <div class="nameplate__line">
      <span>Austin, Texas</span>
      <span class="fill"></span>
      <span><?php echo date('l j F Y'); ?></span>
    </div>
  </div>

  <nav class="contents">
    <a class="contents__item" href="#cinema"><span class="n">01</span> The Cinema</a>
    <a class="contents__item" href="#list"><span class="n">02</span> <?php echo $e($list_label); ?></a>
    <a class="contents__item" href="#journal"><span class="n">03</span> The Journal</a>
    <a class="contents__item" href="#room"><span class="n">04</span> The Room</a>
    <a class="contents__item" href="/directory"><span class="n">05</span> Members</a>
  </nav>

  <!-- ══ 01 THE CINEMA — full sheet, always dark ══════════════════════════ -->
  <section class="cinema" id="cinema">
    <div class="cinema__poster" <?php if ($film): ?>style="background-image:url('/motw/<?php echo $e($film['poster']); ?>.png')"<?php endif; ?>></div>

    <div class="cinema__body">
      <?php if ($is_live): ?>
        <span class="cinema__kicker"><span class="dot"></span> On screen now</span>
      <?php else: ?>
        <span class="cinema__kicker">Next screening<?php echo $show_ts ? ' — ' . date('D j M, g:ia', $show_ts) : ''; ?></span>
      <?php endif; ?>

      <h2 class="cinema__title"><?php echo $film ? $e($film['title']) : 'Dark tonight'; ?></h2>

      <?php if ($film): ?>
      <div class="cinema__credits">
        <?php if (!empty($film['director'])): ?><span><?php echo $e($film['director']); ?></span><?php endif; ?>
        <?php if (!empty($film['dur'])): ?><span><?php echo gmdate('G\hi\m', (int)$film['dur']); ?></span><?php endif; ?>
        <span>Everyone watches at once</span>
      </div>
      <?php endif; ?>

      <?php if (!empty($note['note'])): ?>
      <p class="cinema__note">
        <?php echo $e($note['note']); ?>
        <?php if (!empty($film['program'])): ?><span class="prog">— <?php echo $e($film['program']); ?></span><?php endif; ?>
      </p>
      <?php endif; ?>

      <?php if ($film): ?>
      <span class="cinema__cta"><?php echo $is_live ? 'Enter the theatre' : 'Look inside'; ?></span>
      <?php endif; ?>
    </div>
  </section>

  <!-- ══ THE SPREAD ═══════════════════════════════════════════════════════ -->
  <div class="spread">

    <!-- ── Publication ──────────────────────────────────────────────────── -->
    <div class="publication">

      <!-- 02 THE LIST -->
      <section class="section" id="list">
        <div class="section__head">
          <span class="section__n">02</span>
          <h2 class="section__title"><?php echo $e($list_label); ?> in Austin</h2>
          <a class="section__aside" href="/list">All <?php echo count($screenings); ?> &rarr;</a>
        </div>

        <div class="list">
          <?php if ($bill): foreach ($bill as $s): ?>
          <?php
            $is_member = ($s['source'] ?? '') === 'user';
            $href      = !empty($s['url']) ? $s['url'] : '/list';
            $other_day = date('j M', $s['timestamp']) !== date('j M', $now);
          ?>
          <a class="entry<?php echo $is_member ? ' entry--member' : ''; ?>" href="<?php echo $e($href); ?>"<?php echo $is_member ? '' : ' target="_blank" rel="noopener"'; ?>>
            <span class="entry__time"><?php echo date('g:ia', $s['timestamp']); ?></span>
            <span>
              <span class="entry__title"><?php echo $e($s['title']); ?></span>
              <span class="entry__where">
                <?php if ($is_member): ?>
                  <span class="entry__by">&#9679; Put on by a member</span> &middot;
                <?php endif; ?>
                <?php echo $e($s['venue']); ?>
              </span>
            </span>
            <span class="entry__day"><?php echo $other_day ? date('D', $s['timestamp']) : 'Today'; ?></span>
          </a>
          <?php endforeach; else: ?>
          <p class="prose">Nothing listed just now.</p>
          <?php endif; ?>
        </div>
      </section>

      <!-- 03 THE JOURNAL -->
      <section class="section" id="journal">
        <div class="section__head">
          <span class="section__n">03</span>
          <h2 class="section__title">The Journal</h2>
          <a class="section__aside" href="/list">Archive &rarr;</a>
        </div>

        <?php if ($lead): ?>
        <article class="lead">
          <div class="lead__kicker">
            <span><?php echo $e($lead['type'] ?: 'Essay'); ?></span>
            <span>&middot;</span>
            <span><?php echo date('j F Y', $lead['edited'] ?: $lead['stamp']); ?></span>
          </div>

          <h3 class="lead__title"><?php echo $e($lead['title']); ?></h3>

          <?php if (!empty($lead['subtitle'])): ?>
          <p class="lead__sub"><?php echo $e($lead['subtitle']); ?></p>
          <?php endif; ?>

          <div class="byline">
            <?php if (!empty($lead['author_name'])): ?>
            <a href="/users/profile.php?id=<?php echo (int)$lead['uid']; ?>"><?php echo $e($lead['author_name']); ?></a>
            <?php endif; ?>
          </div>

          <?php if (!empty($lead['image'])): ?>
          <figure class="lead__figure">
            <img class="lead__img" src="/uploads/posts/<?php echo $e($lead['image']); ?>" alt="<?php echo $e($lead['title']); ?>" />
          </figure>
          <?php endif; ?>

          <div class="lead__read">
            <div class="lead__body prose" id="lead-body"><?php echo nl2br($e($lead['content'])); ?></div>
            <div class="lead__fade" id="lead-fade"></div>
          </div>

          <p style="margin-top:var(--s-5);">
            <a class="btn btn--quiet" id="lead-more" href="<?php echo $e(post_url($lead['id'], $lead['title'])); ?>">Read in full</a>
          </p>
        </article>
        <?php endif; ?>

        <?php foreach ($pieces as $p): ?>
        <a class="piece" href="<?php echo $e(post_url($p['id'], $p['title'])); ?>">
          <span>
            <span class="piece__kicker">
              <span class="type"><?php echo $e($p['type']); ?></span>
              <span><?php echo $e($p['author_name'] ?: 'Member'); ?></span>
              <span><?php echo date('j M', $p['edited'] ?: $p['stamp']); ?></span>
            </span>
            <h3 class="piece__title"><?php echo $e($p['title']); ?></h3>
            <?php if (!empty($p['subtitle'])): ?><p class="piece__sub"><?php echo $e($p['subtitle']); ?></p><?php endif; ?>
          </span>
          <span class="piece__thumb" <?php if (!empty($p['image'])): ?>style="background-image:url('/uploads/posts/<?php echo $e($p['image']); ?>')"<?php endif; ?>></span>
        </a>
        <?php endforeach; ?>
      </section>

    </div><!-- /.publication -->

    <!-- ── The room ─────────────────────────────────────────────────────── -->
    <aside class="rail" id="room">
      <div class="room">

        <div class="room__head">
          <span class="stamp">04 &mdash; The Room</span>
          <span class="room__count"><?php echo (int)$m_count; ?> members</span>
        </div>

        <?php if ($signed): ?>
        <div class="composer">
          <textarea class="composer__area" id="composer-area" placeholder="What did you see?" maxlength="600"></textarea>
          <div class="composer__foot">
            <button class="btn" id="composer-send" disabled>Post</button>
            <span class="composer__count" id="composer-count">0/600</span>
          </div>
        </div>
        <?php else: ?>
        <p class="prose" style="font-size:var(--t-sm); color:var(--ink-2); margin-bottom:var(--s-5);">
          Members keep a running note of what they're watching, and put on their own screenings.
        </p>
        <?php endif; ?>

        <div id="statuses">
          <?php foreach ($statuses as $s): ?>
          <div class="status" data-post-id="<?php echo (int)$s['id']; ?>">
            <?php if ($me && $s['uid'] == $me['id']): ?>
            <button class="status__del" data-post-id="<?php echo (int)$s['id']; ?>" title="Delete">&times;</button>
            <?php endif; ?>
            <a class="status__av" href="/users/profile.php?id=<?php echo (int)$s['uid']; ?>">
              <?php if (!empty($s['author_photo'])): ?>
              <img src="/uploads/profiles/<?php echo $e($s['author_photo']); ?>" alt="" />
              <?php else: ?><i class="fa-solid fa-user"></i><?php endif; ?>
            </a>
            <div class="status__main">
              <div class="status__text"><?php echo nl2br($e($s['content'])); ?></div>
              <div class="status__meta">
                <a href="/users/profile.php?id=<?php echo (int)$s['uid']; ?>"><?php echo $e($s['author_name'] ?: 'Member'); ?></a>
                <span><?php echo date('j M', $s['stamp']); ?></span>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- 05 Members — the directory, tasted -->
        <div class="room__head" style="margin-top:var(--s-7);">
          <span class="stamp">05 &mdash; Members</span>
          <a class="room__count" href="/directory">Directory &rarr;</a>
        </div>

        <div class="members">
          <?php foreach ($members as $m): ?>
          <a class="member" href="/users/profile.php?id=<?php echo (int)$m['id']; ?>" title="<?php echo $e($m['name']); ?>">
            <?php if (!empty($m['photo'])): ?>
            <img src="/uploads/profiles/<?php echo $e($m['photo']); ?>" alt="<?php echo $e($m['name']); ?>" />
            <?php else: ?><?php echo $e(mb_substr($m['name'], 0, 1)); ?><?php endif; ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    </aside>

  </div><!-- /.spread -->

  <footer class="colophon">
    <span>Cinema, TX</span>
    <span>Austin</span>
    <span class="fill"></span>
    <a href="#">Discord</a>
    <a href="#">Instagram</a>
    <span><?php echo date('Y'); ?></span>
  </footer>

</main>

<!-- ══ ACCOUNT PANEL — utility, out of the page ═══════════════════════════ -->
<div class="scrim" id="scrim"></div>
<aside class="panel" id="panel">
  <div class="panel__head">
    <span class="panel__title">Become a member</span>
    <button class="icon-btn" data-panel-close title="Close"><i class="fa-solid fa-xmark"></i></button>
  </div>

  <div class="panel__body">
    <?php
    $err = $_GET['error'] ?? null;
    $msg = ['100' => 'Retry your email or password.', '102' => 'Registration error.', '104' => 'That email is already registered.'][$err] ?? null;
    if ($msg): ?><div class="alert"><?php echo $e($msg); ?></div><?php endif; ?>

    <div id="join-step-1">
      <div class="alert" id="join-error" style="display:none;"></div>
      <form id="join-form">
        <input type="hidden" name="ajax" value="1" />
        <div class="field"><label class="field__label" for="j-email">Email</label>
          <input class="field__input" id="j-email" type="email" name="email" /></div>
        <div class="field"><label class="field__label" for="j-pw">Password</label>
          <input class="field__input" id="j-pw" type="password" name="pw" /></div>
        <div class="field"><label class="field__label" for="j-pw2">Confirm password</label>
          <input class="field__input" id="j-pw2" type="password" name="pw2" /></div>
        <div class="field"><label class="field__label" for="j-code">Access code</label>
          <input class="field__input" id="j-code" type="text" name="code" placeholder="Ask around." /></div>
        <input class="btn btn--block" type="submit" value="Sign up" />
      </form>
    </div>

    <div id="join-step-2" style="display:none;">
      <form action="/dashboard/signup.php?action=firstcontact" method="post">
        <input type="hidden" name="uid" id="join-uid" value="" />
        <div class="field"><label class="field__label" for="j-name">Name</label>
          <input class="field__input" id="j-name" type="text" name="uname" /></div>
        <div class="field"><label class="field__label" for="j-role">Role</label>
          <select class="field__input" id="j-role" name="dept">
            <option value="0">Select one</option>
            <?php foreach ($roles as $r): ?><option value="<?php echo $e($r); ?>"><?php echo $e($r); ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label class="field__label" for="j-lb">Letterboxd</label>
          <input class="field__input" id="j-lb" type="text" name="lb" placeholder="Optional" /></div>
        <input class="btn btn--block" type="submit" value="Continue" />
      </form>
    </div>

    <p class="panel__note">Already a member</p>
    <form action="/dashboard/signup.php?action=login" method="post">
      <div class="field"><label class="field__label" for="s-email">Email</label>
        <input class="field__input" id="s-email" type="email" name="email" /></div>
      <div class="field"><label class="field__label" for="s-pw">Password</label>
        <input class="field__input" id="s-pw" type="password" name="pw" /></div>
      <button class="btn btn--quiet btn--block" type="submit">Sign in</button>
    </form>
  </div>
</aside>

<!-- ══ THEATRE ═══════════════════════════════════════════════════════════ -->
<div class="theatre" id="theatre">
  <div class="theatre__bar">
    <span class="theatre__name"><?php echo $film ? $e($film['title']) : 'Cinema, TX'; ?></span>
    <button class="theatre__close" id="theatre-close">Close</button>
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
      <?php echo $film ? $e($film['director']) : ''; ?><?php if ($show_ts): ?> &middot; <?php echo date('g:ia', $show_ts); ?><?php endif; ?> &middot; Synced to showtime
    </span>
  </div>
</div>

</body>
</html>
