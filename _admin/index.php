<?php
// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — Admin dashboard
//
//  Landing view only: stat tiles + visitor charts + links into Films,
//  Showtimes, and Instagram, which each own their actual forms now. Upload,
//  scheduling, and delete used to live here as raw cards — moved to their
//  own pages so this one answers "what's the state of things" rather than
//  doubling as every form at once.
//
//  _guard.php first, before _lib.php and before any output: authentication
//  is not something to do after loading the page's context.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
require dirname(__DIR__) . '/v7/_lib.php';

$film_count = (int) $conn->query("SELECT COUNT(*) FROM `films` WHERE `active` = 1")->fetchColumn();

$showtime_stmt = $conn->prepare("SELECT COUNT(*) FROM `showtimes` WHERE `endtime` > :now");
$showtime_stmt->execute([':now' => $CTX_NOW]);
$showtime_count = (int) $showtime_stmt->fetchColumn();

$posted_today = file_exists(dirname(__DIR__) . '/uploads/social/.posted-' . date('Y-m-d', $CTX_NOW));

$e = 'ctx_e';

$ctx_title     = 'Admin — Cinema, TX';
$ctx_active    = 'dashboard';
$ctx_admin_nav = true;
$ctx_shell     = 'admin-shell';
$ctx_scroll    = true;
$ctx_video     = false;

require dirname(__DIR__) . '/v7/_head.php';
require dirname(__DIR__) . '/v7/_chrome.php';
?>

  <main class="canvas">
    <div class="reading" style="max-width:none;">

      <div class="reading__kicker"><span>Signed in as <?php echo $e($admin_user['name'] ?: 'admin'); ?></span></div>
      <h1 class="reading__title">Dashboard</h1>

      <div class="stats__figures" style="margin-top: var(--s-6);">
        <div class="figure">
          <span class="figure__n"><?php echo $film_count; ?></span>
          <span class="figure__label">Active films</span>
        </div>
        <div class="figure">
          <span class="figure__n"><?php echo $showtime_count; ?></span>
          <span class="figure__label">Upcoming showtimes</span>
        </div>
        <div class="figure">
          <span class="figure__n"><?php echo $posted_today ? 'Posted' : 'Pending'; ?></span>
          <span class="figure__label">Today's Instagram post</span>
        </div>
      </div>

      <div class="admin-grid">

        <section class="card admin-card">
          <div class="card__head"><span class="card__n">01</span><span class="card__title">Films</span></div>
          <div class="card__body">
            <div class="admin-note">Upload a film, attach a programmer's note, or deactivate an existing one.</div>
            <a class="btn btn--block" href="/_admin/films.php">Open Films</a>
          </div>
        </section>

        <section class="card admin-card">
          <div class="card__head"><span class="card__n">02</span><span class="card__title">Showtimes</span></div>
          <div class="card__body">
            <div class="admin-note">Schedule a screening and see what's already booked.</div>
            <a class="btn btn--block" href="/_admin/showtimes.php">Open Showtimes</a>
          </div>
        </section>

        <section class="card admin-card">
          <div class="card__head"><span class="card__n">03</span><span class="card__title">Instagram</span></div>
          <div class="card__body">
            <div class="admin-note">Preview today's card and caption, then post it yourself when it looks right.</div>
            <a class="btn btn--block" href="/_admin/instagram.php">Review today's post</a>
          </div>
        </section>

      </div>

      <?php
      // Below the operations, because it is something to read rather than
      // something to do.
      $v    = ctx_visit_stats(14);
      $peak = max(1, max($v['recent']));
      ?>
      <section class="stats">
        <div class="stats__head">
          <span class="card__n">04</span>
          <span class="card__title">Visitors</span>
          <?php if ($v['since']): ?>
          <span class="stats__since">since <?php echo date('j M Y', $v['since']); ?></span>
          <?php endif; ?>
        </div>

        <div class="stats__figures">
          <?php foreach (['Today' => $v['today'], 'This week' => $v['week'],
                          'This month' => $v['month'], 'All time' => $v['total']] as $label => $n): ?>
          <div class="figure">
            <span class="figure__n"><?php echo $n; ?></span>
            <span class="figure__label"><?php echo $label; ?></span>
          </div>
          <?php endforeach; ?>
        </div>

        <?php if ($v['total']): ?>
        <div class="stats__cols">

          <div class="stats__col">
            <div class="stats__sub">Where they are</div>
            <?php // The bands are the question — a list of cities does not tell
                  // you whether this is reaching Austin. ?>
            <?php foreach ($v['bands'] as $band => $n): if (!$n) continue; ?>
            <div class="band">
              <span class="band__label"><?php echo $e($band); ?></span>
              <span class="band__bar"><span style="width:<?php echo round($n / $v['total'] * 100); ?>%"></span></span>
              <span class="band__n"><?php echo $n; ?></span>
            </div>
            <?php endforeach; ?>
          </div>

          <div class="stats__col">
            <div class="stats__sub">Places</div>
            <?php foreach ($v['places'] as $place => $n): ?>
            <div class="place">
              <span class="place__label"><?php echo $e($place); ?></span>
              <span class="place__n"><?php echo $n; ?></span>
            </div>
            <?php endforeach; ?>
          </div>

        </div>

        <div class="stats__sub">Last fourteen days</div>
        <div class="spark">
          <?php foreach (array_reverse($v['recent'], true) as $day => $n): ?>
          <div class="spark__day" title="<?php echo date('D j M', strtotime($day)); ?> — <?php echo $n; ?>">
            <span class="spark__bar" style="height:<?php echo max(2, round($n / $peak * 100)); ?>%"></span>
            <span class="spark__tick"><?php echo date('j', strtotime($day)); ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="admin-note">Nobody counted yet. Bots are excluded, so this stays at zero until a real browser arrives.</p>
        <?php endif; ?>
      </section>

    </div>
  </main>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
