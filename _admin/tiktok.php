<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — TikTok download package
//
//  Posting is manual for now: TikTok's API won't allow unattended posting
//  (see the audit / consent requirements in its Content Posting docs), so
//  this page just gets everything ready to upload from the TikTok app —
//  the same slides Instagram gets, plus recent Film Forecast videos, each
//  with a caption and hashtags to paste.
//
//  Deliberately separate from the Instagram page and it never posts
//  anywhere. The daily slides are rendered through the exact same
//  ig_build_images() call the cron uses, from the *saved* composition, so
//  they match what Instagram publishes — but they're written to their own
//  tiktok-<date>-N.png files so this page can never overwrite the ig-*.png
//  files the Instagram page and cron work from.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
require dirname(__DIR__) . '/v7/_lib.php';
require dirname(__DIR__) . '/list/instagram.php';
require dirname(__DIR__) . '/list/forecast.php';

const TIKTOK_DAILY_TAGS    = '#austin #atx #austintx #austinfilm #indiefilm #movies';
const TIKTOK_FORECAST_TAGS = '#filmforecast #podcast #austin #atx #film';
const TIKTOK_FORECAST_LIMIT = 6;

$now = ig_admin_target_date();
$e   = 'ctx_e';

// ── Daily carousel ───────────────────────────────────────────────────────
$films   = ig_today_films($conn, $now);
$saved   = ig_compose_read($now);
$images  = ig_build_images($films, $now, $saved);

$dir     = dirname(__DIR__) . '/uploads/social';
if (!is_dir($dir)) mkdir($dir, 0775, true);
$dateKey = date('Y-m-d', $now);
foreach (glob($dir . '/tiktok-' . $dateKey . '-*.png') as $stale) unlink($stale);

$slides = [];
foreach ($images as $i => $im) {
    $name = 'tiktok-' . $dateKey . '-' . ($i + 1) . '.png';
    imagepng($im, $dir . '/' . $name);
    imagedestroy($im);
    $slides[] = ['path' => $dir . '/' . $name, 'url' => '/uploads/social/' . $name, 'name' => $name];
}
foreach (glob($dir . '/tiktok-*.png') as $old) {
    if (filemtime($old) < time() - 7 * 86400) unlink($old);
}

$dailyCaption = ig_caption($films, $now) . "\n\n" . TIKTOK_DAILY_TAGS;

// ── Film Forecast videos ─────────────────────────────────────────────────
$episodes = [];
foreach (forecast_list_episodes($conn) as $ep) {
    $file = !empty($ep['video_file']) ? $ep['video_file'] : ($ep['generated_video'] ?? '');
    if ($file === '' || $file === null) continue;
    $ep['_video_url']  = '/uploads/forecast/' . $file;
    $ep['_video_ext']  = pathinfo($file, PATHINFO_EXTENSION);
    $ep['_tiktok_cap'] = forecast_caption($ep) . "\n\n" . TIKTOK_FORECAST_TAGS;
    $episodes[] = $ep;
    if (count($episodes) >= TIKTOK_FORECAST_LIMIT) break;
}

