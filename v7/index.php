<?php
// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — front page
//  Weight order: 1 The List · 2 The Journal · 3 The Theatre · 4 The Room
//  The only surface locked to a single screen.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_lib.php';

$now     = $CTX_NOW;
$state   = ctx_state($conn);
$signed  = $state !== 'guest';
$me      = ctx_me($conn);

// Someone mid-signup or awaiting an access code gets the gate, not the front
// page. Four states, not two — see ctx_state().
if ($state === 'onboard' || $state === 'gated') {
    $ctx_title  = $state === 'gated' ? 'Access code — Cinema, TX' : 'Welcome — Cinema, TX';
    $ctx_active = 'index';
    $ctx_scroll = true;      // a form should never be trapped in a fixed panel
    $ctx_video  = false;
    $ctx_gate   = $state;

    require __DIR__ . '/_head.php';
    require __DIR__ . '/_chrome.php';
    require __DIR__ . '/_gate.php';
    require __DIR__ . '/_foot.php';
    exit;
}

$tonight = ctx_tonight($conn, $now);
$films   = $tonight['screenings'];
$label   = $tonight['label'];
$venues  = ctx_venues($films);
$counts  = ctx_venue_counts($films);

$theatre = ctx_theatre($conn, $now);
$film    = $theatre['film'];
$note    = $theatre['note'];
$is_live = $theatre['is_live'];
$show_ts = $theatre['show_ts'];

$journal = ctx_journal($conn, 4);
$lead    = $journal['lead'];
$items   = $journal['items'];

$notes   = ctx_room($conn, 10);
$dir     = ctx_members($conn, 7);

// Shell configuration
$ctx_title   = 'Cinema, TX';
$ctx_active  = 'index';
$ctx_scroll  = false;          // the index never scrolls
$ctx_video   = true;
$ctx_theatre = $theatre;

$e = 'ctx_e';

