<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — Films
//
//  The whole life of a film on one page. Upload and the programmer's note are
//  a single submit — a note is optional, and when given it attaches to the
//  film just uploaded (upload.php uses lastInsertId()) rather than asking
//  which film it is about. The list beside it is every active film, each row
//  able to retire itself.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
require dirname(__DIR__) . '/v7/_lib.php';

$films = $conn->query(
  "SELECT f.`id`, f.`title`, f.`director`, f.`dur`, f.`poster`,
          (SELECT COUNT(*) FROM `notes` n WHERE n.`f_id` = f.`id`) AS `notes`
   FROM `films` f WHERE f.`active` = 1 ORDER BY f.`id` DESC"
)->fetchAll(PDO::FETCH_ASSOC);

/** Seconds as h:mm:ss, or m:ss under an hour. */
function admin_fmt_dur($seconds) {
    $seconds = (int)$seconds;
    if ($seconds <= 0) return '—';
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
}

$e = 'ctx_e';

$ctx_title     = 'Films — Cinema, TX Admin';
$ctx_active    = 'films';
$ctx_admin_nav = true;
$ctx_shell     = 'admin-shell';
$ctx_scroll    = true;
$ctx_video     = false;
$ctx_scripts   = '<script src="/js/admin.js?v=' . filemtime(dirname(__DIR__) . '/js/admin.js') . '" defer></script>';
require dirname(__DIR__) . '/v7/_head.php';
require dirname(__DIR__) . '/v7/_chrome.php';
?>

  <main class="canvas">
    <div class="adm">

      <div class="adm__head">
        <h1 class="adm__title">Films</h1>
        <span class="adm__meta"><?php echo count($films); ?> active</span>
      </div>

      <div class="adm-two">

        <section class="card adm-card">
          <div class="card__head"><span class="card__title">Add a film</span></div>
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
              <div class="field"><label class="field__label" for="u-note">Programmer's note <span class="admin-tz">optional</span></label>
                <textarea class="field__input admin-textarea" id="u-note" name="note" rows="4"></textarea></div>
              <div class="field"><label class="field__label" for="u-film">Video file (.mp4)</label>
                <input class="field__input" id="u-film" name="film" type="file" accept="video/mp4" required /></div>
              <div class="field"><label class="field__label" for="u-poster">Poster</label>
                <input class="field__input" id="u-poster" name="poster" type="file" accept="image/*" required /></div>

              <progress class="admin-progress" id="upload-progress" value="0" max="100" hidden></progress>
              <div class="admin-note" id="upload-note"></div>

              <input class="btn btn--block" id="upload-button" type="submit" value="Upload" />
            </form>
          </div>
        </section>

        <section class="card adm-card">
          <div class="card__head">
            <span class="card__title">In the library</span>
            <span class="adm-count"><?php echo count($films); ?></span>
          </div>
          <div class="card__body card__body--flush">
            <?php foreach ($films as $f): ?>
            <div class="adm-row">
              <span class="adm-row__thumb">
                <?php if ($f['poster']): ?>
                <img src="/_admin/thumb.php?w=96&f=<?php echo rawurlencode($f['poster']); ?>"
                     alt="" loading="lazy" />
                <?php endif; ?>
              </span>
              <span class="adm-row__text">
                <span class="adm-row__title"><?php echo $e($f['title']); ?></span>
                <span class="adm-row__sub">
                  <?php echo $e($f['director'] ?: 'Director unknown'); ?>
                  &middot; <?php echo admin_fmt_dur($f['dur']); ?>
                  <?php if ((int)$f['notes']): ?>&middot; <?php echo (int)$f['notes']; ?> note<?php echo (int)$f['notes'] === 1 ? '' : 's'; ?><?php endif; ?>
                </span>
              </span>
              <span class="adm-row__end">
                <form action="/_admin/delete.php" method="post"
                      onsubmit="return confirm('Deactivate <?php echo $e(addslashes($f['title'])); ?>? The video and poster are removed from disk and cannot be recovered.');">
                  <?php echo admin_csrf_field(); ?>
                  <input type="hidden" name="film_id" value="<?php echo (int)$f['id']; ?>" />
                  <button class="btn btn--sm admin-danger" type="submit">Deactivate</button>
                </form>
              </span>
            </div>
            <?php endforeach; ?>
            <?php if (!$films): ?>
            <div class="adm-empty">No active films yet</div>
            <?php endif; ?>
          </div>
        </section>

      </div>
    </div>
  </main>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
