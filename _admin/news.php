<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — Film News
//
//  A curation feed, not an editorial workflow: Austin Chronicle (filtered to
//  Screens) and Austin Film Festival's own RSS, merged and sorted newest
//  first, for skimming toward the next Instagram post. See
//  list/scraper_news.php for how each source is fetched; nothing here is
//  persisted — the feeds themselves stay recent on their own, so there's no
//  "mark as used" state to track.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
require dirname(__DIR__) . '/v7/_lib.php';
require dirname(__DIR__) . '/list/scraper_news.php';

$items = fetch_all_news();

$e = 'ctx_e';

$ctx_title     = 'Film News — Cinema, TX Admin';
$ctx_active    = 'news';
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
        <h1 class="adm__title">Film News</h1>
        <span class="adm__meta"><?php echo count($items); ?> stor<?php echo count($items) === 1 ? 'y' : 'ies'; ?></span>
      </div>

      <section class="card adm-card">
        <div class="card__head">
          <span class="card__title">Latest</span>
          <span class="adm-count"><?php echo count($items); ?></span>
          <form action="/_admin/news_refresh.php" method="post" style="margin-left:auto">
            <?php echo admin_csrf_field(); ?>
            <button class="btn btn--quiet btn--sm" type="submit">Refresh now</button>
          </form>
        </div>
        <?php if ($items): ?>
        <div class="card__body card__body--flush">
          <?php foreach ($items as $item): ?>
          <div class="adm-row">
            <span class="adm-row__text">
              <a class="adm-row__title" href="<?php echo $e($item['url']); ?>" target="_blank" rel="noopener"><?php echo $e($item['title']); ?></a>
              <span class="adm-row__sub">
                <?php echo $e($item['source']); ?>
                <?php if ($item['published']): ?> &middot; <?php echo $e(date('M j', $item['published'])); ?><?php endif; ?>
                <?php if (!empty($item['excerpt'])): ?> &middot; <?php echo $e(mb_strimwidth($item['excerpt'], 0, 140, '…')); ?><?php endif; ?>
              </span>
            </span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="card__body card__body--flush">
          <div class="adm-empty">Nothing to show yet</div>
        </div>
        <?php endif; ?>
      </section>
    </div>
  </main>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
