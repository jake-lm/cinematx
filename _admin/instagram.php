<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — Instagram preview & post
//
//  Renders today's actual card(s) — the same ig_build_images()/ig_caption()
//  the cron job calls — so what you approve here is exactly what would be
//  posted, with no separate preview path to drift out of sync.
//
//  Composition (mode/per-page/spotlight panels) is previewed live from the
//  URL's query params, which regenerates the mockup without touching disk —
//  only "Save composition" commits it to compose-<date>.json. Posting always
//  publishes whatever is currently *saved*, not whatever the mockup happens
//  to be showing, so an unsaved experiment can never go out by accident.
//
//  Posting is a real button click from a signed-in admin, never something the
//  page does on its own. A .posted-<date> flag stops a double click, a reload,
//  or a later cron run from publishing the same day twice.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
require dirname(__DIR__) . '/v7/_lib.php';
require dirname(__DIR__) . '/list/instagram.php';

$now   = time();
$films = ig_today_films($conn);
$saved = ig_compose_read($now);

$mode = $_GET['mode'] ?? $saved['mode'];
if (!in_array($mode, ['default', 'auto', 'manual'], true)) $mode = 'default';
$per_page = isset($_GET['per_page']) ? max(1, (int) $_GET['per_page']) : ($saved['per_page'] ?: 6);
$features = isset($_GET['features']) ? $_GET['features'] === '1' : !empty($saved['features']);

$compose = ['mode' => $mode, 'per_page' => $mode === 'manual' ? $per_page : null, 'features' => $features];
$dirty   = $compose['mode'] !== $saved['mode']
        || ($compose['mode'] === 'manual' && (int) $compose['per_page'] !== (int) ($saved['per_page'] ?? 0))
        || $compose['features'] !== !empty($saved['features']);

$images  = ig_build_images($films, $now, $compose);
$pages   = ig_save_images($images, $now);
$caption = ig_caption($films, $now);

$posted_flag    = dirname(__DIR__) . '/uploads/social/.posted-' . date('Y-m-d', $now);
$already_posted = file_exists($posted_flag);
$configured     = defined('IG_ACCESS_TOKEN') && IG_ACCESS_TOKEN
                && defined('IG_BUSINESS_ACCOUNT_ID') && IG_BUSINESS_ACCOUNT_ID;

