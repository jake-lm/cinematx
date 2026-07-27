<?php
// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — v7 front page
//  Weight order: 1 The List · 2 The Journal · 3 The Theatre · 4 The Room
//  /index.php and every earlier attempt are untouched.
// ═══════════════════════════════════════════════════════════════════════════
error_reporting(0);
session_start();
require dirname(__DIR__) . '/database.php';
require dirname(__DIR__) . '/roles.php';
require dirname(__DIR__) . '/list/fetch_screenings.php';
require __DIR__ . '/screenings.php';

$now    = time();
$signed = isset($_SESSION['username']);
$e      = fn($s) => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

function post_url($id, $title) {
  $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($title ?? '')), '-');
  return '/posts/' . $slug . '-' . (int)$id;
}

// ── 01 · The List ──────────────────────────────────────────────────────────
// Every screening for the window is rendered. The panel scrolls internally,
// so nothing is behind a required navigation and the page never scrolls.
$screenings = fetch_all_screenings($conn, $now, strtotime('tomorrow', $now) - 1);
$label = 'Tonight';
if (count($screenings) < 5) {
  $wider = fetch_all_screenings($conn, $now, $now + 172800);
  if (count($wider) > count($screenings)) { $screenings = $wider; $label = 'Tonight & tomorrow'; }
}
$screenings = ctx_enrich($screenings);
$venues     = ctx_venues($screenings);

$venue_counts = [];
foreach ($screenings as $s) {
  $k = ctx_slug($s['venue'] ?? '');
  $venue_counts[$k] = ($venue_counts[$k] ?? 0) + 1;
}

// ── 03 · The Theatre ───────────────────────────────────────────────────────
$playing = $conn->query("SELECT * FROM `showtimes` WHERE $now > `showtime` AND $now < `endtime` AND `theatre` = 1")->fetch();
$slot = $playing;
if (!$slot) {
  $q = $conn->prepare("SELECT * FROM `showtimes` WHERE `showtime` > :n AND `theatre` = 1 ORDER BY `showtime` ASC LIMIT 1");
  $q->execute([':n' => $now]); $slot = $q->fetch();
}
$film = null;
if ($slot) { $q = $conn->prepare("SELECT * FROM `films` WHERE `id` = :i"); $q->execute([':i' => (int)$slot['f_id']]); $film = $q->fetch(); }

// The programmer's note — notes.f_id joins the internal films library, not the
// scraped listings. A named human saying why tonight's film is on.
$note = null;
if ($film) {
  $q = $conn->prepare("SELECT * FROM `notes` WHERE `f_id` = :i ORDER BY `stamp` DESC LIMIT 1");
  $q->execute([':i' => (int)$film['id']]); $note = $q->fetch();
}

$show_ts = $slot ? (int)$slot['showtime'] : 0;
$is_live = (bool)$playing;

