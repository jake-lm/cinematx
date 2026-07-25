<?php
// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — v5 front page
//  A broadsheet that fits one viewport. Everything above the fold; depth via
//  overlays. /index.php and the earlier attempts are untouched.
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

// ── On screen ──────────────────────────────────────────────────────────────
$playing = $conn->query("SELECT * FROM `showtimes` WHERE $now > `showtime` AND $now < `endtime` AND `theatre` = 1")->fetch();
$slot = $playing;
if (!$slot) {
  $q = $conn->prepare("SELECT * FROM `showtimes` WHERE `showtime` > :n AND `theatre` = 1 ORDER BY `showtime` ASC LIMIT 1");
  $q->execute([':n' => $now]); $slot = $q->fetch();
}
$film = null;
if ($slot) { $q = $conn->prepare("SELECT * FROM `films` WHERE `id` = :i"); $q->execute([':i' => (int)$slot['f_id']]); $film = $q->fetch(); }
$note = null;
if ($film) { $q = $conn->prepare("SELECT * FROM `notes` WHERE `f_id` = :i ORDER BY `stamp` DESC LIMIT 1"); $q->execute([':i' => (int)$film['id']]); $note = $q->fetch(); }

$show_ts = $slot ? (int)$slot['showtime'] : 0;
$is_live = (bool)$playing;

// ── The board ──────────────────────────────────────────────────────────────
$screenings = fetch_all_screenings($conn, $now, strtotime('tomorrow', $now) - 1);
$label = 'Tonight';
if (count($screenings) < 5) {
  $wider = fetch_all_screenings($conn, $now, $now + 172800);
  if (count($wider) > count($screenings)) { $screenings = $wider; $label = 'Tonight & tomorrow'; }
}