require __DIR__ . '/_head.php';
require __DIR__ . '/_chrome.php';
?>

  <main class="canvas">
    <div class="canvas__in">

      <!-- ── 01 · THE LIST — rank 1 ──────────────────────────────────── -->
      <section class="card">
        <div class="card__head">
          <span class="card__n">01</span>
          <span class="card__title"><?php echo $e($label); ?> in Austin</span>
          <a class="card__more" href="/list"><span id="list-count"><?php echo count($films); ?></span> screenings &rarr;</a>
        </div>

        <div class="controls">
          <div class="seg">
            <button class="seg__btn is-on" data-view="grid" title="Posters"><i class="fa-solid fa-grip"></i></button>
            <button class="seg__btn" data-view="rows" title="List"><i class="fa-solid fa-list"></i></button>
          </div>

          <div class="chips">
            <button class="chip is-on" data-venue-filter="all">All<span class="chip__n"><?php echo count($films); ?></span></button>
            <?php foreach ($venues as $v): $vs = ctx_slug($v); ?>
            <button class="chip" data-venue-filter="<?php echo $e($vs); ?>">
              <?php echo $e(ctx_venue_short($v)); ?>
              <span class="chip__n"><?php echo (int)($counts[$vs] ?? 0); ?></span>
            </button>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="card__body">
          <?php if (!$films): ?>
            <p class="empty">Nothing listed</p>
          <?php else: ?>

          <div class="grid-view" id="grid-view">
            <?php foreach ($films as $s):
              $member = ($s['source'] ?? '') === 'user';
              $href   = !empty($s['url']) ? $s['url'] : '/list';
              $other  = date('j M', $s['timestamp']) !== date('j M', $now);
            ?>
            <a class="shot<?php echo $member ? ' shot--member' : ''; ?>"
               data-venue="<?php echo $e(ctx_slug($s['venue'])); ?>"
               href="<?php echo $e($href); ?>"<?php echo $member ? '' : ' target="_blank" rel="noopener"'; ?>>
              <span class="shot__art">
                <?php if (!empty($s['poster'])): ?>
                <img src="<?php echo $e($s['poster']); ?>" alt="<?php echo $e($s['display_title']); ?>" loading="lazy" />
                <?php else: ?>
                <span class="shot__blank"><?php echo $e($s['display_title']); ?></span>
                <?php endif; ?>
                <span class="shot__time"><?php echo date('g:ia', $s['timestamp']); ?><?php echo $other ? ' · ' . date('D', $s['timestamp']) : ''; ?></span>
              </span>
              <span class="shot__title"><?php echo $e($s['display_title']); ?><?php if (!empty($s['series'])): ?><span class="shot__series"><?php echo $e($s['series']); ?></span><?php endif; ?></span>
              <span class="shot__venue">
                <?php if ($member): ?><span class="shot__by">&#9679; By a member</span><?php else: ?><?php echo $e($s['venue']); ?><?php endif; ?>
              </span>
            </a>
            <?php endforeach; ?>
            <p class="empty" id="grid-empty" style="display:none; grid-column:1/-1;">Nothing at that venue</p>
          </div>

          <div class="rows-view" id="rows-view">
            <?php foreach ($films as $s):
              $member = ($s['source'] ?? '') === 'user';
              $href   = !empty($s['url']) ? $s['url'] : '/list';
              $other  = date('j M', $s['timestamp']) !== date('j M', $now);
            ?>
            <a class="line<?php echo $member ? ' line--member' : ''; ?>"
               data-venue="<?php echo $e(ctx_slug($s['venue'])); ?>"
               href="<?php echo $e($href); ?>"<?php echo $member ? '' : ' target="_blank" rel="noopener"'; ?>>
              <span class="line__time"><?php echo date('g:ia', $s['timestamp']); ?></span>
              <span>
                <span class="line__title"><?php echo $e($s['display_title']); ?><?php if (!empty($s['series'])): ?><span class="line__series"><?php echo $e($s['series']); ?></span><?php endif; ?></span>
                <span class="line__sub">
                  <?php if ($member): ?><span class="shot__by">&#9679; By a member</span> &middot; <?php endif; ?>
                  <?php echo $e($s['venue']); ?><?php echo $other ? ' · ' . date('D j M', $s['timestamp']) : ''; ?>
                </span>
              </span>
              <span class="line__venue"><?php echo $other ? date('D', $s['timestamp']) : 'Today'; ?></span>
            </a>
            <?php endforeach; ?>
            <p class="empty" id="rows-empty" style="display:none;">Nothing at that venue</p>
          </div>

          <?php endif; ?>
        </div>
      </section>

      <!-- ── SIDE — ranks 2, 3, 4 ────────────────────────────────────── -->
      <div class="side">

        <!-- 02 · The Journal -->
        <section class="card">
          <div class="card__head">
            <span class="card__n">02</span>
            <span class="card__title">The Journal</span>
            <a class="card__more" href="/list">Archive &rarr;</a>
          </div>
          <div class="card__body">
            <?php if ($lead): ?>
            <article class="lead">
              <div class="lead__kicker">
                <span><?php echo $e($lead['type'] ?: 'Essay'); ?></span>
                <span><?php echo date('j F Y', $lead['edited'] ?: $lead['stamp']); ?></span>
              </div>
              <h1 class="lead__title"><?php echo $e($lead['title']); ?></h1>
              <?php if (!empty($lead['subtitle'])): ?>
              <p class="lead__deck"><?php echo $e($lead['subtitle']); ?></p>
              <?php endif; ?>
              <div class="lead__by">
                <?php if (!empty($lead['author_name'])): ?>
                <a href="/users/profile.php?id=<?php echo (int)$lead['uid']; ?>"><?php echo $e($lead['author_name']); ?></a>
                <?php endif; ?>
              </div>
              <div class="lead__wrap">
                <div class="lead__body"><?php echo nl2br($e($lead['content'])); ?></div>
                <div class="lead__fade"></div>
              </div>
              <div class="lead__foot"><button class="btn btn--quiet" data-open="essay">Read in full</button></div>
            </article>
            <?php endif; ?>

            <?php if ($items): ?>
            <div class="strip">
              <div class="strip__head">More writing</div>
              <?php foreach ($items as $p): ?>
              <a class="row" href="<?php echo $e(ctx_post_url($p['id'], $p['title'])); ?>">
                <span class="row__type"><?php echo $e($p['type']); ?></span>
                <span class="row__title"><?php echo $e($p['title']); ?></span>
              </a>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
        </section>

        <!-- 03 · The Theatre -->
        <section class="theatre-card" id="theatre-card">
          <div class="theatre-card__in">
            <div class="theatre-card__poster" <?php if ($film): ?>style="background-image:url('/motw/<?php echo $e($film['poster']); ?>.png')"<?php endif; ?>></div>
            <div class="theatre-card__meta">
              <?php if ($is_live): ?>
                <span class="theatre-card__kicker"><span class="dot"></span> 03 &mdash; On screen now</span>
              <?php else: ?>
                <span class="theatre-card__kicker">03 &mdash; The Theatre<?php echo $show_ts ? ' · ' . date('D g:ia', $show_ts) : ''; ?></span>
              <?php endif; ?>
              <h2 class="theatre-card__title"><?php echo $film ? $e($film['title']) : 'Dark tonight'; ?></h2>
              <?php if ($film): ?>
              <div class="theatre-card__credits"><?php echo $e($film['director']); ?> &middot; Everyone watches at once</div>
              <?php endif; ?>
            </div>
          </div>

          <?php if (!empty($note['note'])): ?>
          <p class="theatre-card__note">
            <?php echo $e($note['note']); ?>
            <?php if (!empty($film['program'])): ?><span class="prog">— <?php echo $e($film['program']); ?></span><?php endif; ?>
          </p>
          <?php endif; ?>
        </section>

        <!-- 04 · The Room -->
        <section class="card">
          <div class="card__head">
            <span class="card__n">04</span>
            <span class="card__title">The Room</span>
            <a class="card__more" href="/directory"><?php echo $dir['count']; ?> members &rarr;</a>
          </div>
          <div class="card__body">
            <?php if ($signed): ?>
            <div class="compose">
              <textarea class="compose__area" id="compose-area" placeholder="What did you see?" maxlength="600"></textarea>
              <div class="compose__foot">
                <button class="btn" id="compose-send" disabled>Post</button>
                <span class="compose__count" id="compose-count">0/600</span>
              </div>
            </div>
            <?php else: ?>
            <p class="pitch">Members keep a running note of what they're watching, and <em>put on their own screenings</em>.</p>
            <?php endif; ?>

            <div id="notes">
              <?php foreach ($notes as $n): ?>
              <div class="note" data-post-id="<?php echo (int)$n['id']; ?>">
                <?php if ($me && $n['uid'] == $me['id']): ?>
                <button class="note__del" data-post-id="<?php echo (int)$n['id']; ?>" title="Delete">&times;</button>
                <?php endif; ?>
                <div class="note__text"><?php echo nl2br($e($n['content'])); ?></div>
                <div class="note__meta">
                  <a href="/users/profile.php?id=<?php echo (int)$n['uid']; ?>"><?php echo $e($n['author_name'] ?: 'Member'); ?></a>
                  <span><?php echo date('j M', $n['stamp']); ?></span>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </section>

      </div>
    </div>
  </main>

<?php
// Page-specific overlay: the lead essay in full.
if ($lead):
  ob_start(); ?>
<div class="sheet" id="sheet-essay">
  <div class="sheet__head">
    <span class="sheet__title"><?php echo $e($lead['title']); ?></span>
    <button class="ibtn sheet__x" data-close title="Close"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="sheet__body">
    <?php if (!empty($lead['subtitle'])): ?><p class="lead__deck"><?php echo $e($lead['subtitle']); ?></p><?php endif; ?>
    <div class="lead__by">
      <?php if (!empty($lead['author_name'])): ?><span><?php echo $e($lead['author_name']); ?></span><?php endif; ?>
      <span><?php echo date('j F Y', $lead['edited'] ?: $lead['stamp']); ?></span>
    </div>
    <div class="prose"><?php echo nl2br($e($lead['content'])); ?></div>
  </div>
</div>
<?php $ctx_extra_sheets = ob_get_clean(); endif;

require __DIR__ . '/_foot.php';
