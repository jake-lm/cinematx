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
$savedKeys = $hasSaved ? (json_decode($savedRaw, true) ?: []) : array_map('ig_film_key', forecast_flat_week_films($byDay));
$sortedSaved = $savedKeys; sort($sortedSaved);
$sortedCurrent = $currentKeys; sort($sortedCurrent);
$dirty = $previewSubmitted && ($sortedCurrent !== $sortedSaved);

$totalThisWeek = array_sum(array_map('count', $byDay));

$dir = dirname(__DIR__) . '/uploads/forecast';
$previewPath = $dir . '/' . $episode_id . '-preview.png';
$cover = forecast_build_cover($episode, $conn, $films, $totalThisWeek);
imagepng($cover, $previewPath);
imagedestroy($cover);
$previewUrl = '/uploads/forecast/' . $episode_id . '-preview.png?v=' . filemtime($previewPath);

// Timeline — rebuilt fresh on every load, same reasoning the mockup
// preview above already follows: cheap GD renders, and a stale cached
// image here would be exactly the kind of "why isn't this showing my
// changes" bug already hit once this session with the video/mockup
// toggle.
$episodeDuration = (float) ($episode['duration_seconds'] ?? 0);
$audioUrl = !empty($episode['audio_file']) ? '/uploads/forecast/' . rawurlencode($episode['audio_file']) : null;
$chapters = forecast_resolve_timeline($films, $byDay, $episode['week_of'], $episode['chapters'] ?? null, $episodeDuration);
$weekDays = forecast_week_days($episode['week_of']);

// The preshow storyboard frame — no episode data in it, so no reason to
// rebuild it on every load the way the intro/chapter cards are; render it
// once and reuse.
$preshowStoryboardPath = $dir . '/' . $episode_id . '-storyboard-preshow.png';
if (!file_exists($preshowStoryboardPath)) {
    $preshowStoryboardImg = forecast_build_preshow_card();
    imagepng($preshowStoryboardImg, $preshowStoryboardPath);
    imagedestroy($preshowStoryboardImg);
}
$preshowStoryboardUrl = '/uploads/forecast/' . $episode_id . '-storyboard-preshow.png?v=' . filemtime($preshowStoryboardPath);

// The intro/wrap-up storyboard frame is just forecast_build_cover()
// itself — the base state this whole episode opens and closes on — not
// a separate card design.
$introStoryboardPath = $dir . '/' . $episode_id . '-storyboard-intro.png';
$introStoryboardImg = forecast_build_cover($episode + ['duration_seconds' => $episodeDuration], $conn, $films, $totalThisWeek);
imagepng($introStoryboardImg, $introStoryboardPath);
imagedestroy($introStoryboardImg);
$introStoryboardUrl = '/uploads/forecast/' . $episode_id . '-storyboard-intro.png?v=' . filemtime($introStoryboardPath);

// Every selected film plus all 7 days, not just whatever's currently
// placed on the timeline — the bank needs art for everything available
// to drag in, regardless of placement. Keyed "film|<key>"/"day|<ymd>" so
// a film key and a Y-m-d string can never collide.
$segmentImages = [];
foreach ($films as $film) {
    $key = ig_film_key($film);
    $slug = md5($key);
    $path = $dir . '/' . $episode_id . '-storyboard-' . $slug . '.png';
    $cardImg = forecast_build_chapter_card($film, $episode);
    imagepng($cardImg, $path);
    imagedestroy($cardImg);
    $segmentImages['film|' . $key] = '/uploads/forecast/' . $episode_id . '-storyboard-' . $slug . '.png?v=' . filemtime($path);
}
foreach ($weekDays as $ymd) {
    $slug = md5($ymd);
    $path = $dir . '/' . $episode_id . '-storyboard-day-' . $slug . '.png';
    $cardImg = forecast_build_day_card($ymd, forecast_films_for_day($byDay, $ymd), $episode);
    imagepng($cardImg, $path);
    imagedestroy($cardImg);
    $segmentImages['day|' . $ymd] = '/uploads/forecast/' . $episode_id . '-storyboard-day-' . $slug . '.png?v=' . filemtime($path);
}

