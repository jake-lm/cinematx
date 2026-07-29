<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — Instagram preview & post
//
//  Renders today's actual card (same ig_build_image()/ig_build_caption() the
//  cron job calls) so what you approve here is exactly what would be posted
//  — no separate preview path to drift out of sync. Posting is a real button
//  click from a logged-in admin, not something the agent/cron can trigger by
//  itself; a .posted-<date> flag file stops a double-click (or a later cron
//  run the same day) from publishing twice.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
require dirname(__DIR__) . '/v7/_lib.php';
require dirname(__DIR__) . '/list/instagram.php';

$now   = time();
$films = ig_today_films($conn);
$image = ig_build_image($films, $now);
[$path, $image_url] = ig_save_image($image, $now);
$caption = ig_build_caption($films, $now);

$posted_flag    = dirname(__DIR__) . '/uploads/social/.posted-' . date('Y-m-d', $now);
$already_posted = file_exists($posted_flag);
$configured     = defined('IG_ACCESS_TOKEN') && IG_ACCESS_TOKEN
                && defined('IG_BUSINESS_ACCOUNT_ID') && IG_BUSINESS_ACCOUNT_ID;

$e = 'ctx_e';

$ctx_title     = 'Instagram — Cinema, TX Admin';
$ctx_active    = 'instagram';
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
      <h1 class="reading__title">Instagram</h1>
      <div class="reading__by"><?php echo count($films); ?> screenings today &middot; <?php echo date('l, F j', $now); ?></div>

      <?php if (isset($_GET['posted'])): ?>
        <div class="admin-note" style="color:var(--red)">Posted — media id <?php echo $e($_GET['posted']); ?></div>
      <?php elseif (isset($_GET['error'])): ?>
        <div class="admin-note" style="color:var(--red)">Post failed: <?php echo $e($_GET['error']); ?></div>
      <?php endif; ?>

      <div class="admin-grid" style="grid-template-columns: minmax(320px, 440px) minmax(260px, 1fr); align-items: start;">

        <div class="ig-mock">
          <div class="ig-mock__head">
            <span class="ig-mock__avatar">C</span>
            <span class="ig-mock__handle">cinematx</span>
            <span class="ig-mock__dots">&#8226;&#8226;&#8226;</span>
          </div>

          <img class="ig-mock__image" src="<?php echo $e($image_url . '?t=' . time()); ?>" alt="Today's Instagram card" />

          <div class="ig-mock__actions">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20.8 8.6c0 5-8.8 10-8.8 10s-8.8-5-8.8-10a4.8 4.8 0 0 1 8.8-2.7A4.8 4.8 0 0 1 20.8 8.6Z"/></svg>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5c-1.2 0-2.3-.2-3.4-.7L3 20l1.1-4.1A8.5 8.5 0 1 1 21 11.5Z"/></svg>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M22 3 11 14"/><path d="M22 3 15 21l-4-7-7-4Z"/></svg>
            <span class="ig-mock__actions-spacer"></span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12v18l-6-4-6 4Z"/></svg>
          </div>

          <div class="ig-mock__caption"><strong class="ig-mock__handle"><?php echo $e('cinematx'); ?></strong> <?php echo $e($caption); ?></div>
        </div>

        <section class="card admin-card">
          <div class="card__head"><span class="card__n">01</span><span class="card__title">Post</span></div>
          <div class="card__body">
            <div class="admin-note"><?php echo count($films); ?> screenings pulled from AFS, Paramount &amp; Hyperreal for <?php echo $e(date('l, F j', $now)); ?>.</div>

            <?php if ($already_posted): ?>
              <div class="admin-note">Already posted today — regenerating this preview won't post again.</div>
            <?php elseif (!$configured): ?>
              <div class="admin-note">IG_ACCESS_TOKEN / IG_BUSINESS_ACCOUNT_ID aren't set in config.php yet.</div>
            <?php else: ?>
              <form action="/_admin/instagram_post.php" method="post"
                    onsubmit="return confirm('Publish this to the live Instagram account now? This cannot be undone.');">
                <?php echo admin_csrf_field(); ?>
                <button class="btn btn--block" type="submit">Post to Instagram</button>
              </form>
            <?php endif; ?>
          </div>
        </section>

      </div>
    </div>
  </main>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
