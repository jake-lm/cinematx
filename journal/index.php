<?php
// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — The Journal, the archive
//
//  Every published essay and review, laid out like a broadsheet's front
//  page rather than a feed: a masthead, one lead story, then the rest in
//  ruled columns grouped by year. A reading surface, so it scrolls normally.
//
//  Same pieces the front page's Journal card draws from (posts of type
//  'essay' or 'review'); that card's "Archive" link lands here.
// ═══════════════════════════════════════════════════════════════════════════
require dirname(__DIR__) . '/v7/_lib.php';

const JOURNAL_PER_PAGE = 12;

$type_sql = "p.active = 1 AND p.type IN ('review','essay')";

// The lead is the editor's pick — the latest featured piece, or just the
// newest when nothing is featured. It's excluded from the paged list on
// every page so it never shows twice, and only rendered on the first.
$q = $conn->query(
    "SELECT p.*, u.name AS author_name
     FROM posts p LEFT JOIN users u ON p.uid = u.id
     WHERE $type_sql
     ORDER BY p.featured DESC, p.stamp DESC LIMIT 1"
);
$lead = $q->fetch(PDO::FETCH_ASSOC) ?: null;

$total   = (int)$conn->query("SELECT COUNT(*) FROM posts p WHERE $type_sql")->fetchColumn();
$n_rest  = $total - ($lead ? 1 : 0);
$pages   = max(1, (int)ceil($n_rest / JOURNAL_PER_PAGE));
$page    = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$offset  = ($page - 1) * JOURNAL_PER_PAGE;

$q = $conn->query(
    "SELECT p.*, u.name AS author_name
     FROM posts p LEFT JOIN users u ON p.uid = u.id
     WHERE $type_sql" . ($lead ? " AND p.id != " . (int)$lead['id'] : "") . "
     ORDER BY COALESCE(p.edited, p.stamp) DESC
     LIMIT " . JOURNAL_PER_PAGE . " OFFSET " . (int)$offset
);
$rest = $q->fetchAll(PDO::FETCH_ASSOC);

// By year, newest first — the same label-and-rule heading The List uses
// for a day, so a long archive reads as sections rather than one long run.
$by_year = [];
foreach ($rest as $p) $by_year[date('Y', $p['edited'] ?: $p['stamp'])][] = $p;

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
    <div class="journal">

      <header class="journal__mast">
        <h1 class="journal__name">The Journal</h1>
        <div class="journal__strap">
          <span>Essays &amp; reviews from Cinema<span class="c">,</span> TX</span>
          <span><?php echo $total; ?> <?php echo $total === 1 ? 'piece' : 'pieces'; ?></span>
        </div>
      </header>

      <?php if (!$lead): ?>
      <p class="empty">Nothing published yet.</p>
      <?php endif; ?>

      <?php if ($lead && $page === 1):
        $lead_url    = ctx_post_url($lead['id'], $lead['title']);
        $lead_images = ctx_post_images($lead);
        $lead_text   = journal_excerpt($lead['content'], 340);
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
          </div>
          <h2 class="jlead__title"><a href="<?php echo $e($lead_url); ?>"><?php echo $e($lead['title']); ?></a></h2>
          <?php if (!empty($lead['subtitle'])): ?>
          <p class="jlead__deck"><?php echo $e($lead['subtitle']); ?></p>
          <?php endif; ?>
          <?php if (!empty($lead['author_name'])): ?>
          <div class="jby">By <a href="/users/profile.php?id=<?php echo (int)$lead['uid']; ?>"><?php echo $e($lead['author_name']); ?></a></div>
          <?php endif; ?>
          <?php if ($lead_text !== ''): ?>
          <p class="jlead__excerpt"><?php echo $e($lead_text); ?></p>
          <?php endif; ?>
          <a class="jmore" href="<?php echo $e($lead_url); ?>">Read in full &rarr;</a>
        </div>
      </article>
      <?php endif; ?>

      <?php foreach ($by_year as $year => $items): ?>
      <section class="jyear">
        <div class="day__label">
          <span class="day__name"><?php echo $e($year); ?></span>
          <span class="day__rule"></span>
          <span class="day__count"><?php echo count($items); ?></span>
        </div>
        <div class="jgrid">
          <?php foreach ($items as $p):
            $imgs = ctx_post_images($p);
          ?>
          <a class="jitem" href="<?php echo $e(ctx_post_url($p['id'], $p['title'])); ?>">
            <?php if ($imgs): ?>
            <span class="jitem__art"><img src="/uploads/posts/<?php echo $e($imgs[0]['file']); ?>" alt="" loading="lazy" /></span>
            <?php endif; ?>
            <span class="jkicker">
              <span class="jkicker__type"><?php echo $e($p['type']); ?></span>
              <span><?php echo date('j F Y', $p['edited'] ?: $p['stamp']); ?></span>
            </span>
            <span class="jitem__title"><?php echo $e($p['title']); ?></span>
            <?php if (!empty($p['subtitle'])): ?>
            <span class="jitem__deck"><?php echo $e($p['subtitle']); ?></span>
            <?php elseif (($teaser = journal_excerpt($p['content'], 170)) !== ''): ?>
            <span class="jitem__excerpt"><?php echo $e($teaser); ?></span>
            <?php endif; ?>
            <?php if (!empty($p['author_name'])): ?>
            <span class="jby">By <?php echo $e($p['author_name']); ?></span>
            <?php endif; ?>
          </a>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endforeach; ?>

      <?php if ($pages > 1): ?>
      <nav class="jpager" aria-label="Pages">
        <?php if ($page > 1): ?>
        <a class="btn btn--quiet" href="/journal/<?php echo $page > 2 ? '?page=' . ($page - 1) : ''; ?>">&larr; Newer</a>
        <?php else: ?><span></span><?php endif; ?>
        <span class="jpager__n">Page <?php echo $page; ?> of <?php echo $pages; ?></span>
        <?php if ($page < $pages): ?>
        <a class="btn btn--quiet" href="/journal/?page=<?php echo $page + 1; ?>">Older &rarr;</a>
        <?php else: ?><span></span><?php endif; ?>
      </nav>
      <?php endif; ?>

    </div>
  </main>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
