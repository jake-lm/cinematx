<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — one Film Forecast episode's workshop
//
//  Film picker → live cover mockup → generate → post, the same live-
//  preview shape _admin/instagram.php already uses for the daily carousel:
//  the checklist is a GET form, every load re-renders the cover to reflect
//  whatever's currently checked (or, on a fresh load, the saved selection,
//  or the automatic one-per-night default if nothing's ever been saved —
//  see forecast_resolve_selection() in list/forecast.php), and nothing
//  persists until "Save selection" is explicitly clicked. Generation
//  always reads the *saved* selection, never an unsaved preview — same
//  separation ig_build_images() vs ig_publish() already keeps for the
//  daily post.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
require dirname(__DIR__) . '/v7/_lib.php';
require dirname(__DIR__) . '/list/forecast.php';

$episode_id = (int) ($_GET['id'] ?? 0);
$episode = forecast_get_episode($conn, $episode_id);
if (!$episode || (int) $episode['uid'] !== (int) $admin_user['id']) {
    header('Location: /_admin/forecast.php');
    exit;
}

$byDay = forecast_all_week_films($conn, $episode['week_of']);

// Present only when the checklist form was actually submitted — otherwise
// an unchecked-everything GET (films[] simply absent, since unchecked
// boxes never submit) would be indistinguishable from a fresh page load.
$previewSubmitted = isset($_GET['preview']);
$override = $previewSubmitted ? (is_array($_GET['films'] ?? null) ? $_GET['films'] : []) : null;

$films = forecast_resolve_selection($episode, $byDay, $override);
$currentKeys = array_map('ig_film_key', $films);

$savedRaw  = $episode['selected_films'] ?? null;
$hasSaved  = $savedRaw !== null && $savedRaw !== '';
$savedKeys = $hasSaved ? (json_decode($savedRaw, true) ?: []) : array_map('ig_film_key', array_slice(forecast_round_robin_order($byDay), 0, 9));
$sortedSaved = $savedKeys; sort($sortedSaved);
$sortedCurrent = $currentKeys; sort($sortedCurrent);
$dirty = $previewSubmitted && ($sortedCurrent !== $sortedSaved);

$dir = dirname(__DIR__) . '/uploads/forecast';
$previewPath = $dir . '/' . $episode_id . '-preview.png';
$cover = forecast_build_cover($episode, $conn, $films);
imagepng($cover, $previewPath);
imagedestroy($cover);
$previewUrl = '/uploads/forecast/' . $episode_id . '-preview.png?v=' . filemtime($previewPath);

$genStatus   = forecast_generation_status($episode_id);
$generating  = $genStatus && ($genStatus['status'] ?? '') === 'running';
$genError    = $genStatus && ($genStatus['status'] ?? '') === 'error' ? ($genStatus['error'] ?? 'Generation failed.') : null;

$directVideo = !empty($episode['video_file']);
$hasVideo    = $directVideo || !empty($episode['generated_video']);
$videoUrl    = $directVideo ? '/uploads/forecast/' . $episode['video_file']
             : (!empty($episode['generated_video']) ? '/uploads/forecast/' . $episode['generated_video'] : null);

$posted     = !empty($episode['posted_media_id']);
$configured = defined('IG_ACCESS_TOKEN') && IG_ACCESS_TOKEN
            && defined('IG_BUSINESS_ACCOUNT_ID') && IG_BUSINESS_ACCOUNT_ID;

$e = 'ctx_e';