$e  = 'ctx_e';
$qs = function ($overrides) use ($mode, $per_page, $features) {
    return http_build_query(array_merge(
        ['mode' => $mode, 'per_page' => $per_page, 'features' => $features ? 1 : 0],
        $overrides
    ));
};

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
    <div class="adm">

      <div class="adm__head">
        <h1 class="adm__title">Instagram</h1>
        <span class="adm__meta"><?php echo date('l, j F', $now); ?></span>
        <span class="post-status <?php echo $already_posted ? 'status-live' : 'status-draft'; ?>">
          <?php echo $already_posted ? 'Posted' : 'Pending'; ?>
        </span>
      </div>

      <?php if (isset($_GET['posted'])): ?>
        <div class="alert" style="margin-bottom:var(--s-5)">Posted &mdash; media id <?php echo $e($_GET['posted']); ?></div>
      <?php elseif (isset($_GET['error'])): ?>
        <div class="alert" style="margin-bottom:var(--s-5)">Post failed: <?php echo $e($_GET['error']); ?></div>
      <?php endif; ?>

      <div class="adm-two" style="grid-template-columns: minmax(320px, 430px) minmax(0, 1fr);">

        <div class="ig-mock">
          <div class="ig-mock__head">
            <span class="ig-mock__avatar">C</span>
            <span class="ig-mock__handle">cinematx</span>
            <?php if (count($pages) > 1): ?>
              <span class="ig-mock__pagecount"><?php echo count($pages); ?> pages</span>
            <?php endif; ?>
            <span class="ig-mock__dots">&#8226;&#8226;&#8226;</span>
          </div>

          <?php // Keyed on each file's own mtime, not the clock: an unchanged
                // card can then actually be cached instead of re-fetching it
                // on every load, while a regenerated one still busts. ?>
          <?php if (count($pages) > 1): ?>
          <div class="ig-mock__strip">
            <?php foreach ($pages as $i => [$path, $url]): ?>
            <div class="ig-mock__page">
              <img class="ig-mock__image" src="<?php echo $e($url . '?v=' . @filemtime($path)); ?>" alt="Instagram card, page <?php echo $i + 1; ?>" />
            </div>
            <?php endforeach; ?>
          </div>
          <?php else: [$path, $url] = $pages[0]; ?>
          <img class="ig-mock__image" src="<?php echo $e($url . '?v=' . @filemtime($path)); ?>" alt="Today's Instagram card" />
          <?php endif; ?>

          <div class="ig-mock__actions">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20.8 8.6c0 5-8.8 10-8.8 10s-8.8-5-8.8-10a4.8 4.8 0 0 1 8.8-2.7A4.8 4.8 0 0 1 20.8 8.6Z"/></svg>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5c-1.2 0-2.3-.2-3.4-.7L3 20l1.1-4.1A8.5 8.5 0 1 1 21 11.5Z"/></svg>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M22 3 11 14"/><path d="M22 3 15 21l-4-7-7-4Z"/></svg>
            <span class="ig-mock__actions-spacer"></span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12v18l-6-4-6 4Z"/></svg>
          </div>

          <div class="ig-mock__caption"><strong class="ig-mock__handle">cinematx</strong> <?php echo $e($caption); ?></div>
        </div>

        <div style="display:grid; gap:var(--s-5);">

          <section class="card adm-card">
            <div class="card__head">
              <span class="card__title">Composition</span>
              <?php if ($dirty): ?><span class="adm-count">Previewing</span><?php endif; ?>
            </div>
            <div class="card__body">
              <div class="field">
                <span class="field__label">Mode</span>
                <div class="adm-seg" role="group" aria-label="Composition mode">
                  <a class="adm-seg__btn<?php echo $mode === 'default' ? ' is-on' : ''; ?>" href="?<?php echo $e($qs(['mode' => 'default'])); ?>">Default</a>
                  <a class="adm-seg__btn<?php echo $mode === 'auto' ? ' is-on' : ''; ?>" href="?<?php echo $e($qs(['mode' => 'auto'])); ?>">Auto</a>
                  <a class="adm-seg__btn<?php echo $mode === 'manual' ? ' is-on' : ''; ?>" href="?<?php echo $e($qs(['mode' => 'manual'])); ?>">Manual</a>
                </div>
              </div>

              <?php if ($mode === 'default'): ?>
                <div class="admin-note" style="margin:0">One card, today's usual design &mdash; whatever fits, "+N more" below it.</div>
              <?php else: ?>
              <form method="get">
                <input type="hidden" name="mode" value="<?php echo $e($mode); ?>">
                <?php if ($mode === 'manual'): ?>
                <div class="field">
                  <label class="field__label" for="per_page">Screenings per page</label>
                  <input class="field__input" type="number" min="1" max="10" id="per_page" name="per_page" value="<?php echo (int) $per_page; ?>">
                </div>
                <?php endif; ?>
                <div class="field">
                  <label class="role-check">
                    <input type="hidden" name="features" value="0">
                    <input type="checkbox" name="features" value="1"<?php echo $features ? ' checked' : ''; ?>>
                    Add spotlight panels
                  </label>
                </div>
                <button class="btn btn--quiet btn--sm" type="submit">Update preview</button>
              </form>
              <?php endif; ?>

              <?php if ($dirty): ?>
              <form action="/_admin/instagram_compose.php" method="post" style="margin-top:var(--s-4)">
                <?php echo admin_csrf_field(); ?>
                <input type="hidden" name="mode" value="<?php echo $e($mode); ?>">
                <input type="hidden" name="per_page" value="<?php echo (int) $per_page; ?>">
                <input type="hidden" name="features" value="<?php echo $features ? 1 : 0; ?>">
                <button class="btn btn--block" type="submit">Save composition</button>
              </form>
              <?php endif; ?>
            </div>
          </section>

          <section class="card adm-card">
            <div class="card__head">
              <span class="card__title">Caption</span>
            </div>
            <div class="card__body">
              <form action="/_admin/instagram_caption.php" method="post">
                <?php echo admin_csrf_field(); ?>
                <textarea class="field__input admin-textarea" name="caption" maxlength="<?php echo IG_CAPTION_MAX; ?>" rows="6"><?php echo $e($caption); ?></textarea>
                <div style="display:flex; gap:var(--s-2); margin-top:var(--s-3)">
                  <button class="btn" type="submit" name="action" value="save">Save</button>
                  <button class="btn btn--quiet" type="submit" name="action" value="reset">Reset to generated</button>
                </div>
              </form>
            </div>
          </section>

          <section class="card adm-card">
            <div class="card__head">
              <span class="card__title">Publish</span>
            </div>
            <div class="card__body">
              <?php if ($already_posted): ?>
                <div class="admin-note">
                  Already posted today. Reloading this page regenerates the preview but will not
                  post again &mdash; the same flag also stops the cron job repeating it.
                </div>
                <button class="btn btn--block" type="button" disabled>Posted</button>
              <?php elseif (!$configured): ?>
                <div class="admin-note">
                  <code>IG_ACCESS_TOKEN</code> and <code>IG_BUSINESS_ACCOUNT_ID</code> are not set in
                  <code>config.php</code>, so there is nothing to post to yet.
                </div>
                <button class="btn btn--block" type="button" disabled>Not configured</button>
              <?php else: ?>
                <div class="admin-note">
                  Publishes the <strong>saved</strong> composition and caption to the live account
                  &mdash; not necessarily what the mockup is previewing right now.
                  <?php if ($dirty): ?> This mockup has unsaved changes; save composition first if you want them posted.<?php endif; ?>
                </div>
                <form action="/_admin/instagram_post.php" method="post"
                      onsubmit="return confirm('Publish this to the live Instagram account now? This cannot be undone.');">
                  <?php echo admin_csrf_field(); ?>
                  <button class="btn btn--block" type="submit">Post to Instagram</button>
                </form>
              <?php endif; ?>
            </div>
          </section>

          <section class="card adm-card">
            <div class="card__head">
              <span class="card__title">On the card</span>
              <span class="adm-count"><?php echo count($films); ?></span>
            </div>
            <div class="card__body card__body--flush">
              <?php foreach ($films as $f): ?>
              <div class="adm-row">
                <span class="adm-row__text">
                  <span class="adm-row__title"><?php echo $e(mb_strtoupper($f['title'])); ?></span>
                  <span class="adm-row__sub">
                    <?php echo $e($f['venue']); ?><?php if ($f['director']): ?> &middot; dir. <?php echo $e($f['director']); ?><?php endif; ?>
                  </span>
                </span>
                <span class="adm-row__when"><?php echo date('g:i A', $f['timestamp']); ?></span>
              </div>
              <?php endforeach; ?>
              <?php if (!$films): ?>
              <div class="adm-empty">Nothing scraped for today</div>
              <?php endif; ?>
            </div>
          </section>

          <section class="card adm-card">
            <div class="card__head"><span class="card__title">Where this comes from</span></div>
            <div class="card__body">
              <div class="admin-note" style="margin:0">
                Today's screenings at <strong>Austin Film Society</strong>, the
                <strong>Paramount</strong> and <strong>Hyperreal Film Club</strong>, from the same
                scrape The List runs on. Chains and member-submitted events are left out on purpose.
                The cron job posts this automatically each morning once it is enabled &mdash; until
                then it waits for the button.
              </div>
            </div>
          </section>

        </div>
      </div>
    </div>
  </main>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
