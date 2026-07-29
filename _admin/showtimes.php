<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — Showtimes
//
//  Its own section rather than folded into Films — scheduling has more room
//  to grow (recurring showtimes, multiple theatres, conflict warnings) than
//  upload or notes do.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
require dirname(__DIR__) . '/v7/_lib.php';

$films = $conn->query("SELECT `id`, `title` FROM `films` WHERE `active` = 1 ORDER BY `id` DESC")
              ->fetchAll(PDO::FETCH_ASSOC);

$booked = $conn->prepare(
  "SELECT s.`id`, s.`showtime`, s.`theatre`, f.`title`
   FROM `showtimes` s LEFT JOIN `films` f ON f.`id` = s.`f_id`
   WHERE s.`endtime` > :now ORDER BY s.`showtime` ASC LIMIT 50"
);
$booked->execute([':now' => $CTX_NOW ?? time()]);
$booked = $booked->fetchAll(PDO::FETCH_ASSOC);

$e = 'ctx_e';

$ctx_title     = 'Showtimes — Cinema, TX Admin';
$ctx_active    = 'showtimes';
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
      <h1 class="reading__title">Showtimes</h1>
      <div class="reading__by"><?php echo count($booked); ?> upcoming</div>

      <div class="admin-grid">

        <section class="card admin-card">
          <div class="card__head"><span class="card__n">01</span><span class="card__title">Schedule a showtime</span></div>
          <div class="card__body">
            <form action="/_admin/showtime.php" method="post">
              <?php echo admin_csrf_field(); ?>
              <div class="field"><label class="field__label" for="s-film">Film</label>
                <select class="field__input" id="s-film" name="film_id">
                  <?php foreach ($films as $f): ?>
                  <option value="<?php echo (int)$f['id']; ?>"><?php echo $e($f['title']); ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div class="field"><label class="field__label" for="s-th">Theatre</label>
                <select class="field__input" id="s-th" name="theatre">
                  <option value="1">Theatre 1</option><option value="2">Theatre 2</option>
                </select></div>
              <div class="field"><label class="field__label" for="s-when">Date &amp; time <span class="admin-tz">America/Chicago</span></label>
                <input class="field__input" id="s-when" name="showtime" type="datetime-local" required /></div>
              <button class="btn btn--block" type="submit">Create showtime</button>
            </form>
          </div>
        </section>

      </div>

      <div class="admin-list admin-list--showtimes">
        <div class="admin-list__head">
          <span>When</span><span>Title</span><span>Theatre</span>
        </div>
        <?php foreach ($booked as $b): ?>
        <div class="admin-list__row">
          <span class="admin-list__meta"><?php echo date('D j M, g:ia', $b['showtime']); ?></span>
          <span class="admin-list__title"><?php echo $e($b['title']); ?></span>
          <span class="admin-list__meta">Th<?php echo (int)$b['theatre']; ?></span>
        </div>
        <?php endforeach; ?>
        <?php if (!$booked): ?>
        <div class="admin-note">Nothing scheduled.</div>
        <?php endif; ?>
      </div>

    </div>
  </main>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
