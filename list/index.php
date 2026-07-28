<?php
// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — The List
//
//  Everything playing in Austin over the next seven days, grouped by day.
//  Carries forward the old page's controls (Today / Tomorrow / This week and
//  the member-submitted filter) and adds v7's poster grid, row view, venue
//  narrowing and series parsing.
//
//  A content page: it scrolls normally. Only the index is one screen.
// ═══════════════════════════════════════════════════════════════════════════
require dirname(__DIR__) . '/v7/_lib.php';

$now = $CTX_NOW;
$tz  = new DateTimeZone('America/Chicago');
$end = $now + 7 * 86400;

$films = ctx_enrich(fetch_all_screenings($conn, $now, $end));

// Chips and the header count screenings. Folding happens after, so a venue
// that collapses into one card still reports how much it is actually showing.
$venues     = ctx_venues($films);
$counts     = ctx_venue_counts($films);
$by_member  = count(array_filter($films, fn($f) => ($f['source'] ?? '') === 'user'));
$n_screenings = count($films);

// Alamo runs five cinemas and books the same film across them; a day of it is
// a schedule rather than a listing. See ctx_fold_venue().
$entries = ctx_fold_venue($films, 'Alamo Drafthouse', 3);

// Every folded card opens a panel; they are collected as we render.
$ctx_extra_sheets = '';

// Group by calendar day, preserving chronological order.
$days = [];
foreach ($entries as $f) {
    $dt  = (new DateTime('@' . $f['timestamp']))->setTimezone($tz);
    $key = $dt->format('Ymd');
    if (!isset($days[$key])) {
        $days[$key] = ['label' => $dt->format('l, j F'), 'films' => []];
    }
    $days[$key]['films'][] = $f;
}

$today_key = (new DateTime('today', $tz))->format('Ymd');
$tmrw_key  = (new DateTime('tomorrow', $tz))->format('Ymd');

// Shell
$ctx_title  = 'The List — Cinema, TX';
$ctx_active = 'list';
$ctx_scroll = true;
$ctx_video  = false;

$e = 'ctx_e';

/** One screening — or one folded venue-day — rendered for whichever view. */
function ctx_screening($s, $view) {
    $e      = 'ctx_e';
    $member = ($s['source'] ?? '') === 'user';
    $group  = !empty($s['is_group']);
    $href   = !empty($s['url']) ? $s['url'] : '#';
    $ext    = ($member || $group) ? '' : ' target="_blank" rel="noopener"';

    // data-count is what the header adds up. A folded card stands for all its
    // showings, so counting elements would understate the page and disagree
    // with the venue chips beside it.
    $attrs  = 'data-venue="' . $e(ctx_slug($s['venue'])) . '"'
            . ' data-source="' . ($member ? 'user' : 'venue') . '"'
            . ' data-count="' . ($group ? (int)$s['n_showings'] : 1) . '"'
            . ($group ? ' data-open="' . $e($s['group_id']) . '"' : ctx_screening_hover($s));

    if ($group) { ctx_fold_card($s, $view); ctx_fold_children($s, $view); return; }

    if ($view === 'grid') { ?>
      <a class="shot<?php echo $member ? ' shot--member' : ''; ?>" <?php echo $attrs; ?> href="<?php echo $e($href); ?>"<?php echo $ext; ?>>
        <span class="shot__art">
          <?php if (!empty($s['poster'])): ?>
          <img src="<?php echo $e($s['poster']); ?>" alt="<?php echo $e($s['display_title']); ?>" loading="lazy" />
          <?php else: ?>
          <span class="shot__blank"><?php echo $e($s['display_title']); ?></span>
          <?php endif; ?>
          <span class="shot__time"><?php echo date('g:ia', $s['timestamp']); ?></span>
        </span>
        <span class="shot__title"><?php echo $e($s['display_title']); ?><?php if (!empty($s['series'])): ?><span class="shot__series"><?php echo $e($s['series']); ?></span><?php endif; ?></span>
        <span class="shot__venue">
          <?php // Poster cards are ~120px wide, so the venue is abbreviated here
                // to leave room for the year and runtime beside it. ?>
          <?php if ($member): ?><span class="shot__by">&#9679; By a member</span>
          <?php else: ?><?php echo $e(implode(' · ', array_merge([ctx_venue_short($s['venue'])], ctx_bits($s, false)))); ?><?php endif; ?>
        </span>
      </a>
    <?php } else { ?>
      <a class="line<?php echo $member ? ' line--member' : ''; ?>" <?php echo $attrs; ?> href="<?php echo $e($href); ?>"<?php echo $ext; ?>>
        <span class="line__time"><?php echo date('g:ia', $s['timestamp']); ?></span>
        <span>
          <span class="line__title"><?php echo $e($s['display_title']); ?><?php if (!empty($s['series'])): ?><span class="line__series"><?php echo $e($s['series']); ?></span><?php endif; ?></span>
          <span class="line__sub">
            <?php if ($member): ?><span class="shot__by">&#9679; By a member</span> &middot; <?php endif; ?>
            <?php echo $e(implode(' · ', ctx_bits($s))); ?>
          </span>
        </span>
        <span class="line__venue"><?php echo date('D', $s['timestamp']); ?></span>
      </a>
    <?php }
}

