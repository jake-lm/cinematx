<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — screenings at venues nothing scrapes
//
//  Writes into the same `events` table dashboard/event.php's member flow
//  does — same table, same downstream rendering (/list, /events/?id=), the
//  only difference is who's adding it and that it publishes immediately.
//  list/fetch_screenings.php tells the two apart at read time by checking
//  whether the submitting uid belongs to an admin, which is also what lets
//  an admin-added screening into the Instagram admin panel's own lineup
//  (list/instagram.php's ig_arthouse_films()) while a real member
//  submission stays out until public engagement is actually on.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
require dirname(__DIR__) . '/v7/_lib.php';

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $conn->prepare("SELECT * FROM `events` WHERE id = :id AND uid = :uid");
    $stmt->execute([':id' => (int) $_GET['edit'], ':uid' => $admin_user['id']]);
    $editing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$upcoming = $conn->prepare(
    "SELECT id, title, poster, screentime, location, address
     FROM `events` WHERE uid = :uid AND screentime > :now
     ORDER BY screentime ASC LIMIT 60"
);
$upcoming->execute([':uid' => $admin_user['id'], ':now' => $CTX_NOW]);
$upcoming = $upcoming->fetchAll(PDO::FETCH_ASSOC);

$e  = 'ctx_e';
$tz = new DateTimeZone('America/Chicago');

$ctx_title     = 'Screenings — Cinema, TX Admin';
$ctx_active    = 'events';
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
        <h1 class="adm__title">Screenings</h1>
        <span class="adm__meta"><?php echo count($upcoming); ?> upcoming</span>
      </div>

      <?php if (isset($_GET['error'])): ?>
        <div class="alert" style="margin-bottom:var(--s-5)"><?php echo $e($_GET['error']); ?></div>
      <?php endif; ?>

      <div class="adm-two">

        <section class="card adm-card">
          <div class="card__head">
            <span class="card__title"><?php echo $editing ? 'Edit screening' : 'Add a screening'; ?></span>
          </div>
          <div class="card__body">
            <form action="/_admin/event_save.php" method="post" enctype="multipart/form-data">
              <?php echo admin_csrf_field(); ?>
              <?php if ($editing): ?>
              <input type="hidden" name="event_id" value="<?php echo (int) $editing['id']; ?>">
              <?php endif; ?>
              <div class="field"><label class="field__label" for="ev-title">Title</label>
                <input class="field__input" id="ev-title" name="title" type="text" required
                       value="<?php echo $e($editing['title'] ?? ''); ?>"></div>
              <div class="field"><label class="field__label" for="ev-venue">Venue</label>
                <input class="field__input" id="ev-venue" name="location" type="text" required
                       placeholder="e.g. The Marchesa"
                       value="<?php echo $e($editing['location'] ?? ''); ?>"></div>
              <div class="field"><label class="field__label" for="ev-address">Address <span class="admin-tz">optional</span></label>
                <input class="field__input" id="ev-address" name="address" type="text"
                       value="<?php echo $e($editing['address'] ?? ''); ?>"></div>
              <div class="field"><label class="field__label" for="ev-synopsis">Synopsis <span class="admin-tz">optional</span></label>
                <textarea class="field__input admin-textarea" id="ev-synopsis" name="synopsis" rows="4"><?php echo $e($editing['synopsis'] ?? ''); ?></textarea></div>
              <div class="field"><label class="field__label" for="ev-when">Date &amp; time <span class="admin-tz">America/Chicago</span></label>
                <input class="field__input" id="ev-when" name="screentime" type="datetime-local" required
                       value="<?php echo $editing ? $e((new DateTime('@' . $editing['screentime']))->setTimezone($tz)->format('Y-m-d\TH:i')) : ''; ?>"></div>
              <div class="field"><label class="field__label" for="ev-poster">Poster <span class="admin-tz">optional</span></label>
                <input class="field__input" id="ev-poster" name="poster" type="file" accept="image/jpeg,image/png,image/webp,image/gif">
                <?php if ($editing && !empty($editing['poster'])): ?>
                <div class="admin-note" style="margin:var(--s-2) 0 0">Leave blank to keep the current poster.</div>
                <?php endif; ?>
              </div>
              <button class="btn btn--block" type="submit"><?php echo $editing ? 'Save changes' : 'Add screening'; ?></button>
            </form>
            <?php if ($editing): ?>
            <div style="margin-top:var(--s-3)">
              <a class="btn btn--quiet btn--sm" href="/_admin/events.php">Cancel edit</a>
            </div>
            <?php endif; ?>
          </div>
        </section>

        <section class="card adm-card">
          <div class="card__head">
            <span class="card__title">Upcoming</span>
            <span class="adm-count"><?php echo count($upcoming); ?></span>
          </div>
          <div class="card__body card__body--flush">
            <?php
            $current_day = null;
            foreach ($upcoming as $ev):
              $day = date('Y-m-d', $ev['screentime']);
              if ($day !== $current_day):
                $current_day = $day; ?>
                <div class="adm-row" style="background:var(--surface-2)">
                  <span class="adm-row__text">
                    <span class="adm-row__sub" style="font-weight:600;color:var(--text-2)">
                      <?php echo date('l j F', $ev['screentime']); ?>
                    </span>
                  </span>
                </div>
            <?php endif; ?>
            <div class="adm-row">
              <span class="adm-row__thumb">
                <?php if ($ev['poster']): ?>
                <img src="/uploads/events/<?php echo $e($ev['poster']); ?>" alt="" loading="lazy" />
                <?php endif; ?>
              </span>
              <span class="adm-row__text">
                <span class="adm-row__title"><?php echo $e($ev['title']); ?></span>
                <span class="adm-row__sub"><?php echo $e($ev['location']); ?></span>
              </span>
              <span class="adm-row__when"><?php echo date('g:ia', $ev['screentime']); ?></span>
              <span class="adm-row__end">
                <a class="event-edit" href="/_admin/events.php?edit=<?php echo (int) $ev['id']; ?>" title="Edit">
                  <i class="fa-solid fa-pen"></i>
                </a>
                <form action="/_admin/event_delete.php" method="post"
                      onsubmit="return confirm('Delete this screening?');">
                  <?php echo admin_csrf_field(); ?>
                  <input type="hidden" name="event_id" value="<?php echo (int) $ev['id']; ?>">
                  <button class="event-delete" type="submit" title="Delete"><i class="fa-solid fa-trash"></i></button>
                </form>
              </span>
            </div>
            <?php endforeach; ?>
            <?php if (!$upcoming): ?>
            <div class="adm-empty">Nothing added yet</div>
            <?php endif; ?>
          </div>
        </section>

      </div>
    </div>
  </main>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
