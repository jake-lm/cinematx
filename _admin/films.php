<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — Films
//
//  Upload and the programmer's note are one form now — a note is optional,
//  and when given it's attached to the film just uploaded (upload.php calls
//  lastInsertId()) rather than asking which film it's about. The table below
//  is every active film with a one-click deactivate, so the whole lifecycle
//  of a film — add it, note it, retire it — lives on one page.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
require dirname(__DIR__) . '/v7/_lib.php';

$films = $conn->query("SELECT `id`, `title`, `director`, `dur`, `active` FROM `films` WHERE `active` = 1 ORDER BY `id` DESC")
              ->fetchAll(PDO::FETCH_ASSOC);

function admin_fmt_dur($seconds) {
    $seconds = (int)$seconds;
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
    <div class="reading" style="max-width:none;">

      <div class="reading__kicker"><span>Signed in as <?php echo $e($admin_user['name'] ?: 'admin'); ?></span></div>
      <h1 class="reading__title">Films</h1>
      <div class="reading__by"><?php echo count($films); ?> active</div>

      <div class="admin-grid">

        <section class="card admin-card">
          <div class="card__head"><span class="card__n">01</span><span class="card__title">Add a film</span></div>
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
              <div class="field"><label class="field__label" for="u-poster">Poster (.png)</label>
                <input class="field__input" id="u-poster" name="poster" type="file" accept="image/png" required /></div>

              <progress class="admin-progress" id="upload-progress" value="0" max="100" hidden></progress>
              <div class="admin-note" id="upload-note"></div>

              <input class="btn btn--block" id="upload-button" type="submit" value="Upload" />
            </form>
          </div>
        </section>

      </div>

      <div class="admin-list">
        <div class="admin-list__head">
          <span>Title</span><span>Director</span><span>Duration</span><span></span>
        </div>
        <?php foreach ($films as $f): ?>
        <div class="admin-list__row">
          <span class="admin-list__title"><?php echo $e($f['title']); ?></span>
          <span class="admin-list__meta"><?php echo $e($f['director']); ?></span>
          <span class="admin-list__meta"><?php echo $e(admin_fmt_dur($f['dur'])); ?></span>
          <form action="/_admin/delete.php" method="post"
                onsubmit="return confirm('Deactivate this film? The video and poster are removed from disk and cannot be recovered.');">
            <?php echo admin_csrf_field(); ?>
            <input type="hidden" name="film_id" value="<?php echo (int)$f['id']; ?>" />
            <button class="btn btn--quiet btn--sm" type="submit">Deactivate</button>
          </form>
        </div>
        <?php endforeach; ?>
        <?php if (!$films): ?>
        <div class="admin-note">No active films yet.</div>
        <?php endif; ?>
      </div>

    </div>
  </main>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
