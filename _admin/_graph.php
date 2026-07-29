<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Visitor graph
//
//  Included by the dashboard peek and by the Visitors page, so the two can
//  never drift apart. Expects:
//    $g_stats  array  from ctx_visit_stats()
//    $g_peek   bool   compact form — plot only, no axis, bots or legend
//
//  Each column is one day and its full height is connections. The solid red
//  block at the base is unique visitors; the pale block above it is the
//  repeat visits those same people made. So the red is literally the share of
//  that day's traffic that was somebody new.
//
//  Days before connection counting existed have visitors but no hit file. The
//  column then falls back to the visitor figure alone rather than drawing a
//  zero, which would read as "nobody came" instead of "not measured".
// ═══════════════════════════════════════════════════════════════════════════

$g_peek = $g_peek ?? false;
$g_days = $g_stats['recent'];            // day => unique visitors, newest first
$g_hits = $g_stats['recent_hits'];       // day => ['human' => n, 'bot' => n]

$g_scale = 1;
$g_bot_scale = 1;
foreach ($g_days as $day => $uniques) {
    $g_scale     = max($g_scale, $uniques, $g_hits[$day]['human'] ?? 0);
    $g_bot_scale = max($g_bot_scale, $g_hits[$day]['bot'] ?? 0);
}

// Oldest on the left, which is the direction time is read in.
$g_days = array_reverse($g_days, true);
?>
<div class="vis<?php echo $g_peek ? ' vis--peek' : ''; ?>">

  <div class="vis__plot">
    <?php foreach ($g_days as $day => $uniques):
      $human  = $g_hits[$day]['human'] ?? 0;
      $repeat = max(0, $human - $uniques);
      $total  = max($human, $uniques);

      // A floor so a single visit is still a visible mark rather than a hairline.
      $part  = $uniques ? max(4, round($uniques / $g_scale * 100)) : 0;
      $whole = $repeat  ? max(3, round($repeat  / $g_scale * 100)) : 0;

      $tip = date('D j M', strtotime($day)) . "\n"
           . $uniques . ' ' . ($uniques === 1 ? 'visitor' : 'visitors')
           . ' · ' . $total . ' ' . ($total === 1 ? 'visit' : 'visits');
    ?>
    <div class="vis__day" data-tip="<?php echo ctx_e($tip); ?>">
      <?php if (!$part && !$whole): ?>
        <span class="vis__nil"></span>
      <?php else: ?>
        <?php if ($whole): ?><span class="vis__whole" style="height:<?php echo $whole; ?>%"></span><?php endif; ?>
        <?php if ($part):  ?><span class="vis__part"  style="height:<?php echo $part;  ?>%"></span><?php endif; ?>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if (!$g_peek): ?>
    <?php // Bots get their own strip and their own mark. A muted grey sitting
          // beside a muted red is a pair the eye cannot reliably separate, so
          // they are never put in the same stack. ?>
    <div class="vis__bots">
      <?php foreach ($g_days as $day => $_):
        $bots = $g_hits[$day]['bot'] ?? 0;
        $h    = $bots ? max(20, round($bots / $g_bot_scale * 100)) : 0;
      ?>
      <span class="vis__bot" style="height:<?php echo $h; ?>%"></span>
      <?php endforeach; ?>
    </div>

    <div class="vis__axis">
      <?php foreach ($g_days as $day => $_): ?>
      <span class="vis__tick"><?php echo date('j', strtotime($day)); ?></span>
      <?php endforeach; ?>
    </div>

    <div class="vis__legend">
      <span class="vis__key"><span class="vis__swatch vis__swatch--part"></span>Unique visitors</span>
      <span class="vis__key"><span class="vis__swatch vis__swatch--whole"></span>Repeat visits</span>
      <span class="vis__key"><span class="vis__swatch vis__swatch--bot"></span>Bots</span>
    </div>
  <?php endif; ?>

</div>
