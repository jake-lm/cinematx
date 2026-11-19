<?php
// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — The Journal, the archive
//
//  Every published essay and review, built from the same pieces The List is:
//  the listing header, filter chips, a view toggle and rows. Two views —
//  columns (a lead story, then compact stories in ruled newspaper columns)
//  and a plain list — and a filter by type. All of it is ordinary links
//  (?type=, ?view=, ?page=), so there's no script and every state can be
//  bookmarked. The toggles deliberately don't use data-view / data-narrow:
//  the List's script binds those site-wide.
//
//  Same posts the front page's Journal card draws from (type 'essay' or
//  'review'); that card's "Archive" link lands here.
// ═══════════════════════════════════════════════════════════════════════════
require dirname(__DIR__) . '/v7/_lib.php';

const JOURNAL_TYPES = ['essay' => 'Essays', 'review' => 'Reviews'];

$type = isset(JOURNAL_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : null;
$view = ($_GET['view'] ?? '') === 'list' ? 'list' : 'columns';
$per  = $view === 'list' ? 30 : 12;

// $type is whitelisted above, so it's safe to inline.
$where = "p.active = 1 AND p.type IN ('review','essay')" . ($type ? " AND p.type = '$type'" : '');

// Chip counts ignore the current filter — they say how much each one holds.
$counts = $conn->query(
    "SELECT p.type, COUNT(*) FROM posts p
     WHERE p.active = 1 AND p.type IN ('review','essay') GROUP BY p.type"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$n_all = array_sum($counts);

$total = (int)$conn->query("SELECT COUNT(*) FROM posts p WHERE $where")->fetchColumn();

// The lead is the editor's pick — the latest featured piece, or just the
// newest when nothing is featured. Columns view only; the list is a plain
// index. It's excluded from the paged stories on every page so it never
// shows twice, and only rendered on the first.
$lead = null;
if ($view === 'columns') {
    $lead = $conn->query(
        "SELECT p.*, u.name AS author_name
         FROM posts p LEFT JOIN users u ON p.uid = u.id
         WHERE $where
         ORDER BY p.featured DESC, p.stamp DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC) ?: null;
}

$n_rest = $total - ($lead ? 1 : 0);
$pages  = max(1, (int)ceil($n_rest / $per));
$page   = min(max(1, (int)($_GET['page'] ?? 1)), $pages);

$rest = $conn->query(
    "SELECT p.*, u.name AS author_name
     FROM posts p LEFT JOIN users u ON p.uid = u.id
     WHERE $where" . ($lead ? " AND p.id != " . (int)$lead['id'] : "") . "
     ORDER BY COALESCE(p.edited, p.stamp) DESC
     LIMIT $per OFFSET " . (($page - 1) * $per)
)->fetchAll(PDO::FETCH_ASSOC);

// By year, newest first — the same label-and-rule heading The List uses for
// a day, so a long archive reads as sections rather than one long run.
$by_year = [];
foreach ($rest as $p) $by_year[date('Y', $p['edited'] ?: $p['stamp'])][] = $p;

/** This page's own URL with some parameters changed; defaults are left out. */
$url = function (array $over = []) use ($type, $view) {
    $q = array_merge(['type' => $type, 'view' => $view === 'list' ? 'list' : null, 'page' => null], $over);
    $q = array_filter($q, fn($v) => $v !== null && $v !== '');
    return '/journal/' . ($q ? '?' . http_build_query($q) : '');
};

/** A plain-text teaser, cut back to a whole word. */
function journal_excerpt($text, $max) {
    $text = trim(preg_replace('/\s+/u', ' ', (string)$text));
    if (mb_strlen($text) <= $max) return $text;
    $cut = mb_substr($text, 0, $max);
    if (!preg_match('/\s/u', mb_substr($text, $max, 1))) $cut = preg_replace('/\s+\S*$/u', '', $cut);
    return rtrim($cut, " ,;:.-—") . '…';
}

$e = 'ctx_e';

// Shell
$ctx_title  = 'The Journal — Cinema, TX';
$ctx_active = '';
$ctx_scroll = true;
$ctx_video  = false;

require dirname(__DIR__) . '/v7/_head.php';
require dirname(__DIR__) . '/v7/_chrome.php';
?>

  <main class="canvas">
    <div class="listing journal">

      <div class="listing__head listing__head--paper">
        <div class="listing__title">
          <h1 class="listing__h">The Journal</h1>
          <span class="listing__sub"><?php echo $total; ?> <?php echo $total === 1 ? 'piece' : 'pieces'; ?></span>
        </div>

        <div class="listing__controls">
          <div class="seg">
            <a class="seg__btn<?php echo $view === 'columns' ? ' is-on' : ''; ?>" href="<?php echo $e($url(['view' => null])); ?>" title="Columns" aria-label="Columns"><i class="fa-solid fa-newspaper"></i></a>
            <a class="seg__btn<?php echo $view === 'list' ? ' is-on' : ''; ?>" href="<?php echo $e($url(['view' => 'list'])); ?>" title="List" aria-label="List"><i class="fa-solid fa-list"></i></a>
          </div>

          <div class="chips">
            <a class="chip<?php echo $type === null ? ' is-on' : ''; ?>" href="<?php echo $e($url(['type' => null])); ?>">All<span class="chip__n"><?php echo $n_all; ?></span></a>
            <?php foreach (JOURNAL_TYPES as $t => $label): if (empty($counts[$t])) continue; ?>
            <a class="chip<?php echo $type === $t ? ' is-on' : ''; ?>" href="<?php echo $e($url(['type' => $t])); ?>"><?php echo $label; ?><span class="chip__n"><?php echo (int)$counts[$t]; ?></span></a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <?php if ($total === 0): ?>
      <p class="empty">Nothing published yet.</p>
      <?php endif; ?>

      <?php if ($lead && $page === 1):
        $lead_url    = ctx_post_url($lead['id'], $lead['title']);
        $lead_images = ctx_post_images($lead);
        $lead_text   = journal_excerpt($lead['content'], 300);
      ?>
      <article class="jlead<?php echo $lead_images ? '' : ' jlead--text'; ?>">
        <?php if ($lead_images): ?>
        <a class="jlead__art" href="<?php echo $e($lead_url); ?>">
          <img src="/uploads/posts/<?php echo $e($lead_images[0]['file']); ?>" alt="" />
        </a>
        <?php endif; ?>
        <div class="jlead__txt">
          <div class="jkicker">
            <span class="jkicker__type"><?php echo $e($lead['type'] ?: 'Essay'); ?></span>
            <span><?php echo date('j F Y', $lead['edited'] ?: $lead['stamp']); ?></span>
            <?php if (!empty($lead['author_name'])): ?>
            <span>By <a href="/users/profile.php?id=<?php echo (int)$lead['uid']; ?>"><?php echo $e($lead['author_name']); ?></a></span>
            <?php endif; ?>
          </div>
          <h2 class="jlead__title"><a href="<?php echo $e($lead_url); ?>"><?php echo $e($lead['title']); ?></a></h2>
          <?php if (!empty($lead['subtitle'])): ?>
          <p class="jlead__deck"><?php echo $e($lead['subtitle']); ?></p>
          <?php endif; ?>
          <?php if ($lead_text !== ''): ?>
          <p class="jlead__excerpt"><?php echo $e($lead_text); ?></p>
          <?php endif; ?>
          <a class="jmore" href="<?php echo $e($lead_url); ?>">Read in full &rarr;</a>
        </div>
      </article>
      <?php endif; ?>

      <?php foreach ($by_year as $year => $items): ?>
      <section class="day">
        <div class="day__label">
          <span class="day__name"><?php echo $e($year); ?></span>
          <span class="day__rule"></span>
          <span class="day__count"><?php echo count($items); ?></span>
        </div>

        <?php if ($view === 'list'): ?>
        <div class="rows-view is-on jrows">
          <?php foreach ($items as $p): ?>
          <a class="row" href="<?php echo $e(ctx_post_url($p['id'], $p['title'])); ?>">
            <span class="row__type"><?php echo $e($p['type']); ?></span>
            <span class="row__title"><?php echo $e($p['title']); ?></span>
            <?php if (!empty($p['author_name'])): ?><span class="row__by"><?php echo $e($p['author_name']); ?></span><?php endif; ?>
            <span class="row__meta"><?php echo date('j M Y', $p['edited'] ?: $p['stamp']); ?></span>
          </a>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="jcols">
          <?php foreach ($items as $p):
            $imgs   = ctx_post_images($p);
            $teaser = !empty($p['subtitle']) ? null : journal_excerpt($p['content'], 150);
          ?>
          <a class="jitem<?php echo $imgs ? '' : ' jitem--text'; ?>" href="<?php echo $e(ctx_post_url($p['id'], $p['title'])); ?>">
            <?php if ($imgs): ?>
            <span class="jitem__art"><img src="/uploads/posts/<?php echo $e($imgs[0]['file']); ?>" alt="" loading="lazy" /></span>
            <?php endif; ?>
            <span class="jitem__txt">
              <span class="jkicker">
                <span class="jkicker__type"><?php echo $e($p['type']); ?></span>
                <span><?php echo date('j M Y', $p['edited'] ?: $p['stamp']); ?></span>
              </span>
              <span class="jitem__title"><?php echo $e($p['title']); ?></span>
              <?php if (!empty($p['subtitle'])): ?>
              <span class="jitem__deck"><?php echo $e($p['subtitle']); ?></span>
              <?php elseif ($teaser !== null && $teaser !== ''): ?>
              <span class="jitem__excerpt"><?php echo $e($teaser); ?></span>
              <?php endif; ?>
              <?php if (!empty($p['author_name'])): ?>
              <span class="jby">By <?php echo $e($p['author_name']); ?></span>
              <?php endif; ?>
            </span>
          </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </section>
      <?php endforeach; ?>

      <?php if ($pages > 1): ?>
      <nav class="jpager" aria-label="Pages">
        <?php if ($page > 1): ?>
        <a class="btn btn--quiet" href="<?php echo $e($url(['page' => $page > 2 ? $page - 1 : null])); ?>">&larr; Newer</a>
        <?php else: ?><span></span><?php endif; ?>
        <span class="jpager__n">Page <?php echo $page; ?> of <?php echo $pages; ?></span>
        <?php if ($page < $pages): ?>
        <a class="btn btn--quiet" href="<?php echo $e($url(['page' => $page + 1])); ?>">Older &rarr;</a>
        <?php else: ?><span></span><?php endif; ?>
      </nav>
      <?php endif; ?>

    </div>
  </main>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