$genStatus   = forecast_generation_status($episode_id);
$generating  = $genStatus && ($genStatus['status'] ?? '') === 'running';
$genError    = $genStatus && ($genStatus['status'] ?? '') === 'error' ? ($genStatus['error'] ?? 'Generation failed.') : null;

// The manual-fallback exports — same running/error shape as the real
// video, on their own progress files (see forecast_progress_path()'s
// $kind) so none of the three can block or clobber another's status.
$packageStatus    = forecast_generation_status($episode_id, 'package');
$packageRunning   = $packageStatus && ($packageStatus['status'] ?? '') === 'running';
$packageError     = $packageStatus && ($packageStatus['status'] ?? '') === 'error' ? ($packageStatus['error'] ?? 'Package generation failed.') : null;
$packageUrl       = !empty($episode['package_file']) ? '/uploads/forecast/' . $episode['package_file'] : null;

$waveformStatus   = forecast_generation_status($episode_id, 'waveform');
$waveformRunning  = $waveformStatus && ($waveformStatus['status'] ?? '') === 'running';
$waveformError    = $waveformStatus && ($waveformStatus['status'] ?? '') === 'error' ? ($waveformStatus['error'] ?? 'Waveform generation failed.') : null;
$waveformUrl      = !empty($episode['waveform_file']) ? '/uploads/forecast/' . $episode['waveform_file'] : null;

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
            <?php if ($hasVideo && !$dirty && !$previewSubmitted): ?>
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
                <form action="/_admin/forecast_generate.php" method="post" data-forecast-generate-form>
                  <?php echo admin_csrf_field(); ?>
                  <input type="hidden" name="episode_id" value="<?php echo $episode_id; ?>">
                  <?php foreach ($currentKeys as $k): ?>
                  <input type="hidden" name="films[]" value="<?php echo $e($k); ?>">
                  <?php endforeach; ?>
                  <input type="hidden" name="chapters" data-forecast-chapters-input>
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
              <?php else: ?>
                <?php if (!$configured): ?>
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

                <form action="/_admin/forecast_mark_posted.php" method="post" style="margin-top:var(--s-4)"
                      onsubmit="return confirm('Mark this episode as posted without actually posting it? Only do this if you already posted it yourself — it unlocks the podcast RSS feed for this episode.');">
                  <?php echo admin_csrf_field(); ?>
                  <input type="hidden" name="episode_id" value="<?php echo $episode_id; ?>">
                  <div class="admin-note" style="margin:0 0 var(--s-2)">Already posted it yourself? This unlocks the podcast RSS feed without posting again.</div>
                  <div class="field"><label class="field__label" for="fc-posted-ref">Instagram link or media id <span class="admin-tz">optional</span></label>
                    <input class="field__input" id="fc-posted-ref" name="reference" type="text" placeholder="https://instagram.com/reel/...">
                  </div>
                  <button class="btn btn--quiet btn--block" type="submit">Mark as already posted</button>
                </form>
              <?php endif; ?>
            </div>
          </section>

          <section class="card adm-card" style="margin-top:var(--s-5)">
            <div class="card__head"><span class="card__title">Manual export</span></div>
            <div class="card__body">
              <div class="admin-note" style="margin:0 0 var(--s-3)">
                For days the automated video can't be trusted &mdash; every panel this week as PNGs, plus a transparent waveform clip, to hand-assemble in an editor. Once you've got a final cut, upload it below.
              </div>

              <div class="admin-note" style="margin:0 0 var(--s-1);font-weight:600;color:var(--text-2)">Final video</div>
              <?php if (!empty($episode['video_file'])): ?>
              <div class="admin-note" style="margin:0 0 var(--s-2)">Current: <?php echo $e($episode['video_file']); ?></div>
              <?php endif; ?>
              <div data-forecast-video-upload
                   data-episode-id="<?php echo $episode_id; ?>"
                   data-csrf="<?php echo $e($_SESSION['admin_csrf']); ?>"
                   style="margin-bottom:var(--s-4)">
                <input type="file" class="field__input" accept="video/mp4,video/quicktime" data-video-upload-input style="margin-bottom:var(--s-2)">
                <progress class="admin-progress" data-video-upload-progress value="0" max="100" hidden></progress>
                <div class="admin-note" style="margin:var(--s-2) 0" data-video-upload-status></div>
                <button class="btn btn--quiet btn--block" type="button" data-video-upload-btn disabled>Upload video</button>
              </div>

              <div class="admin-note" style="margin:0 0 var(--s-1);font-weight:600;color:var(--text-2)">Panel package</div>
              <?php if ($packageRunning): ?>
                <div class="admin-note" style="margin:0 0 var(--s-2)" data-forecast-progress-label data-progress-kind="package">Generating&hellip; <?php echo (int) ($packageStatus['percent'] ?? 0); ?>%</div>
                <progress class="admin-progress" data-forecast-progress data-progress-kind="package" data-episode-id="<?php echo $episode_id; ?>" value="<?php echo (int) ($packageStatus['percent'] ?? 0); ?>" max="100"></progress>
              <?php else: ?>
                <?php if ($packageError): ?>
                <div class="admin-note" style="margin:0 0 var(--s-2);color:var(--red-hi)">Failed: <?php echo $e(mb_strimwidth($packageError, 0, 200, '…')); ?></div>
                <?php endif; ?>
                <?php if ($packageUrl): ?>
                <a class="btn btn--quiet btn--sm" href="<?php echo $e($packageUrl); ?>" style="margin-bottom:var(--s-3);display:inline-block">Download package</a>
                <?php endif; ?>
                <form action="/_admin/forecast_package.php" method="post" style="margin-bottom:var(--s-4)">
                  <?php echo admin_csrf_field(); ?>
                  <input type="hidden" name="episode_id" value="<?php echo $episode_id; ?>">
                  <button class="btn btn--block" type="submit"><?php echo $packageUrl ? 'Regenerate package' : 'Generate package'; ?></button>
                </form>
              <?php endif; ?>

              <div class="admin-note" style="margin:0 0 var(--s-1);font-weight:600;color:var(--text-2)">Waveform clip</div>
              <?php if (empty($episode['audio_file'])): ?>
                <div class="admin-note">Upload an audio file first.</div>
              <?php elseif ($waveformRunning): ?>
                <div class="admin-note" style="margin:0 0 var(--s-2)" data-forecast-progress-label data-progress-kind="waveform">Generating&hellip; <?php echo (int) ($waveformStatus['percent'] ?? 0); ?>%</div>
                <progress class="admin-progress" data-forecast-progress data-progress-kind="waveform" data-episode-id="<?php echo $episode_id; ?>" value="<?php echo (int) ($waveformStatus['percent'] ?? 0); ?>" max="100"></progress>
              <?php else: ?>
                <?php if ($waveformError): ?>
                <div class="admin-note" style="margin:0 0 var(--s-2);color:var(--red-hi)">Failed: <?php echo $e(mb_strimwidth($waveformError, 0, 200, '…')); ?></div>
                <?php endif; ?>
                <?php if ($waveformUrl): ?>
                <a class="btn btn--quiet btn--sm" href="<?php echo $e($waveformUrl); ?>" style="margin-bottom:var(--s-3);display:inline-block">Download waveform clip</a>
                <?php endif; ?>
                <form action="/_admin/forecast_waveform.php" method="post">
                  <?php echo admin_csrf_field(); ?>
                  <input type="hidden" name="episode_id" value="<?php echo $episode_id; ?>">
                  <button class="btn btn--block" type="submit"><?php echo $waveformUrl ? 'Regenerate waveform clip' : 'Generate waveform clip'; ?></button>
                </form>
              <?php endif; ?>
            </div>
          </section>
        </div>

        <section class="card adm-card">
          <div class="card__head">
            <span class="card__title">Chapters</span>
            <span class="adm-count"><?php echo count($films); ?> selected</span>
          </div>
          <div class="card__body">
            <div class="admin-note" style="margin:0 0 var(--s-3)">
              Every film this week gets a chapter by default &mdash; uncheck any you don't want commentary timed to. "Update preview" shows the change above without saving it. Poster crops adjusted here carry over everywhere else that poster is used, including the Instagram carousel.
            </div>
            <form method="get">
              <?php echo admin_csrf_field(); ?>
              <input type="hidden" name="id" value="<?php echo $episode_id; ?>">
              <input type="hidden" name="preview" value="1">
              <div class="card__body card__body--flush" style="max-height:640px; overflow-y:auto; margin:0 calc(-1 * var(--s-4)); padding:0 var(--s-4)">
                <div class="adm-crop-row">
                  <div class="adm-row">
                    <span class="adm-row__thumb adm-row__thumb--square">
                      <img src="<?php echo $e($introStoryboardUrl); ?>" alt="" loading="lazy" />
                    </span>
                    <span class="adm-row__text">
                      <span class="adm-row__title">INTRO / WRAP-UP</span>
                      <span class="adm-row__sub">Always included &mdash; not part of the checklist</span>
                    </span>
                  </div>
                </div>
                <?php foreach ($byDay as $day => $dayFilms): ?>
                <div class="adm-row" style="background:var(--surface-2)">
                  <span class="adm-row__text">
                    <span class="adm-row__sub" style="font-weight:600;color:var(--text-2)"><?php echo $e(date('l, j F', strtotime($day))); ?></span>
                  </span>
                </div>
                <?php foreach ($dayFilms as $f):
                  $key = ig_film_key($f);
                  $checked = in_array($key, $currentKeys, true);
                  $heroUrl = $f['poster'] ? ig_hero_url($f['poster']) : null;
                  $cropBias = $heroUrl ? ig_poster_crop_bias($heroUrl) : 0.5;
                ?>
                <div class="adm-crop-row">
                  <label class="adm-row adm-row--check">
                    <input type="checkbox" name="films[]" value="<?php echo $e($key); ?>"<?php echo $checked ? ' checked' : ''; ?>>
                    <span class="adm-row__text">
                      <span class="adm-row__title"><?php echo $e(mb_strtoupper($f['display_title'])); ?></span>
                      <span class="adm-row__sub"><?php echo $e($f['venue']); ?></span>
                    </span>
                    <span class="adm-row__when"><?php echo $e(date('g:ia', $f['timestamp'])); ?></span>
                  </label>
                  <?php if ($heroUrl): ?>
                  <button type="button" class="adm-crop-toggle" data-crop-toggle>Adjust crop</button>
                  <div class="adm-crop-panel" data-crop-panel
                       data-crop-endpoint="/_admin/forecast_poster_crop.php"
                       data-episode-id="<?php echo $episode_id; ?>">
                    <div class="adm-crop-preview" data-crop-preview
                         style="background-image:url('<?php echo $e($heroUrl); ?>'); background-position: 50% <?php echo round($cropBias * 100); ?>%;"></div>
                    <input type="range" class="adm-crop-slider" data-crop-slider min="0" max="100" step="1" value="<?php echo round($cropBias * 100); ?>">
                    <div class="adm-crop-actions">
                      <button type="button" class="btn btn--quiet btn--sm" data-crop-save data-poster-url="<?php echo $e($heroUrl); ?>">Save crop</button>
                      <span class="adm-crop-status" data-crop-status></span>
                    </div>
                  </div>
                  <?php endif; ?>
                </div>
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

      <?php if ($films && $audioUrl && $episodeDuration > 0): ?>
      <section class="card adm-card" style="margin-top:var(--s-5)" data-forecast-timeline
               data-audio-url="<?php echo $e($audioUrl); ?>"
               data-duration="<?php echo $e($episodeDuration); ?>"
               data-episode-id="<?php echo $episode_id; ?>"
               data-csrf="<?php echo $e($_SESSION['admin_csrf']); ?>"
               data-intro-image="<?php echo $e($introStoryboardUrl); ?>"
               data-preshow-image="<?php echo $e($preshowStoryboardUrl); ?>"
               data-preshow-seconds="<?php echo FORECAST_PRESHOW_SECONDS; ?>">
        <div class="card__head">
          <span class="card__title">Timeline</span>
          <span class="adm-count" data-forecast-timeline-dirty hidden>Unsaved</span>
        </div>
        <div class="card__body">
          <div class="admin-note" style="margin:0 0 var(--s-3)">
            Drag a day or film from the bank onto the waveform where it comes up — a day is a main chapter, a film nests under whichever day you drop it near. Anything can be used more than once. Play the episode back to hear where things actually land, then fine-tune by dragging the marker itself. Click anywhere on the waveform to jump there.
          </div>

          <div class="adm-two" style="grid-template-columns: minmax(260px, 340px) minmax(0, 1fr); margin-bottom:var(--s-4);">
            <div class="ig-mock">
              <img class="ig-mock__image" data-forecast-storyboard-img src="<?php echo $e($preshowStoryboardUrl); ?>" alt="Segment preview">
            </div>
            <div class="fc-bank" data-forecast-bank>
              <div class="fc-bank__section-label">Days</div>
              <div class="fc-bank__row">
                <?php foreach ($weekDays as $ymd): ?>
                <div class="fc-bank-item" data-bank-item data-type="day" data-key="<?php echo $e($ymd); ?>">
                  <span class="fc-bank-item__thumb">
                    <img src="<?php echo $e($segmentImages['day|' . $ymd] ?? ''); ?>" alt="" loading="lazy">
                  </span>
                  <span class="fc-bank-item__title"><?php echo $e(date('D, M j', strtotime($ymd))); ?></span>
                  <span class="fc-bank-item__badge" data-bank-badge hidden>0</span>
                </div>
                <?php endforeach; ?>
              </div>

              <div class="fc-bank__section-label">Films</div>
              <div class="fc-bank__row">
                <?php foreach ($films as $film): $filmKey = ig_film_key($film); ?>
                <div class="fc-bank-item" data-bank-item data-type="film" data-key="<?php echo $e($filmKey); ?>">
                  <span class="fc-bank-item__thumb">
                    <img src="<?php echo $e($segmentImages['film|' . $filmKey] ?? ''); ?>" alt="" loading="lazy">
                  </span>
                  <span class="fc-bank-item__title"><?php echo $e($film['display_title'] ?? $film['title']); ?></span>
                  <span class="fc-bank-item__badge" data-bank-badge hidden>0</span>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <audio data-forecast-audio-player controls preload="metadata" style="width:100%;margin-bottom:var(--s-3)" src="<?php echo $e($audioUrl); ?>"></audio>

          <div data-forecast-waveform-wrap style="position:relative">
            <canvas data-forecast-waveform-canvas style="width:100%;height:110px;display:block;border-radius:var(--radius);background:var(--surface-2)"></canvas>
            <div data-forecast-markers style="position:absolute;inset:0;top:0;bottom:0"></div>
          </div>

          <div style="margin-top:var(--s-4);display:flex;align-items:center;gap:var(--s-3)">
            <button class="btn btn--quiet btn--sm" type="button" data-forecast-chapters-save disabled>Save chapters</button>
            <span class="admin-note" style="margin:0" data-forecast-chapters-status></span>
          </div>
        </div>
      </section>

      <script type="application/json" data-forecast-chapters-data><?php echo json_encode([
          'chapters' => array_map(fn($c) => [
              'type' => $c['type'], 'film' => $c['film'] ?? null, 'day' => $c['day'] ?? null,
              'start' => $c['start'], 'title' => $c['title'],
          ], $chapters),
          'segmentImages' => $segmentImages,
          'wrapupStart' => forecast_wrapup_start($chapters, $episodeDuration),
      ]); ?></script>
      <?php endif; ?>

    </div>
  </main>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