require dirname(__DIR__) . '/v7/_head.php';
require dirname(__DIR__) . '/v7/_chrome.php';
?>

  <main class="canvas">
    <div class="listing" id="listing" data-today="<?php echo $today_key; ?>" data-tmrw="<?php echo $tmrw_key; ?>">

      <div class="listing__head">
        <div class="listing__title">
          <span class="card__n">01</span>
          <h1 class="listing__h">The List</h1>
          <?php // The scope word is driven by the Week/Today/Tomorrow control,
                // not baked in — it used to keep saying "next seven days" while
                // the number beside it changed to today's. ?>
          <span class="listing__sub"><span id="list-count"><?php echo $n_screenings; ?></span>
            <span id="list-noun"><?php echo $n_screenings === 1 ? 'screening' : 'screenings'; ?></span>
            &middot; <span id="list-scope">next seven days</span></span>
        </div>

        <div class="listing__controls">
          <div class="seg">
            <button class="seg__btn is-on" data-when="week">Week</button>
            <button class="seg__btn" data-when="today">Today</button>
            <button class="seg__btn" data-when="tmrw">Tomorrow</button>
          </div>

          <div class="seg">
            <button class="seg__btn is-on" data-view="grid" title="Posters"><i class="fa-solid fa-grip"></i></button>
            <button class="seg__btn" data-view="rows" title="List"><i class="fa-solid fa-list"></i></button>
          </div>

          <!-- Venue and member-submitted collapse into one narrowing control,
               so the page asks for two decisions rather than three. -->
          <div class="chips">
            <button class="chip is-on" data-narrow="all">All<span class="chip__n"><?php echo $n_screenings; ?></span></button>
            <?php foreach ($venues as $v): $vs = ctx_slug($v); ?>
            <button class="chip" data-narrow="venue:<?php echo $e($vs); ?>">
              <?php echo $e(ctx_venue_short($v)); ?><span class="chip__n"><?php echo (int)($counts[$vs] ?? 0); ?></span>
            </button>
            <?php endforeach; ?>
            <?php if ($by_member): ?>
            <button class="chip chip--member" data-narrow="source:user">By members<span class="chip__n"><?php echo $by_member; ?></span></button>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php if (!$days): ?>
        <p class="empty">Nothing listed in the next seven days.</p>
      <?php else: foreach ($days as $key => $day): ?>
      <section class="day" data-day="<?php echo $key; ?>">
        <div class="day__label">
          <span class="day__name"><?php echo $e($day['label']); ?></span>
          <?php // Cast: PHP turns numeric-string array keys into ints, so a
                // strict compare against the string key silently never matches. ?>
          <?php if ((string)$key === $today_key): ?><span class="day__flag">Today</span>
          <?php elseif ((string)$key === $tmrw_key): ?><span class="day__flag day__flag--soft">Tomorrow</span><?php endif; ?>
          <span class="day__rule"></span>
          <span class="day__count"><?php echo count($day['films']); ?></span>
        </div>

        <div class="grid-view">
          <?php foreach ($day['films'] as $s) {
                  if (!empty($s['is_group'])) $ctx_extra_sheets .= ctx_group_sheet($s);
                  ctx_screening($s, 'grid');
                } ?>
        </div>
        <div class="rows-view">
          <?php foreach ($day['films'] as $s) ctx_screening($s, 'rows'); ?>
        </div>
      </section>
      <?php endforeach; endif; ?>

      <p class="empty" id="list-empty" style="display:none;">Nothing matches that.</p>
    </div>
  </main>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
