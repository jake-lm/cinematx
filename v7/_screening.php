<?php
// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — a screening room
//
//  Rendered by /th1/ and /th2/. Always dark, whatever the theme: a lit screen
//  in an unlit room is the whole point, and it is the only place on the site
//  that gets to be immersive.
//
//  Set before including:  $ctx_screen  int  theatre number (1 or 2)
// ═══════════════════════════════════════════════════════════════════════════

$screen  = (int)($ctx_screen ?? 1);
$now     = $CTX_NOW;
$theatre = ctx_theatre($conn, $now, $screen);

$film    = $theatre['film'];
$note    = $theatre['note'];
$is_live = $theatre['is_live'];
$show_ts = $theatre['show_ts'];

// What comes after this one. Strictly future showtimes, so the heading stays
// honest — the slot on screen right now is already named above it.
$q = $conn->prepare(
  "SELECT s.showtime, f.title
   FROM `showtimes` s LEFT JOIN `films` f ON f.id = s.f_id
   WHERE s.`showtime` > :now AND s.`theatre` = :t
   ORDER BY s.`showtime` ASC LIMIT 6"
);
$q->execute([':now' => max($now, $show_ts), ':t' => $screen]);
$upcoming = $q->fetchAll(PDO::FETCH_ASSOC);

$e = 'ctx_e';

// "Sat 3:00am" reads as this Saturday even when it is five days out. Name the
// day only while it is still near; past that, give the date.
$when = function ($ts) use ($now) {
    $d = (int)floor((strtotime('today', $ts) - strtotime('today', $now)) / 86400);
    if ($d === 0) return date('g:ia', $ts);
    if ($d === 1) return 'tomorrow ' . date('g:ia', $ts);
    if ($d < 7)   return date('D g:ia', $ts);
    return date('M j, g:ia', $ts);
};

$ctx_title   = ($film ? $film['title'] . ' — ' : '') . 'Theatre ' . $screen . ' — Cinema, TX';
$ctx_active  = 'theatre';
$ctx_scroll  = false;      // a screening room is one screen by nature
$ctx_video   = true;       // load video.js and the sync globals
$ctx_theatre = $theatre;
$ctx_overlay = false;      // the player is the page here, not an overlay
$ctx_shell   = 'app--screening';   // rail and bar go dark too

// Open Graph, carried over from the old /th1/ — these links get shared.
ob_start(); ?>
<?php if ($film): ?>
<meta property="og:title" content="<?php echo $e($film['title']); ?><?php echo $show_ts ? ' — ' . date('g:ia', $show_ts) : ''; ?>" />
<?php if (!empty($note['note'])): ?>
<meta property="og:description" content="<?php echo $e($note['note']); ?>" />
<?php endif; ?>
<?php if (!empty($film['poster'])): ?>
<meta property="og:image" content="/motw/<?php echo $e($film['poster']); ?>.png" />
<?php endif; ?>
<?php endif; ?>
<?php $ctx_meta = ob_get_clean();

require __DIR__ . '/_head.php';
require __DIR__ . '/_chrome.php';
?>

  <main class="canvas canvas--screening">
    <div class="screening">

      <div class="screening__stage" id="screening-stage">
        <?php if ($film): ?>
        <?php // The player is only mounted when there is something to play —
              // otherwise initScreening() finds nothing and stays out of the way. ?>
        <?php // muted: autoplay is only permitted silent, and startSync unmutes
              // via the nudge. playsinline: iOS otherwise takes it fullscreen. ?>
        <video id="screening-player" class="video-js" oncontextmenu="return false;"
               preload="none" muted playsinline
               poster="<?php echo !empty($film['poster']) ? '/motw/' . $e($film['poster']) . '.png' : ''; ?>"></video>
        <?php endif; ?>
      </div>

      <aside class="screening__info">
        <div class="screening__head">
          <?php if ($is_live): ?>
          <span class="screening__kicker"><span class="dot"></span> On screen now</span>
          <?php else: ?>
          <span class="screening__kicker">Theatre <?php echo $screen; ?><?php echo $show_ts ? ' &middot; next ' . $when($show_ts) : ''; ?></span>
          <?php endif; ?>

          <h1 class="screening__title">
            <?php if ($film && !empty($film['wiki'])): ?>
            <a href="<?php echo $e($film['wiki']); ?>" target="_blank" rel="noopener"><?php echo $e($film['title']); ?></a>
            <?php else: ?><?php echo $film ? $e($film['title']) : 'Dark tonight'; ?><?php endif; ?>
          </h1>

          <?php if ($film): ?>
          <div class="screening__credits">
            <?php if (!empty($film['director'])): ?><span><?php echo $e($film['director']); ?></span><?php endif; ?>
            <?php if (!empty($film['dur'])): ?><span><?php echo gmdate('G\hi\m', (int)$film['dur']); ?></span><?php endif; ?>
            <?php if ($show_ts): ?><span><?php echo date('g:ia', $show_ts); ?></span><?php endif; ?>
          </div>
          <?php endif; ?>
        </div>

        <div class="screening__body">
          <?php if (!$film): ?>
          <p class="screening__note">Nothing is booked on this screen. <a href="/list">The List</a> has what
            is playing around town, and <a href="<?php echo CTX_HOME; ?>">tonight</a> has the other theatre.</p>
          <?php endif; ?>

          <?php if (!empty($note['note'])): ?>
          <p class="screening__note">
            <?php echo $e($note['note']); ?>
            <?php if (!empty($film['program'])): ?><span class="prog">— <?php echo $e($film['program']); ?></span><?php endif; ?>
          </p>
          <?php endif; ?>

          <?php if ($film): ?>
          <?php // Carried over from the old page, which asked on every screening. ?>
          <p class="screening__ask"><a href="/about">Please consider donating.</a></p>
          <?php endif; ?>

          <?php if ($upcoming): ?>
          <div class="screening__next">
            <div class="screening__next-head">Up next</div>
            <?php foreach ($upcoming as $u): ?>
            <div class="screening__slot">
              <span class="screening__slot-time"><?php echo $when($u['showtime']); ?></span>
              <span class="screening__slot-title"><?php echo $e($u['title']); ?></span>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <?php if ($film): ?>
        <div class="screening__foot">
          <?php // Transport is deliberately absent: the showtime decides where
                // you are in the film. All that is yours is the volume. ?>
          <button class="ctrl" id="ctrl-mute" title="Mute"><i class="fa-solid fa-volume-high"></i></button>
          <input class="vol" id="ctrl-vol" type="range" min="0" max="1" step="0.05" value="1" />
          <button class="ctrl" id="ctrl-full" title="Fullscreen"><i class="fa-solid fa-expand"></i></button>
          <span class="screening__sync"><?php echo $is_live
            ? 'Synced to showtime'
            : 'Begins ' . $when($show_ts); ?></span>
        </div>
        <?php endif; ?>
      </aside>

    </div>
  </main>

<?php require __DIR__ . '/_foot.php'; ?>
