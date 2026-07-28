<?php
// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — Admin
//
//  The last surface on the old stylesheet, now in the v7 shell.
//
//  Four operations, down from five: "film of the week" is gone. Nothing has
//  read films.motw since the pre-v7 homepage was retired — the only readers
//  left were script.js and script-jlm.js, which drew a banner on a page that
//  no longer exists. The column is still there and still written as 0 on
//  upload; it can be dropped whenever the schema is next touched.
//
//  _guard.php first, before _lib.php and before any output: authentication
//  is not something to do after loading the page's context.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
require dirname(__DIR__) . '/v7/_lib.php';

// The old page ran the same query four times — once per <select>, because
// each one consumed the cursor — and drove the loops off a separate COUNT.
// Fetch once and reuse.
$films = $conn->query("SELECT `id`, `title` FROM `films` WHERE `active` = 1 ORDER BY `id` DESC")
              ->fetchAll(PDO::FETCH_ASSOC);

// Scheduling into a void was the panel's real weakness: no way to see what
// was already booked, so double-bookings were only visible on the theatre page.
$booked = $conn->prepare(
  "SELECT s.`id`, s.`showtime`, s.`theatre`, f.`title`
   FROM `showtimes` s LEFT JOIN `films` f ON f.`id` = s.`f_id`
   WHERE s.`endtime` > :now ORDER BY s.`showtime` ASC LIMIT 12"
);
$booked->execute([':now' => $CTX_NOW]);
$booked = $booked->fetchAll(PDO::FETCH_ASSOC);

$e   = 'ctx_e';
$opt = function ($films) use ($e) {
    foreach ($films as $f) {
        echo '<option value="' . (int)$f['id'] . '">' . $e($f['title']) . '</option>';
    }
};

$ctx_title  = 'Admin — Cinema, TX';
$ctx_active = '';
$ctx_scroll = true;
$ctx_video  = false;
$ctx_scripts = '<script src="/js/admin.js?v=' . filemtime(dirname(__DIR__) . '/js/admin.js') . '" defer></script>';

require dirname(__DIR__) . '/v7/_head.php';
require dirname(__DIR__) . '/v7/_chrome.php';
?>

  <main class="canvas">
    <div class="reading" style="max-width:none;">

      <div class="reading__kicker"><span>Signed in as <?php echo $e($admin_user['name'] ?: 'admin'); ?></span></div>
      <h1 class="reading__title">Admin</h1>
      <div class="reading__by"><?php echo count($films); ?> films &middot; <?php echo count($booked); ?> upcoming showtimes</div>

      <div class="admin-grid">

        <section class="card admin-card">
          <div class="card__head"><span class="card__n">01</span><span class="card__title">Upload a film</span></div>
          <div class="card__body">
            <form id="upload-form" action="/_admin/upload.php" method="post" enctype="multipart/form-data">
              <?php echo admin_csrf_field(); ?>
              <div class="field"><label class="field__label" for="u-title">Title</label>
                <input class="field__input" id="u-title" name="title" type="text" required /></div>
              <div class="field"><label class="field__label" for="u-dir">Director</label>
                <input class="field__input" id="u-dir" name="director" type="text" /></div>
              <div class="field"><label class="field__label" for="u-wiki">Wikipedia URL</label>
                <input class="field__input" id="u-wiki" name="wiki" type="url" placeholder="https://en.wikipedia.org/wiki/…" /></div>
              <div class="field"><label class="field__label" for="u-prog">Programmed by</label>
                <input class="field__input" id="u-prog" name="program" type="text" /></div>
              <div class="field"><label class="field__label" for="u-film">Video file (.mp4)</label>
                <input class="field__input" id="u-film" name="film" type="file" accept="video/mp4" required /></div>
              <div class="field"><label class="field__label" for="u-poster">Poster (.png)</label>
                <input class="field__input" id="u-poster" name="poster" type="file" accept="image/png" required /></div>

              <progress class="admin-progress" id="upload-progress" value="0" max="100" hidden></progress>
              <div class="admin-note" id="upload-note"></div>

              <input class="btn btn--block" id="upload-button" type="submit" value="Upload" />
            </form>
          </div>
        </section>

        <section class="card admin-card">
          <div class="card__head"><span class="card__n">02</span><span class="card__title">Schedule a showtime</span></div>
          <div class="card__body">
            <form action="/_admin/showtime.php" method="post">
              <?php echo admin_csrf_field(); ?>
              <div class="field"><label class="field__label" for="s-film">Film</label>
                <select class="field__input" id="s-film" name="film_id"><?php $opt($films); ?></select></div>
              <div class="field"><label class="field__label" for="s-th">Theatre</label>
                <select class="field__input" id="s-th" name="theatre">
                  <option value="1">Theatre 1</option><option value="2">Theatre 2</option>
                </select></div>
              <div class="field"><label class="field__label" for="s-when">Date &amp; time <span class="admin-tz">America/Chicago</span></label>
                <input class="field__input" id="s-when" name="showtime" type="datetime-local" required /></div>
              <button class="btn btn--block" type="submit">Create showtime</button>
            </form>

            <?php if ($booked): ?>
            <div class="admin-booked">
              <div class="admin-booked__head">Already booked</div>
              <?php foreach ($booked as $b): ?>
              <div class="admin-booked__row">
                <span class="admin-booked__when"><?php echo date('D j M, g:ia', $b['showtime']); ?></span>
                <span class="admin-booked__title"><?php echo $e($b['title']); ?></span>
                <span class="admin-booked__th">Th<?php echo (int)$b['theatre']; ?></span>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
        </section>

        <section class="card admin-card">
          <div class="card__head"><span class="card__n">03</span><span class="card__title">Programmer's note</span></div>
          <div class="card__body">
            <form action="/_admin/note.php" method="post">
              <?php echo admin_csrf_field(); ?>
              <div class="field"><label class="field__label" for="n-film">Film</label>
                <select class="field__input" id="n-film" name="film_id"><?php $opt($films); ?></select></div>
              <div class="field"><label class="field__label" for="n-note">Note</label>
                <textarea class="field__input admin-textarea" id="n-note" name="note" rows="5" required></textarea></div>
              <button class="btn btn--block" type="submit">Add note</button>
            </form>
          </div>
        </section>

        <section class="card admin-card admin-card--danger">
          <div class="card__head"><span class="card__n">04</span><span class="card__title">Delete a film</span></div>
          <div class="card__body">
            <form action="/_admin/delete.php" method="post"
                  onsubmit="return confirm('Delete this film? The video and poster files are removed from disk and cannot be recovered.');">
              <?php echo admin_csrf_field(); ?>
              <div class="field"><label class="field__label" for="d-film">Film</label>
                <select class="field__input" id="d-film" name="film_id"><?php $opt($films); ?></select></div>
              <div class="admin-note">Deletes the .mp4 and .png from disk. This cannot be undone.</div>
              <button class="btn btn--block admin-danger" type="submit">Delete film</button>
            </form>
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
          <span class="card__n">05</span>
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
