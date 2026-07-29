<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — Showtimes
//
//  Its own section rather than folded in with Films: scheduling has the most
//  room to grow of anything here (recurring slots, more theatres, conflict
//  warnings), and it is the one thing you come back to repeatedly.
//
//  Scheduling used to happen blind — the old panel showed nothing about what
//  was already booked, so a double-booking only turned up on the theatre page.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
require dirname(__DIR__) . '/v7/_lib.php';

$films = $conn->query("SELECT `id`, `title` FROM `films` WHERE `active` = 1 ORDER BY `id` DESC")
              ->fetchAll(PDO::FETCH_ASSOC);

$booked = $conn->prepare(
  "SELECT s.`id`, s.`showtime`, s.`theatre`, f.`title`, f.`poster`
   FROM `showtimes` s LEFT JOIN `films` f ON f.`id` = s.`f_id`
   WHERE s.`endtime` > :now ORDER BY s.`showtime` ASC LIMIT 60"
);
$booked->execute([':now' => $CTX_NOW]);
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
    <div class="adm">

      <div class="adm__head">
        <h1 class="adm__title">Showtimes</h1>
        <span class="adm__meta"><?php echo count($booked); ?> upcoming</span>
      </div>

      <div class="adm-two">

        <section class="card adm-card">
          <div class="card__head"><span class="card__title">Schedule a screening</span></div>
          <div class="card__body">
            <?php if ($films): ?>
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
              <div class="admin-note">The end time is worked out from the film's own duration.</div>
              <button class="btn btn--block" type="submit">Create showtime</button>
            </form>
            <?php else: ?>
            <div class="admin-note">There are no active films to schedule. Add one first.</div>
            <a class="btn btn--block" href="/_admin/films.php">Go to Films</a>
            <?php endif; ?>
          </div>
        </section>

        <section class="card adm-card">
          <div class="card__head">
            <span class="card__title">Booked</span>
            <span class="adm-count"><?php echo count($booked); ?></span>
          </div>
          <div class="card__body card__body--flush">
            <?php
            // Grouped by day, so a double-booking sits directly under the
            // thing it collides with instead of somewhere in a flat list.
            $current_day = null;
            foreach ($booked as $b):
              $day = date('Y-m-d', $b['showtime']);
              if ($day !== $current_day):
                $current_day = $day; ?>
                <div class="adm-row" style="background:var(--surface-2)">
                  <span class="adm-row__text">
                    <span class="adm-row__sub" style="font-weight:600;color:var(--text-2)">
                      <?php echo date('l j F', $b['showtime']); ?>
                    </span>
                  </span>
                </div>
            <?php endif; ?>
            <div class="adm-row">
              <span class="adm-row__thumb">
                <?php if ($b['poster']): ?>
                <img src="/_admin/thumb.php?w=96&f=<?php echo rawurlencode($b['poster']); ?>" alt="" loading="lazy" />
                <?php endif; ?>
              </span>
              <span class="adm-row__text">
                <span class="adm-row__title"><?php echo $e($b['title'] ?: 'Film removed'); ?></span>
                <span class="adm-row__sub">Theatre <?php echo (int)$b['theatre']; ?></span>
              </span>
              <span class="adm-row__when"><?php echo date('g:ia', $b['showtime']); ?></span>
            </div>
            <?php endforeach; ?>
            <?php if (!$booked): ?>
            <div class="adm-empty">Nothing scheduled</div>
            <?php endif; ?>
          </div>
        </section>

      </div>
    </div>
  </main>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