// ── The journal ────────────────────────────────────────────────────────────
$q = $conn->prepare("SELECT p.*, u.name AS author_name FROM posts p LEFT JOIN users u ON p.uid = u.id
  WHERE p.active = 1 AND p.featured = 1 AND p.type IN ('review','essay') ORDER BY p.stamp DESC LIMIT 1");
$q->execute(); $lead = $q->fetch(PDO::FETCH_ASSOC);

$q = $conn->prepare("SELECT p.id, p.uid, p.title, p.subtitle, p.type, p.stamp, p.edited, u.name AS author_name
  FROM posts p LEFT JOIN users u ON p.uid = u.id
  WHERE p.active = 1 AND p.type IN ('review','essay') " . ($lead ? "AND p.id != " . (int)$lead['id'] . " " : "") .
  "ORDER BY COALESCE(p.edited, p.stamp) DESC LIMIT 5");
$q->execute(); $items = $q->fetchAll(PDO::FETCH_ASSOC);

// ── The room ───────────────────────────────────────────────────────────────
$me = null;
if ($signed) { $q = $conn->prepare("SELECT * FROM `users` WHERE `email` = :e"); $q->execute([':e' => $_SESSION['username']]); $me = $q->fetch(); }

$q = $conn->prepare("SELECT p.id, p.uid, p.content, p.stamp, u.name AS author_name
  FROM posts p LEFT JOIN users u ON p.uid = u.id
  WHERE p.active = 1 AND p.type = 'post' ORDER BY p.stamp DESC LIMIT 8");
$q->execute(); $notes = $q->fetchAll(PDO::FETCH_ASSOC);

// ── Members ────────────────────────────────────────────────────────────────
$q = $conn->prepare("SELECT id, name, photo FROM `users` WHERE `active` = 1 AND `name` != '' ORDER BY `id` DESC LIMIT 8");
$q->execute(); $members = $q->fetchAll(PDO::FETCH_ASSOC);
$m_count = $conn->query("SELECT count(*) FROM `users` WHERE `active` = 1 AND `name` != ''")->fetchColumn();

// One row of the board, reused on the front page and inside the overlay.
function board_row($s, $now, $e) {
  $member = ($s['source'] ?? '') === 'user';
  $href   = !empty($s['url']) ? $s['url'] : '/list';
  $other  = date('j M', $s['timestamp']) !== date('j M', $now);
  ?>
  <a class="board__row<?php echo $member ? ' board__row--member' : ''; ?>" href="<?php echo $e($href); ?>"<?php echo $member ? '' : ' target="_blank" rel="noopener"'; ?>>
    <span class="board__time"><?php echo date('g:ia', $s['timestamp']); ?><?php echo $other ? ' · ' . date('D', $s['timestamp']) : ''; ?></span>
    <span class="board__title"><?php echo $e($s['title']); ?></span>
    <span class="board__where">
      <?php if ($member): ?><span class="board__by">&#9679; By a member</span> &middot; <?php endif; ?>
      <?php echo $e($s['venue']); ?>
    </span>
  </a>
  <?php
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Cinema, TX</title>
<script>try{var t=localStorage.getItem('ctx-theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
<link rel="stylesheet" href="/css/v5.css?v=<?php echo filemtime(dirname(__DIR__) . '/css/v5.css'); ?>" />
<link rel="stylesheet" href="https://vjs.zencdn.net/7.8.3/video-js.css" />
<link rel="icon" href="/img/iconimg.png" type="image/x-icon" />
<script src="https://kit.fontawesome.com/7ea7b5f42f.js" crossorigin="anonymous"></script>
<script src="https://vjs.zencdn.net/7.8.3/video.js"></script>
<script>
  window.CTX_SHOWTIME = <?php echo (int)$show_ts; ?>;
  window.CTX_DUR      = <?php echo (int)($film['dur'] ?? 0); ?>;
  window.CTX_FILE     = <?php echo json_encode($film['filename'] ?? ''); ?>;
</script>
<script src="/js/v5.js?v=<?php echo filemtime(dirname(__DIR__) . '/js/v5.js'); ?>" defer></script>
</head>
<body>

<div class="app">

  <!-- ══ MASTHEAD ═══════════════════════════════════════════════════════ -->
  <header class="head" id="head">
    <a class="head__word" href="/v5/">Cinema<span class="c">,</span> TX</a>

    <nav class="head__nav">
      <a class="head__link is-on" href="/v5/">Index</a>
      <a class="head__link" href="/list">The List</a>
      <a class="head__link" href="/about">About</a>
      <?php if ($signed): ?>
      <a class="head__link" href="/directory">Directory</a>
      <a class="head__link" href="/dashboard">Dashboard</a>
      <?php endif; ?>
    </nav>

    <span class="head__rule"></span>
    <span class="head__date">Austin &middot; <?php echo date('D j M Y'); ?></span>

    <div class="head__end">
      <button class="ibtn" id="theme" title="Dark"><i class="fa-solid fa-moon"></i></button>
      <?php if ($signed): ?>
        <a class="ibtn" href="/dashboard" title="Write"><i class="fa-solid fa-pen"></i></a>
        <form action="/dashboard/signup.php?action=logout" method="post" style="display:flex;">
          <button class="ibtn" type="submit" title="Sign out"><i class="fa-solid fa-arrow-right-from-bracket"></i></button>
        </form>
      <?php else: ?>
        <button class="btn" data-open="account">Sign in</button>
      <?php endif; ?>
      <button class="ibtn head__toggle" id="head-toggle" title="Menu"><i class="fa-solid fa-bars"></i></button>
    </div>
  </header>

  <!-- ══ THE FRONT PAGE ═════════════════════════════════════════════════ -->
  <div class="front">

    <!-- ── 01 · The board ─────────────────────────────────────────────── -->
    <section class="col col--left">
      <div class="col__head">
        <span class="col__n">01</span>
        <span class="col__title"><?php echo $e($label); ?></span>
        <button class="col__more" data-open="list">All <?php echo count($screenings); ?> &rarr;</button>
      </div>

      <div class="col__body board">
        <?php if ($screenings): foreach (array_slice($screenings, 0, 12) as $s) board_row($s, $now, $e); ?>
        <?php else: ?><p class="board__empty">Nothing listed.</p><?php endif; ?>
      </div>

      <div class="who">
        <div class="who__head">
          <span class="who__label">05 &mdash; Members</span>
          <a class="who__more" href="/directory"><?php echo (int)$m_count; ?> &rarr;</a>
        </div>
        <div class="who__faces">
          <?php foreach ($members as $m): ?>
          <a class="face" href="/users/profile.php?id=<?php echo (int)$m['id']; ?>" title="<?php echo $e($m['name']); ?>">
            <?php if (!empty($m['photo'])): ?><img src="/uploads/profiles/<?php echo $e($m['photo']); ?>" alt="" />
            <?php else: ?><?php echo $e(mb_substr($m['name'], 0, 1)); ?><?php endif; ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <!-- ── 03 · The journal (widest — it earns the room) ───────────────── -->
    <section class="col col--mid">
      <div class="col__head">
        <span class="col__n">03</span>
        <span class="col__title">The Journal</span>
        <a class="col__more" href="/list">Archive &rarr;</a>
      </div>

      <div class="col__body">
        <?php if ($lead): ?>
        <article class="lead">
          <div class="lead__kicker">
            <span><?php echo $e($lead['type'] ?: 'Essay'); ?></span>
            <span><?php echo date('j F Y', $lead['edited'] ?: $lead['stamp']); ?></span>
          </div>

          <h1 class="lead__title"><?php echo $e($lead['title']); ?></h1>

          <?php if (!empty($lead['subtitle'])): ?>
          <p class="lead__deck"><?php echo $e($lead['subtitle']); ?></p>
          <?php endif; ?>

          <div class="lead__by">
            <?php if (!empty($lead['author_name'])): ?>
            <a href="/users/profile.php?id=<?php echo (int)$lead['uid']; ?>"><?php echo $e($lead['author_name']); ?></a>
            <?php endif; ?>
            <span>Cinema, TX</span>
          </div>

          <?php if (!empty($lead['image'])): ?>
          <figure class="lead__figure">
            <img class="lead__img" src="/uploads/posts/<?php echo $e($lead['image']); ?>" alt="<?php echo $e($lead['title']); ?>" />
          </figure>
          <?php endif; ?>

          <div class="lead__wrap">
            <div class="lead__cols"><?php echo nl2br($e($lead['content'])); ?></div>
            <div class="lead__fade"></div>
          </div>

          <div class="lead__foot">
            <button class="btn btn--quiet" data-open="essay">Read in full</button>
          </div>
        </article>
        <?php endif; ?>

        <div class="more">
          <div class="more__head">More writing</div>
          <?php foreach ($items as $p): ?>
          <a class="item" href="<?php echo $e(post_url($p['id'], $p['title'])); ?>">
            <span>
              <span class="item__kicker">
                <span class="type"><?php echo $e($p['type']); ?></span> &middot;
                <?php echo $e($p['author_name'] ?: 'Member'); ?>
              </span>
              <h2 class="item__title"><?php echo $e($p['title']); ?></h2>
            </span>
            <span class="item__date"><?php echo date('j M', $p['edited'] ?: $p['stamp']); ?></span>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <!-- ── 02 · On screen + 04 · The room ─────────────────────────────── -->
    <section class="col col--right">

      <div class="screen" id="screen">
        <div class="screen__in">
          <div class="screen__poster" <?php if ($film): ?>style="background-image:url('/motw/<?php echo $e($film['poster']); ?>.png')"<?php endif; ?>></div>
          <div class="screen__meta">
            <?php if ($is_live): ?>
              <span class="screen__kicker"><span class="dot"></span> 02 &mdash; On screen now</span>
            <?php else: ?>
              <span class="screen__kicker">02 &mdash; Next<?php echo $show_ts ? ' · ' . date('D g:ia', $show_ts) : ''; ?></span>
            <?php endif; ?>
            <h2 class="screen__title"><?php echo $film ? $e($film['title']) : 'Dark tonight'; ?></h2>
            <?php if ($film): ?>
            <div class="screen__credits">
              <?php echo $e($film['director']); ?><?php if (!empty($film['dur'])): ?> &middot; <?php echo gmdate('G\hi\m', (int)$film['dur']); ?><?php endif; ?>
            </div>
            <span class="screen__cta">Everyone watches at once &rarr;</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="room">
        <div class="room__head">
          <span class="stamp">04 &mdash; The Room</span>
          <span class="room__count"><?php echo count($notes); ?> notes</span>
        </div>

        <div class="room__body">
          <?php if ($signed): ?>
          <div class="compose">
            <textarea class="compose__area" id="compose-area" placeholder="What did you see?" maxlength="600"></textarea>
            <div class="compose__foot">
              <button class="btn" id="compose-send" disabled>Post</button>
              <span class="compose__count" id="compose-count">0/600</span>
            </div>
          </div>
          <?php else: ?>
          <p class="join-pitch">Members keep a running note of what they're watching, and <em>put on their own screenings</em>.</p>
          <?php endif; ?>

          <div id="notes">
            <?php foreach ($notes as $n): ?>
            <div class="note" data-post-id="<?php echo (int)$n['id']; ?>">
              <?php if ($me && $n['uid'] == $me['id']): ?>
              <button class="note__del" data-post-id="<?php echo (int)$n['id']; ?>" title="Delete">&times;</button>
              <?php endif; ?>
              <div class="note__text"><?php echo nl2br($e($n['content'])); ?></div>
              <div class="note__meta">
                <a href="/users/profile.php?id=<?php echo (int)$n['uid']; ?>"><?php echo $e($n['author_name'] ?: 'Member'); ?></a>
                <span><?php echo date('j M', $n['stamp']); ?></span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

    </section>

  </div><!-- /.front -->
</div><!-- /.app -->

<!-- ══ OVERLAYS ═════════════════════════════════════════════════════════ -->
<div class="scrim" id="scrim"></div>

<?php if ($lead): ?>
<div class="sheet" id="sheet-essay">
  <div class="sheet__head">
    <span class="sheet__title"><?php echo $e($lead['title']); ?></span>
    <button class="ibtn sheet__x" data-close title="Close"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="sheet__body">
    <?php if (!empty($lead['subtitle'])): ?><p class="lead__deck"><?php echo $e($lead['subtitle']); ?></p><?php endif; ?>
    <div class="lead__by">
      <?php if (!empty($lead['author_name'])): ?><span><?php echo $e($lead['author_name']); ?></span><?php endif; ?>
      <span><?php echo date('j F Y', $lead['edited'] ?: $lead['stamp']); ?></span>
    </div>
    <div class="prose"><?php echo nl2br($e($lead['content'])); ?></div>
  </div>
</div>
<?php endif; ?>

<div class="sheet" id="sheet-list">
  <div class="sheet__head">
    <span class="sheet__title"><?php echo $e($label); ?> in Austin</span>
    <button class="ibtn sheet__x" data-close title="Close"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="sheet__body" style="padding:0;">
    <div class="board">
      <?php foreach ($screenings as $s) board_row($s, $now, $e); ?>
    </div>
  </div>
</div>

<aside class="sheet sheet--side" id="sheet-account">
  <div class="sheet__head">
    <span class="sheet__title">Become a member</span>
    <button class="ibtn sheet__x" data-close title="Close"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="sheet__body">
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

    <p style="margin:var(--s-6) 0 var(--s-3);"><span class="who__label">Already a member</span></p>
    <form action="/dashboard/signup.php?action=login" method="post">
      <div class="field"><label class="field__label" for="s-email">Email</label>
        <input class="field__input" id="s-email" type="email" name="email" /></div>
      <div class="field"><label class="field__label" for="s-pw">Password</label>
        <input class="field__input" id="s-pw" type="password" name="pw" /></div>
      <button class="btn btn--quiet btn--block" type="submit">Sign in</button>
    </form>
  </div>
</aside>

<!-- ══ THEATRE ══════════════════════════════════════════════════════════ -->
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
    <span class="theatre__meta"><?php echo $film ? $e($film['director']) : ''; ?> &middot; Synced to showtime</span>
  </div>
</div>

</body>
</html>
