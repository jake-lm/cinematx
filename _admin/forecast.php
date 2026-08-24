<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — Film Forecast
//
//  The weekly podcast Reel — independent from the daily screening carousel
//  (list/instagram.php) and from events: its own table, its own cover
//  design, its own publish path (ig_publish_reel(), list/forecast.php).
//
//  An episode moves through three states this page surfaces directly:
//  no video yet → generate one from the uploaded audio (or upload a
//  finished video and skip that step) → post. posted_media_id is the
//  point of no return, same guard shape instagram_post.php uses.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
require dirname(__DIR__) . '/v7/_lib.php';
require dirname(__DIR__) . '/list/forecast.php';

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $conn->prepare("SELECT * FROM `forecast_episodes` WHERE id = :id AND uid = :uid");
    $stmt->execute([':id' => (int) $_GET['edit'], ':uid' => $admin_user['id']]);
    $editing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$episodes = forecast_list_episodes($conn);

$e = 'ctx_e';

$ctx_title     = 'Film Forecast — Cinema, TX Admin';
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
        <h1 class="adm__title">Film Forecast</h1>
        <span class="adm__meta"><?php echo count($episodes); ?> episode<?php echo count($episodes) === 1 ? '' : 's'; ?></span>
      </div>

      <?php if (isset($_GET['posted'])): ?>
        <div class="alert" style="margin-bottom:var(--s-5)">Posted &mdash; media id <?php echo $e($_GET['posted']); ?></div>
      <?php elseif (isset($_GET['error'])): ?>
        <div class="alert" style="margin-bottom:var(--s-5)"><?php echo $e($_GET['error']); ?></div>
      <?php endif; ?>

      <div class="adm-two">

        <section class="card adm-card">
          <div class="card__head">
            <span class="card__title"><?php echo $editing ? 'Edit episode' : 'New episode'; ?></span>
          </div>
          <div class="card__body">
            <form action="/_admin/forecast_save.php" method="post" enctype="multipart/form-data">
              <?php echo admin_csrf_field(); ?>
              <?php if ($editing): ?>
              <input type="hidden" name="episode_id" value="<?php echo (int) $editing['id']; ?>">
              <?php endif; ?>
              <div class="field"><label class="field__label" for="fc-guest">Guest name</label>
                <input class="field__input" id="fc-guest" name="guest_name" type="text" required
                       value="<?php echo $e($editing['guest_name'] ?? ''); ?>"></div>
              <div class="field"><label class="field__label" for="fc-week">Week of</label>
                <input class="field__input" id="fc-week" name="week_of" type="date" required
                       value="<?php echo $e($editing['week_of'] ?? ''); ?>"></div>
              <div class="field"><label class="field__label" for="fc-blurb">Episode blurb <span class="admin-tz">optional</span></label>
                <textarea class="field__input admin-textarea" id="fc-blurb" name="blurb" rows="4"><?php echo $e($editing['blurb'] ?? ''); ?></textarea></div>
              <div class="field"><label class="field__label" for="fc-photo">Guest photo <span class="admin-tz">optional</span></label>
                <input class="field__input" id="fc-photo" name="guest_photo" type="file" accept="image/jpeg,image/png,image/webp">
                <?php if ($editing && !empty($editing['guest_photo'])): ?>
                <div class="admin-note" style="margin:var(--s-2) 0 0">Leave blank to keep the current photo.</div>
                <?php endif; ?>
              </div>
              <div class="field"><label class="field__label" for="fc-audio">Audio file <span class="admin-tz">for auto-generating the Reel</span></label>
                <input class="field__input" id="fc-audio" name="audio_file" type="file" accept="audio/mpeg,audio/mp4,audio/x-m4a,audio/wav">
                <?php if ($editing && !empty($editing['audio_file'])): ?>
                <div class="admin-note" style="margin:var(--s-2) 0 0">Current: <a href="/_admin/forecast_episode.php?id=<?php echo (int) $editing['id']; ?>"><?php echo $e($editing['audio_file']); ?></a><?php if (!empty($editing['duration_seconds'])): ?> (<?php echo forecast_format_duration($editing['duration_seconds']); ?>)<?php endif; ?> &mdash; pick the showcase and generate the video there.</div>
                <?php endif; ?>
              </div>
              <div class="field"><label class="field__label" for="fc-video">Or a finished video <span class="admin-tz">skips generation, posts as-is</span></label>
                <input class="field__input" id="fc-video" name="video_file" type="file" accept="video/mp4,video/quicktime">
                <?php if ($editing && !empty($editing['video_file'])): ?>
                <div class="admin-note" style="margin:var(--s-2) 0 0">Current: <?php echo $e($editing['video_file']); ?></div>
                <?php endif; ?>
              </div>
              <div class="field"><label class="field__label" for="fc-caption">Caption <span class="admin-tz">optional override</span></label>
                <textarea class="field__input admin-textarea" id="fc-caption" name="caption" rows="4" placeholder="Leave blank to generate one from the fields above"><?php echo $e($editing['caption'] ?? ''); ?></textarea></div>
              <button class="btn btn--block" type="submit"><?php echo $editing ? 'Save changes' : 'Add episode'; ?></button>
            </form>
            <?php if ($editing): ?>
            <div style="margin-top:var(--s-3)">
              <a class="btn btn--quiet btn--sm" href="/_admin/forecast.php">Cancel edit</a>
            </div>
            <?php endif; ?>
          </div>
        </section>

        <section class="card adm-card">
          <div class="card__head">
            <span class="card__title">Episodes</span>
            <span class="adm-count"><?php echo count($episodes); ?></span>
          </div>
          <?php if ($episodes): ?>
          <div class="card__body card__body--flush">
            <?php foreach ($episodes as $ep):
              $hasVideo   = !empty($ep['video_file']) || !empty($ep['generated_video']);
              $hasSource  = !empty($ep['audio_file']) || !empty($ep['video_file']);
              $posted     = !empty($ep['posted_media_id']);
            ?>
            <div class="adm-row">
              <span class="adm-row__thumb">
                <?php if ($ep['guest_photo']): ?>
                <img src="/uploads/forecast/<?php echo $e($ep['guest_photo']); ?>" alt="" loading="lazy" />
                <?php endif; ?>
              </span>
              <span class="adm-row__text">
                <?php if ($hasSource): ?>
                <a class="adm-row__title" href="/_admin/forecast_episode.php?id=<?php echo (int) $ep['id']; ?>"><?php echo $e($ep['guest_name']); ?></a>
                <?php else: ?>
                <span class="adm-row__title"><?php echo $e($ep['guest_name']); ?></span>
                <?php endif; ?>
                <span class="adm-row__sub">
                  Week of <?php echo $e(date('M j', strtotime($ep['week_of']))); ?>
                  <?php if ($ep['duration_seconds']): ?> &middot; <?php echo forecast_format_duration($ep['duration_seconds']); ?><?php endif; ?>
                  <?php if ($posted): ?> &middot; Posted<?php elseif ($hasVideo): ?> &middot; Ready<?php elseif ($hasSource): ?> &middot; Needs a video<?php else: ?> &middot; Needs audio<?php endif; ?>
                </span>
              </span>
              <span class="adm-row__end">
                <?php if ($hasSource): ?>
                <a class="event-edit" href="/_admin/forecast_episode.php?id=<?php echo (int) $ep['id']; ?>" title="Open workshop">
                  <i class="fa-solid fa-clapperboard"></i>
                </a>
                <?php endif; ?>
                <a class="event-edit" href="/_admin/forecast.php?edit=<?php echo (int) $ep['id']; ?>" title="Edit">
                  <i class="fa-solid fa-pen"></i>
                </a>
                <?php if (!$posted): ?>
                <form action="/_admin/forecast_delete.php" method="post"
                      onsubmit="return confirm('Delete this episode?');">
                  <?php echo admin_csrf_field(); ?>
                  <input type="hidden" name="episode_id" value="<?php echo (int) $ep['id']; ?>">
                  <button class="event-delete" type="submit" title="Delete"><i class="fa-solid fa-trash"></i></button>
                </form>
                <?php endif; ?>
              </span>
            </div>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <div class="card__body card__body--flush">
            <div class="adm-empty">No episodes yet</div>
          </div>
          <?php endif; ?>
        </section>

      </div>
    </div>
  </main>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
