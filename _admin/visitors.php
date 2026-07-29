<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — Visitors
//
//  Two different questions, kept apart: how many people, and how much traffic.
//  The logs answer the first (one line per person per day, deduplicated); the
//  .hits counters answer the second, because the dedup means a visitor who
//  reads nine pages leaves exactly as much trace as one who bounced.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
require dirname(__DIR__) . '/v7/_lib.php';

$v = ctx_visit_stats(14);
$e = 'ctx_e';

$ctx_title     = 'Visitors — Cinema, TX Admin';
$ctx_active    = 'visitors';
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
        <h1 class="adm__title">Visitors</h1>
        <?php if ($v['since']): ?>
        <span class="adm__meta">since <?php echo date('j M Y', $v['since']); ?></span>
        <?php endif; ?>
      </div>

      <div class="adm-figures" style="margin-bottom: var(--s-5);">
        <?php foreach ([
          'People today'    => $v['today'],
          'Visits today'    => $v['hits']['today'],
          'People this week'=> $v['week'],
          'Visits this week'=> $v['hits']['week'],
          'People all time' => $v['total'],
          'Visits all time' => $v['hits']['total'],
        ] as $label => $n): ?>
        <div class="adm-figure">
          <span class="adm-figure__n"><?php echo number_format($n); ?></span>
          <span class="adm-figure__label"><?php echo $e($label); ?></span>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="adm-two">

        <div style="display:grid; gap:var(--s-5);">
          <section class="card adm-card">
            <div class="card__head">
              <span class="card__title">Where they are</span>
            </div>
            <div class="card__body">
              <?php if ($v['total']): ?>
                <?php foreach ($v['bands'] as $band => $n): if (!$n) continue; ?>
                <div class="adm-band">
                  <span><?php echo $e($band); ?></span>
                  <span class="adm-band__bar"><span style="width:<?php echo round($n / $v['total'] * 100); ?>%"></span></span>
                  <span class="adm-band__n"><?php echo $n; ?></span>
                </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="adm-empty">Nothing counted yet</div>
              <?php endif; ?>
            </div>
          </section>

          <section class="card adm-card">
            <div class="card__head">
              <span class="card__title">Places</span>
              <span class="adm-count"><?php echo count($v['places']); ?></span>
            </div>
            <div class="card__body">
              <?php if ($v['places']): ?>
                <?php foreach ($v['places'] as $place => $n): ?>
                <div class="adm-place"><span><?php echo $e($place); ?></span><span><?php echo $n; ?></span></div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="adm-empty">No locations resolved</div>
              <?php endif; ?>
            </div>
          </section>
        </div>

        <div style="display:grid; gap:var(--s-5);">
          <section class="card adm-card">
            <div class="card__head">
              <span class="card__title">Last fourteen days</span>
              <span class="adm-count"><?php echo number_format($v['hits']['month']); ?> visits / 30d</span>
            </div>
            <div class="card__body">
              <?php $g_stats = $v; $g_peek = false; require __DIR__ . '/_graph.php'; ?>
            </div>
          </section>

          <section class="card adm-card">
            <div class="card__head">
              <span class="card__title">Bots</span>
              <span class="adm-count"><?php echo number_format($v['bots']['total']); ?> all time</span>
            </div>
            <div class="card__body">
              <div class="admin-note">
                Crawlers and scanners, counted but kept out of every figure above — they
                outnumber people by a wide margin and would swamp the signal if summed in.
              </div>
              <div class="adm-figures">
                <?php foreach (['Today' => $v['bots']['today'], 'This week' => $v['bots']['week'],
                                'This month' => $v['bots']['month']] as $label => $n): ?>
                <div class="adm-figure">
                  <span class="adm-figure__n"><?php echo number_format($n); ?></span>
                  <span class="adm-figure__label"><?php echo $e($label); ?></span>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </section>
        </div>

      </div>
    </div>
  </main>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