$ctx_title     = $episode['guest_name'] . ' — Film Forecast — Cinema, TX Admin';
$ctx_active    = 'forecast';
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
        <h1 class="adm__title"><?php echo $e($episode['guest_name']); ?></h1>
        <span class="adm__meta">Week of <?php echo $e(date('l, j F', strtotime($episode['week_of']))); ?></span>
        <a class="btn btn--quiet btn--sm" href="/_admin/forecast.php" style="margin-left:auto">&larr; All episodes</a>
      </div>

      <?php if (isset($_GET['posted'])): ?>
        <div class="alert" style="margin-bottom:var(--s-5)">Posted &mdash; media id <?php echo $e($_GET['posted']); ?></div>
      <?php elseif (isset($_GET['error'])): ?>
        <div class="alert" style="margin-bottom:var(--s-5)"><?php echo $e($_GET['error']); ?></div>
      <?php elseif ($genError): ?>
        <div class="alert" style="margin-bottom:var(--s-5)">Generation failed: <?php echo $e(mb_strimwidth($genError, 0, 300, '…')); ?></div>
      <?php endif; ?>

      <div class="adm-two" style="grid-template-columns: minmax(320px, 430px) minmax(0, 1fr);">

        <div>
          <div class="ig-mock" style="margin-bottom:var(--s-5)">
            <?php if ($hasVideo && !$dirty): ?>
            <video class="ig-mock__image" src="<?php echo $e($videoUrl . '?v=' . @filemtime($dir . '/' . basename($videoUrl))); ?>" controls playsinline></video>
            <?php else: ?>
            <img class="ig-mock__image" src="<?php echo $e($previewUrl); ?>" alt="Cover mockup" />
            <?php endif; ?>
          </div>

          <section class="card adm-card" data-forecast-generate>
            <div class="card__head"><span class="card__title">Video</span></div>
            <div class="card__body">
              <?php if ($directVideo): ?>
                <div class="admin-note">A finished video was uploaded directly &mdash; nothing to generate. It posts as-is.</div>
              <?php elseif (empty($episode['audio_file'])): ?>
                <div class="admin-note">Upload an audio file (or a finished video) on the main episode form before generating.</div>
              <?php elseif ($generating): ?>
                <div class="admin-note" style="margin:0 0 var(--s-2)" data-forecast-progress-label>Generating&hellip; <?php echo (int) ($genStatus['percent'] ?? 0); ?>%</div>
                <progress class="admin-progress" data-forecast-progress data-episode-id="<?php echo $episode_id; ?>" value="<?php echo (int) ($genStatus['percent'] ?? 0); ?>" max="100"></progress>
              <?php else: ?>
                <div class="admin-note" style="margin:0 0 var(--s-3)">
                  <?php echo $hasVideo ? 'Regenerating replaces the current video.' : 'Builds the cover from the showcase below and assembles the Reel.'; ?>
                  Uses exactly what's checked right now &mdash; saves it as part of generating.
                </div>
                <form action="/_admin/forecast_generate.php" method="post">
                  <?php echo admin_csrf_field(); ?>
                  <input type="hidden" name="episode_id" value="<?php echo $episode_id; ?>">
                  <?php foreach ($currentKeys as $k): ?>
                  <input type="hidden" name="films[]" value="<?php echo $e($k); ?>">
                  <?php endforeach; ?>
                  <button class="btn btn--block" type="submit"><?php echo $hasVideo ? 'Regenerate video' : 'Generate video'; ?></button>
                </form>
              <?php endif; ?>
            </div>
          </section>

          <section class="card adm-card" style="margin-top:var(--s-5)">
            <div class="card__head"><span class="card__title">Publish</span></div>
            <div class="card__body">
              <?php if ($posted): ?>
                <div class="admin-note">Already posted &mdash; media id <?php echo $e($episode['posted_media_id']); ?>.</div>
                <button class="btn btn--block" type="button" disabled>Posted</button>
              <?php elseif (!$configured): ?>
                <div class="admin-note"><code>IG_ACCESS_TOKEN</code> / <code>IG_BUSINESS_ACCOUNT_ID</code> aren't set.</div>
                <button class="btn btn--block" type="button" disabled>Not configured</button>
              <?php elseif (!$hasVideo): ?>
                <div class="admin-note">Nothing to post yet &mdash; upload a video or generate one first.</div>
                <button class="btn btn--block" type="button" disabled>No video yet</button>
              <?php else: ?>
                <form action="/_admin/forecast_post.php" method="post"
                      onsubmit="return confirm('Publish this Reel to the live Instagram account now? This cannot be undone.');">
                  <?php echo admin_csrf_field(); ?>
                  <input type="hidden" name="episode_id" value="<?php echo $episode_id; ?>">
                  <button class="btn btn--block" type="submit">Post to Instagram</button>
                </form>
              <?php endif; ?>
            </div>
          </section>
        </div>

        <section class="card adm-card">
          <div class="card__head">
            <span class="card__title">Showcase</span>
            <span class="adm-count"><?php echo count($films); ?> selected</span>
          </div>
          <div class="card__body">
            <div class="admin-note" style="margin:0 0 var(--s-3)">
              Defaults to one film per night. Check or uncheck to pick your own &mdash; "Update preview" shows the change above without saving it.
            </div>
            <form method="get">
              <input type="hidden" name="id" value="<?php echo $episode_id; ?>">
              <input type="hidden" name="preview" value="1">
              <div class="card__body card__body--flush" style="max-height:640px; overflow-y:auto; margin:0 calc(-1 * var(--s-4)); padding:0 var(--s-4)">
                <?php foreach ($byDay as $day => $dayFilms): ?>
                <div class="adm-row" style="background:var(--surface-2)">
                  <span class="adm-row__text">
                    <span class="adm-row__sub" style="font-weight:600;color:var(--text-2)"><?php echo $e(date('l, j F', strtotime($day))); ?></span>
                  </span>
                </div>
                <?php foreach ($dayFilms as $f):
                  $key = ig_film_key($f);
                  $checked = in_array($key, $currentKeys, true);
                ?>
                <label class="adm-row adm-row--check">
                  <input type="checkbox" name="films[]" value="<?php echo $e($key); ?>"<?php echo $checked ? ' checked' : ''; ?>>
                  <span class="adm-row__text">
                    <span class="adm-row__title"><?php echo $e(mb_strtoupper($f['display_title'])); ?></span>
                    <span class="adm-row__sub"><?php echo $e($f['venue']); ?></span>
                  </span>
                  <span class="adm-row__when"><?php echo $e(date('g:ia', $f['timestamp'])); ?></span>
                </label>
                <?php endforeach; ?>
                <?php endforeach; ?>
                <?php if (!$byDay): ?>
                <div class="adm-empty">Nothing scraped for this week</div>
                <?php endif; ?>
              </div>
              <div style="margin-top:var(--s-4)">
                <button class="btn btn--quiet btn--sm" type="submit">Update preview</button>
              </div>
            </form>

            <?php if ($dirty): ?>
            <form action="/_admin/forecast_selection_save.php" method="post" style="margin-top:var(--s-3)">
              <?php echo admin_csrf_field(); ?>
              <input type="hidden" name="episode_id" value="<?php echo $episode_id; ?>">
              <?php foreach ($currentKeys as $k): ?>
              <input type="hidden" name="films[]" value="<?php echo $e($k); ?>">
              <?php endforeach; ?>
              <button class="btn btn--block" type="submit">Save selection</button>
            </form>
            <?php endif; ?>
          </div>
        </section>

      </div>
    </div>
  </main>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