$ctx_title     = 'TikTok — Cinema, TX Admin';
$ctx_active    = 'tiktok';
$ctx_admin_nav = true;
$ctx_shell     = 'admin-shell';
$ctx_scroll    = true;
$ctx_video     = false;
require dirname(__DIR__) . '/v7/_head.php';
require dirname(__DIR__) . '/v7/_chrome.php';
?>

  <style>
    .tt-note { color: var(--text-2); font-size: var(--t-xs, 0.85rem); line-height: 1.55; margin: 0 0 var(--s-5); max-width: 62ch; }
    .tt-actions { display: flex; flex-wrap: wrap; gap: var(--s-3); align-items: center; margin-bottom: var(--s-5); }
    .tt-slides { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: var(--s-4); margin-bottom: var(--s-5); }
    .tt-slide { display: grid; gap: var(--s-2); }
    .tt-slide img { display: block; width: 100%; height: auto; aspect-ratio: 4 / 5; object-fit: cover; border: 1px solid var(--line-2); border-radius: 4px; background: var(--surface-2); }
    .tt-slide__row { display: flex; justify-content: space-between; align-items: center; font-size: var(--t-3xs, 0.72rem); color: var(--text-3); }
    .tt-slide__row a { color: var(--red); text-decoration: none; }
    .tt-caption { width: 100%; box-sizing: border-box; min-height: 9rem; font: inherit; font-size: var(--t-xs, 0.85rem); line-height: 1.5; resize: vertical; }
    .tt-caption-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--s-2); }
    .tt-eps { display: grid; gap: var(--s-6); }
    .tt-ep { display: grid; grid-template-columns: 150px minmax(0, 1fr); gap: var(--s-5); padding-bottom: var(--s-6); border-bottom: 1px solid var(--line); }
    .tt-ep:last-child { border-bottom: 0; padding-bottom: 0; }
    .tt-ep video { display: block; width: 100%; aspect-ratio: 9 / 16; object-fit: cover; background: #000; border-radius: 4px; }
    .tt-ep__head { display: flex; flex-wrap: wrap; gap: var(--s-3); align-items: baseline; margin-bottom: var(--s-3); }
    .tt-ep__title { font-weight: 600; }
    .tt-ep__meta { color: var(--text-3); font-size: var(--t-3xs, 0.72rem); }
    .tt-empty { color: var(--text-3); font-size: var(--t-xs, 0.85rem); margin: 0; }
    @media (max-width: 560px) { .tt-ep { grid-template-columns: 1fr; } .tt-ep video { max-width: 200px; } }
  </style>

  <main class="canvas">
    <div class="adm">

      <div class="adm__head">
        <h1 class="adm__title">TikTok</h1>
        <span class="adm__meta"><?php echo date('l, j F', $now); ?></span>
        <span class="post-status status-draft">Manual</span>
      </div>

      <p class="tt-note">
        Nothing here posts by itself. Download the files, then upload them from the TikTok app and paste the caption.
        The slides are rendered from the same saved composition Instagram uses, so change those on the
        <a href="/_admin/instagram.php" style="color:var(--red)">Instagram page</a> and reload this one.
      </p>

      <div style="display:grid; gap:var(--s-5);">

        <section class="card adm-card">
          <div class="card__head">
            <span class="card__title">Daily carousel</span>
            <span class="adm-count"><?php echo count($slides); ?> slide<?php echo count($slides) === 1 ? '' : 's'; ?></span>
          </div>
          <div class="card__body">
            <?php if (empty($films)): ?>
              <p class="tt-empty">Nothing scraped for <?php echo date('l, j F', $now); ?> yet, so there are no screenings to show.</p>
            <?php endif; ?>

            <div class="tt-actions">
              <a class="btn btn--sm" href="/_admin/tiktok_download.php?date=<?php echo $e($dateKey); ?>">Download all (.zip)</a>
              <span class="tt-slide__row">Slides are 4:5, so TikTok will show them with bars top and bottom.</span>
            </div>

            <div class="tt-slides">
              <?php foreach ($slides as $i => $s): ?>
              <div class="tt-slide">
                <img src="<?php echo $e($s['url'] . '?v=' . @filemtime($s['path'])); ?>" alt="Slide <?php echo $i + 1; ?>" loading="lazy" />
                <div class="tt-slide__row">
                  <span>Slide <?php echo $i + 1; ?></span>
                  <a href="<?php echo $e($s['url'] . '?v=' . @filemtime($s['path'])); ?>" download="cinematx-<?php echo $e($dateKey); ?>-<?php echo sprintf('%02d', $i + 1); ?>.png">Download</a>
                </div>
              </div>
              <?php endforeach; ?>
            </div>

            <div class="tt-caption-bar">
              <span class="field__label" style="margin:0">Caption</span>
              <button class="btn btn--quiet btn--sm" type="button" data-copy="#tt-daily-caption">Copy caption</button>
            </div>
            <textarea class="field__input tt-caption" id="tt-daily-caption" readonly><?php echo $e($dailyCaption); ?></textarea>
          </div>
        </section>

        <section class="card adm-card">
          <div class="card__head">
            <span class="card__title">Film Forecast videos</span>
            <span class="adm-count"><?php echo count($episodes); ?></span>
            <a class="card__more" href="/_admin/forecast.php">Manage &rarr;</a>
          </div>
          <div class="card__body">
            <?php if (empty($episodes)): ?>
              <p class="tt-empty">No episode has a finished video yet. Generate or upload one on the Film Forecast page and it will show up here.</p>
            <?php else: ?>
            <div class="tt-eps">
              <?php foreach ($episodes as $ep): $capId = 'tt-ep-cap-' . (int) $ep['id']; ?>
              <div class="tt-ep">
                <video src="<?php echo $e($ep['_video_url']); ?>#t=0.1" controls preload="metadata" playsinline></video>
                <div>
                  <div class="tt-ep__head">
                    <span class="tt-ep__title">Week of <?php echo $e(date('F j', strtotime($ep['week_of']))); ?></span>
                    <span class="tt-ep__meta"><?php echo $e($ep['guest_name']); ?></span>
                  </div>
                  <div class="tt-actions" style="margin-bottom:var(--s-4)">
                    <a class="btn btn--sm" href="<?php echo $e($ep['_video_url']); ?>" download="film-forecast-<?php echo $e($ep['week_of']); ?>.<?php echo $e($ep['_video_ext']); ?>">Download video</a>
                    <a class="btn btn--quiet btn--sm" href="/_admin/forecast_episode.php?id=<?php echo (int) $ep['id']; ?>">Open episode</a>
                  </div>
                  <div class="tt-caption-bar">
                    <span class="field__label" style="margin:0">Caption</span>
                    <button class="btn btn--quiet btn--sm" type="button" data-copy="#<?php echo $e($capId); ?>">Copy caption</button>
                  </div>
                  <textarea class="field__input tt-caption" id="<?php echo $e($capId); ?>" readonly style="min-height:7rem"><?php echo $e($ep['_tiktok_cap']); ?></textarea>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
        </section>

      </div>
    </div>
  </main>

  <script>
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var box = document.querySelector(btn.getAttribute('data-copy'));
        if (!box) return;
        var done = function () {
          var was = btn.textContent;
          btn.textContent = 'Copied';
          setTimeout(function () { btn.textContent = was; }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(box.value).then(done, function () { box.select(); document.execCommand('copy'); done(); });
        } else {
          box.select();
          document.execCommand('copy');
          done();
        }
      });
    });
  </script>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
