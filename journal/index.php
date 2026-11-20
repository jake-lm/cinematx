<?php
// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — The Journal, the archive
//
//  Every published essay and review, laid out the way a modern editorial
//  front page is: one loud lead with a big cover, a short stack of the next
//  stories beside it, then image-forward cards in date order, grouped by
//  year. Underneath it's still The List's own parts — the listing header,
//  filter chips, a view toggle, rows for the plain list — and all of it is
//  ordinary links (?type=, ?view=, ?page=), so there's no script and every
//  state can be bookmarked. The toggles deliberately avoid data-view /
//  data-narrow: the List's script binds those site-wide.
//
//  Same posts the front page's Journal card draws from (type 'essay' or
//  'review'); that card's "Archive" link lands here.
// ═══════════════════════════════════════════════════════════════════════════
require dirname(__DIR__) . '/v7/_lib.php';

const JOURNAL_TYPES = ['essay' => 'Essays', 'review' => 'Reviews'];
const JOURNAL_SIDE  = 3;    // stories stacked beside the lead, page 1 only

$type = isset(JOURNAL_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : null;
$view = ($_GET['view'] ?? '') === 'list' ? 'list' : 'cards';
$per  = $view === 'list' ? 30 : 12;

// $type is whitelisted above, so it's safe to inline.
$where = "p.active = 1 AND p.type IN ('review','essay')" . ($type ? " AND p.type = '$type'" : '');
$sel   = "SELECT p.*, u.name AS author_name, u.photo AS author_photo
          FROM posts p LEFT JOIN users u ON p.uid = u.id";

// Chip counts ignore the current filter — they say how much each one holds.
$counts = $conn->query(
    "SELECT p.type, COUNT(*) FROM posts p
     WHERE p.active = 1 AND p.type IN ('review','essay') GROUP BY p.type"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$n_all = array_sum($counts);

$total = (int)$conn->query("SELECT COUNT(*) FROM posts p WHERE $where")->fetchColumn();

// The lead is the editor's pick — the latest featured piece, or just the
// newest when nothing is featured. Cards view only; the list is a plain
// index. Everything else is ordered by date.
$lead = null;
if ($view === 'cards') {
    $lead = $conn->query("$sel WHERE $where ORDER BY p.featured DESC, p.stamp DESC LIMIT 1")
                 ->fetch(PDO::FETCH_ASSOC) ?: null;
}
$rest_where = $where . ($lead ? " AND p.id != " . (int)$lead['id'] : '');
$order      = "ORDER BY COALESCE(p.edited, p.stamp) DESC";

// The first few after the lead sit beside it on page 1, so the card grid
// starts after them — and on later pages, still skips them.
$skip   = $lead ? JOURNAL_SIDE : 0;
$n_grid = max(0, $total - ($lead ? 1 : 0) - $skip);
$pages  = max(1, (int)ceil($n_grid / $per));
$page   = min(max(1, (int)($_GET['page'] ?? 1)), $pages);

$side = ($lead && $page === 1)
    ? $conn->query("$sel WHERE $rest_where $order LIMIT " . JOURNAL_SIDE)->fetchAll(PDO::FETCH_ASSOC)
    : [];
$grid = $conn->query("$sel WHERE $rest_where $order LIMIT $per OFFSET " . ($skip + ($page - 1) * $per))
             ->fetchAll(PDO::FETCH_ASSOC);

// By year, newest first — the same label-and-rule heading The List uses for
// a day, so a long archive reads as sections rather than one long run.
$by_year = [];
foreach ($grid as $p) $by_year[date('Y', $p['edited'] ?: $p['stamp'])][] = $p;

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

function journal_read_time($content) {
    $words = preg_match_all('/\S+/u', (string)$content);
    return max(1, (int)ceil($words / 220)) . ' min read';
}

/** Byline: avatar (their photo, or an initial), name, reading time. */
function journal_byline(array $p, $link = false) {
    $name = trim((string)($p['author_name'] ?? ''));
    $h = '<span class="jby">';
    if ($name !== '') {
        $h .= !empty($p['author_photo'])
            ? '<img class="jav" src="/uploads/profiles/' . ctx_e($p['author_photo']) . '" alt="" />'
            : '<span class="jav jav--i">' . ctx_e(mb_strtoupper(mb_substr($name, 0, 1))) . '</span>';
        $by = $link
            ? '<a href="/users/profile.php?id=' . (int)$p['uid'] . '">' . ctx_e($name) . '</a>'
            : ctx_e($name);
        $h .= '<span>By ' . $by . '</span><span class="jby__dot">&middot;</span>';
    }
    return $h . '<span class="jby__read">' . journal_read_time($p['content']) . '</span></span>';
}

function journal_date($p, $fmt = 'j M Y') { return date($fmt, $p['edited'] ?: $p['stamp']); }

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
          <span class="jtag">Essays &amp; reviews from Cinema<span class="c">,</span> TX</span>
        </div>

        <div class="listing__controls">
          <div class="seg">
            <a class="seg__btn<?php echo $view === 'cards' ? ' is-on' : ''; ?>" href="<?php echo $e($url(['view' => null])); ?>" title="Cards" aria-label="Cards"><i class="fa-solid fa-table-cells-large"></i></a>
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
        $lead_dek    = !empty($lead['subtitle']) ? $lead['subtitle'] : journal_excerpt($lead['content'], 220);
      ?>
      <section class="jfront<?php echo $side ? '' : ' jfront--solo'; ?>">
        <article class="jhero<?php echo $lead_images ? '' : ' jhero--text'; ?>">
          <?php if ($lead_images): ?>
          <a class="jhero__art" href="<?php echo $e($lead_url); ?>">
            <img src="/uploads/posts/<?php echo $e($lead_images[0]['file']); ?>" alt="" />
          </a>
          <?php endif; ?>
          <div class="jhero__txt">
            <div class="jkicker">
              <?php if (!empty($lead['featured'])): ?><span class="pill pill--featured">Featured</span><?php endif; ?>
              <span class="jkicker__type"><?php echo $e($lead['type'] ?: 'Essay'); ?></span>
              <span><?php echo journal_date($lead, 'j F Y'); ?></span>
            </div>
            <h2 class="jhero__title"><a href="<?php echo $e($lead_url); ?>"><?php echo $e($lead['title']); ?></a></h2>
            <?php if ($lead_dek !== ''): ?><p class="jhero__dek"><?php echo $e($lead_dek); ?></p><?php endif; ?>
            <?php echo journal_byline($lead, true); ?>
          </div>
        </article>

        <?php if ($side): ?>
        <aside class="jside" aria-label="Also in the Journal">
          <?php foreach ($side as $p): $imgs = ctx_post_images($p); ?>
          <a class="jside__item<?php echo $imgs ? '' : ' jside__item--text'; ?>" href="<?php echo $e(ctx_post_url($p['id'], $p['title'])); ?>">
            <span class="jside__txt">
              <span class="jkicker">
                <span class="jkicker__type"><?php echo $e($p['type']); ?></span>
                <span><?php echo journal_date($p); ?></span>
              </span>
              <span class="jside__title"><?php echo $e($p['title']); ?></span>
              <?php echo journal_byline($p); ?>
            </span>
            <?php if ($imgs): ?>
            <span class="jside__art"><img src="/uploads/posts/<?php echo $e($imgs[0]['file']); ?>" alt="" loading="lazy" /></span>
            <?php endif; ?>
          </a>
          <?php endforeach; ?>
        </aside>
        <?php endif; ?>
      </section>
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
            <span class="row__meta"><?php echo journal_date($p); ?></span>
          </a>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="jgrid">
          <?php foreach ($items as $p):
            $imgs = ctx_post_images($p);
            $dek  = !empty($p['subtitle']) ? $p['subtitle'] : journal_excerpt($p['content'], 170);
          ?>
          <a class="jcard<?php echo $imgs ? '' : ' jcard--text'; ?>" href="<?php echo $e(ctx_post_url($p['id'], $p['title'])); ?>">
            <?php if ($imgs): ?>
            <span class="jcard__art"><img src="/uploads/posts/<?php echo $e($imgs[0]['file']); ?>" alt="" loading="lazy" /></span>
            <?php endif; ?>
            <span class="jkicker">
              <span class="jkicker__type"><?php echo $e($p['type']); ?></span>
              <span><?php echo journal_date($p); ?></span>
            </span>
            <span class="jcard__title"><?php echo $e($p['title']); ?></span>
            <?php if ($dek !== ''): ?>
            <span class="jcard__dek<?php echo !empty($p['subtitle']) ? ' jcard__dek--deck' : ''; ?>"><?php echo $e($dek); ?></span>
            <?php endif; ?>
            <?php echo journal_byline($p); ?>
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