// ── 02 · The Journal ───────────────────────────────────────────────────────
$q = $conn->prepare("SELECT p.*, u.name AS author_name FROM posts p LEFT JOIN users u ON p.uid = u.id
  WHERE p.active = 1 AND p.featured = 1 AND p.type IN ('review','essay') ORDER BY p.stamp DESC LIMIT 1");
$q->execute(); $lead = $q->fetch(PDO::FETCH_ASSOC);

$q = $conn->prepare("SELECT p.id, p.uid, p.title, p.type, p.stamp, p.edited, u.name AS author_name
  FROM posts p LEFT JOIN users u ON p.uid = u.id
  WHERE p.active = 1 AND p.type IN ('review','essay') " . ($lead ? "AND p.id != " . (int)$lead['id'] . " " : "") .
  "ORDER BY COALESCE(p.edited, p.stamp) DESC LIMIT 4");
$q->execute(); $items = $q->fetchAll(PDO::FETCH_ASSOC);

// ── 04 · The Room ──────────────────────────────────────────────────────────
$me = null;
if ($signed) { $q = $conn->prepare("SELECT * FROM `users` WHERE `email` = :e"); $q->execute([':e' => $_SESSION['username']]); $me = $q->fetch(); }

$q = $conn->prepare("SELECT p.id, p.uid, p.content, p.stamp, u.name AS author_name
  FROM posts p LEFT JOIN users u ON p.uid = u.id
  WHERE p.active = 1 AND p.type = 'post' ORDER BY p.stamp DESC LIMIT 10");
$q->execute(); $notes = $q->fetchAll(PDO::FETCH_ASSOC);

$q = $conn->prepare("SELECT id, name, photo FROM `users` WHERE `active` = 1 AND `name` != '' ORDER BY `id` DESC LIMIT 7");
$q->execute(); $members = $q->fetchAll(PDO::FETCH_ASSOC);
$m_count = $conn->query("SELECT count(*) FROM `users` WHERE `active` = 1 AND `name` != ''")->fetchColumn();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Cinema, TX</title>
<script>try{var t=localStorage.getItem('ctx-theme');if(t==='dark')document.documentElement.setAttribute('data-theme','dark');}catch(e){}</script>
<link rel="stylesheet" href="/css/v7.css?v=<?php echo filemtime(dirname(__DIR__) . '/css/v7.css'); ?>" />
<link rel="stylesheet" href="https://vjs.zencdn.net/7.8.3/video-js.css" />
<link rel="icon" href="/img/iconimg.png" type="image/x-icon" />
<script src="https://kit.fontawesome.com/7ea7b5f42f.js" crossorigin="anonymous"></script>
<script src="https://vjs.zencdn.net/7.8.3/video.js"></script>
<script>
  window.CTX_SHOWTIME = <?php echo (int)$show_ts; ?>;
  window.CTX_DUR      = <?php echo (int)($film['dur'] ?? 0); ?>;
  window.CTX_FILE     = <?php echo json_encode($film['filename'] ?? ''); ?>;
</script>
<script src="/js/v7.js?v=<?php echo filemtime(dirname(__DIR__) . '/js/v7.js'); ?>" defer></script>
</head>
<body>

<div class="app" id="app">

  <!-- ══ RAIL ═══════════════════════════════════════════════════════════ -->
  <aside class="rail">
    <div class="rail__top"><a class="rail__mark" href="/v7/">Cinema<span class="c">,</span> TX</a></div>

    <nav class="rail__nav">
      <a class="rail__link is-on" href="/v7/"><span class="ico"><i class="fa-solid fa-film"></i></span><span class="txt">Tonight</span></a>
      <a class="rail__link" href="/list"><span class="ico"><i class="fa-solid fa-calendar-days"></i></span><span class="txt">The List</span></a>
      <a class="rail__link" href="/about"><span class="ico"><i class="fa-solid fa-circle-info"></i></span><span class="txt">About</span></a>

      <?php if ($signed): ?>
      <div class="rail__group">Yours</div>
      <a class="rail__link" href="/dashboard"><span class="ico"><i class="fa-solid fa-gauge"></i></span><span class="txt">Dashboard</span></a>
      <a class="rail__link" href="/directory"><span class="ico"><i class="fa-solid fa-users"></i></span><span class="txt">Directory</span></a>
      <a class="rail__link" href="/users/profile.php?id=<?php echo (int)$me['id']; ?>"><span class="ico"><i class="fa-solid fa-user"></i></span><span class="txt">Profile</span></a>
      <?php endif; ?>
    </nav>

    <div class="rail__foot">
      <div class="faces">
        <?php foreach ($members as $m): ?>
        <a class="face" href="/users/profile.php?id=<?php echo (int)$m['id']; ?>" title="<?php echo $e($m['name']); ?>">
          <?php if (!empty($m['photo'])): ?><img src="/uploads/profiles/<?php echo $e($m['photo']); ?>" alt="" />
          <?php else: ?><?php echo $e(mb_substr($m['name'], 0, 1)); ?><?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php $rest = (int)$m_count - count($members); if ($rest > 0): ?>
        <a class="face" href="/directory" title="All members">+<?php echo $rest; ?></a>
        <?php endif; ?>
      </div>
    </div>
  </aside>

  <!-- ══ BAR ════════════════════════════════════════════════════════════ -->
  <header class="bar">
    <button class="ibtn rail__toggle" id="rail-toggle" title="Collapse"><i class="fa-solid fa-bars"></i></button>
    <a class="bar__mark" href="/v7/">Cinema<span class="c">,</span> TX</a>
    <span class="welcome" id="welcome">Welcome to the Cinema</span>

    <div class="bar__end">
      <button class="ibtn" id="theme" title="Dark"><i class="fa-solid fa-moon"></i></button>
      <?php if ($signed): ?>
        <a class="ibtn" href="/dashboard" title="Write"><i class="fa-solid fa-pen"></i></a>
        <form action="/dashboard/signup.php?action=logout" method="post" style="display:flex;">
          <button class="ibtn" type="submit" title="Sign out"><i class="fa-solid fa-arrow-right-from-bracket"></i></button>
        </form>
      <?php else: ?>
        <button class="btn" data-open="account">Sign in</button>
      <?php endif; ?>
    </div>
  </header>

  <!-- ══ CANVAS ═════════════════════════════════════════════════════════ -->
  <main class="canvas">
    <div class="canvas__in">

      <!-- ── 01 · THE LIST — rank 1 ──────────────────────────────────── -->
      <section class="card">
        <div class="card__head">
          <span class="card__n">01</span>
          <span class="card__title"><?php echo $e($label); ?> in Austin</span>
          <a class="card__more" href="/list"><span id="list-count"><?php echo count($screenings); ?></span> screenings &rarr;</a>
        </div>

        <div class="controls">
          <div class="seg">
            <button class="seg__btn is-on" data-view="grid" title="Posters"><i class="fa-solid fa-grip"></i></button>
            <button class="seg__btn" data-view="rows" title="List"><i class="fa-solid fa-list"></i></button>
          </div>

          <div class="chips">
            <button class="chip is-on" data-venue-filter="all">All<span class="chip__n"><?php echo count($screenings); ?></span></button>
            <?php foreach ($venues as $v): $vs = ctx_slug($v); ?>
            <button class="chip" data-venue-filter="<?php echo $e($vs); ?>">
              <?php echo $e(ctx_venue_short($v)); ?>
              <span class="chip__n"><?php echo (int)($venue_counts[$vs] ?? 0); ?></span>
            </button>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="card__body">
          <?php if (!$screenings): ?>
            <p class="empty">Nothing listed</p>
          <?php else: ?>

          <!-- Poster view -->
          <div class="grid-view" id="grid-view">
            <?php foreach ($screenings as $s):
              $member = ($s['source'] ?? '') === 'user';
              $href   = !empty($s['url']) ? $s['url'] : '/list';
              $other  = date('j M', $s['timestamp']) !== date('j M', $now);
            ?>
            <a class="shot<?php echo $member ? ' shot--member' : ''; ?>"
               data-venue="<?php echo $e(ctx_slug($s['venue'])); ?>"
               href="<?php echo $e($href); ?>"<?php echo $member ? '' : ' target="_blank" rel="noopener"'; ?>>
              <span class="shot__art">
                <?php if (!empty($s['poster'])): ?>
                <img src="<?php echo $e($s['poster']); ?>" alt="<?php echo $e($s['display_title']); ?>" loading="lazy" />
                <?php else: ?>
                <span class="shot__blank"><?php echo $e($s['display_title']); ?></span>
                <?php endif; ?>
                <span class="shot__time"><?php echo date('g:ia', $s['timestamp']); ?><?php echo $other ? ' · ' . date('D', $s['timestamp']) : ''; ?></span>
              </span>
              <span class="shot__title"><?php echo $e($s['display_title']); ?></span>
              <?php if (!empty($s['series'])): ?><span class="shot__series"><?php echo $e($s['series']); ?></span><?php endif; ?>
              <span class="shot__venue">
                <?php if ($member): ?><span class="shot__by">&#9679; By a member</span><?php else: ?><?php echo $e($s['venue']); ?><?php endif; ?>
              </span>
            </a>
            <?php endforeach; ?>
            <p class="empty" id="grid-empty" style="display:none; grid-column:1/-1;">Nothing at that venue</p>
          </div>

          <!-- Row view -->
          <div class="rows-view" id="rows-view">
            <?php foreach ($screenings as $s):
              $member = ($s['source'] ?? '') === 'user';
              $href   = !empty($s['url']) ? $s['url'] : '/list';
              $other  = date('j M', $s['timestamp']) !== date('j M', $now);
            ?>
            <a class="line<?php echo $member ? ' line--member' : ''; ?>"
               data-venue="<?php echo $e(ctx_slug($s['venue'])); ?>"
               href="<?php echo $e($href); ?>"<?php echo $member ? '' : ' target="_blank" rel="noopener"'; ?>>
              <span class="line__time"><?php echo date('g:ia', $s['timestamp']); ?></span>
              <span>
                <span class="line__title"><?php echo $e($s['display_title']); ?></span>
                <span class="line__sub">
                  <?php if ($member): ?><span class="shot__by">&#9679; By a member</span> &middot; <?php endif; ?>
                  <?php if (!empty($s['series'])): ?><?php echo $e($s['series']); ?> &middot; <?php endif; ?>
                  <?php echo $e($s['venue']); ?><?php echo $other ? ' · ' . date('D j M', $s['timestamp']) : ''; ?>
                </span>
              </span>
              <span class="line__venue"><?php echo $other ? date('D', $s['timestamp']) : 'Today'; ?></span>
            </a>
            <?php endforeach; ?>
            <p class="empty" id="rows-empty" style="display:none;">Nothing at that venue</p>
          </div>

          <?php endif; ?>
        </div>
      </section>

      <!-- ── SIDE — ranks 2, 3, 4 ────────────────────────────────────── -->
      <div class="side">

        <!-- 02 · The Journal -->
        <section class="card">
          <div class="card__head">
            <span class="card__n">02</span>
            <span class="card__title">The Journal</span>
            <a class="card__more" href="/list">Archive &rarr;</a>
          </div>
          <div class="card__body">
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
              </div>
              <div class="lead__wrap">
                <div class="lead__body"><?php echo nl2br($e($lead['content'])); ?></div>
                <div class="lead__fade"></div>
              </div>
              <div class="lead__foot"><button class="btn btn--quiet" data-open="essay">Read in full</button></div>
            </article>
            <?php endif; ?>

            <?php if ($items): ?>
            <div class="strip">
              <div class="strip__head">More writing</div>
              <?php foreach ($items as $p): ?>
              <a class="row" href="<?php echo $e(post_url($p['id'], $p['title'])); ?>">
                <span class="row__type"><?php echo $e($p['type']); ?></span>
                <span class="row__title"><?php echo $e($p['title']); ?></span>
              </a>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
        </section>

        <!-- 03 · The Theatre -->
        <section class="theatre-card" id="theatre-card">
          <div class="theatre-card__in">
            <div class="theatre-card__poster" <?php if ($film): ?>style="background-image:url('/motw/<?php echo $e($film['poster']); ?>.png')"<?php endif; ?>></div>
            <div class="theatre-card__meta">
              <?php if ($is_live): ?>
                <span class="theatre-card__kicker"><span class="dot"></span> 03 &mdash; On screen now</span>
              <?php else: ?>
                <span class="theatre-card__kicker">03 &mdash; The Theatre<?php echo $show_ts ? ' · ' . date('D g:ia', $show_ts) : ''; ?></span>
              <?php endif; ?>
              <h2 class="theatre-card__title"><?php echo $film ? $e($film['title']) : 'Dark tonight'; ?></h2>
              <?php if ($film): ?>
              <div class="theatre-card__credits"><?php echo $e($film['director']); ?> &middot; Everyone watches at once</div>
              <?php endif; ?>
            </div>
          </div>

          <?php if (!empty($note['note'])): ?>
          <p class="theatre-card__note">
            <?php echo $e($note['note']); ?>
            <?php if (!empty($film['program'])): ?><span class="prog">— <?php echo $e($film['program']); ?></span><?php endif; ?>
          </p>
          <?php endif; ?>
        </section>

        <!-- 04 · The Room -->
        <section class="card">
          <div class="card__head">
            <span class="card__n">04</span>
            <span class="card__title">The Room</span>
            <a class="card__more" href="/directory"><?php echo (int)$m_count; ?> members &rarr;</a>
          </div>
          <div class="card__body">
            <?php if ($signed): ?>
            <div class="compose">
              <textarea class="compose__area" id="compose-area" placeholder="What did you see?" maxlength="600"></textarea>
              <div class="compose__foot">
                <button class="btn" id="compose-send" disabled>Post</button>
                <span class="compose__count" id="compose-count">0/600</span>
              </div>
            </div>
            <?php else: ?>
            <p class="pitch">Members keep a running note of what they're watching, and <em>put on their own screenings</em>.</p>
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
        </section>

      </div>
    </div>
  </main>
</div>

<!-- ══ OVERLAYS ═══════════════════════════════════════════════════════ -->
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

    <div class="field__label" style="margin:var(--s-6) 0 var(--s-3);">Already a member</div>
    <form action="/dashboard/signup.php?action=login" method="post">
      <div class="field"><label class="field__label" for="s-email">Email</label>
        <input class="field__input" id="s-email" type="email" name="email" /></div>
      <div class="field"><label class="field__label" for="s-pw">Password</label>
        <input class="field__input" id="s-pw" type="password" name="pw" /></div>
      <button class="btn btn--quiet btn--block" type="submit">Sign in</button>
    </form>
  </div>
</aside>

<!-- ══ THEATRE ════════════════════════════════════════════════════════ -->
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
