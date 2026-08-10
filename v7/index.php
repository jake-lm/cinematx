<?php
// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — front page
//  Weight order: 1 The List · 2 The Journal · 3 The Theatre
//  (4 The Directory is parked — see "04 · The Directory" below)
//  The only surface locked to a single screen.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_lib.php';

$now     = $CTX_NOW;
$state   = ctx_state($conn);
$signed  = $state !== 'guest';

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

// Chips and the header count screenings, so all of this is computed before
// folding — otherwise Alamo's chip reports the number of cards it collapsed
// into (2) rather than what it is showing (23), and the chips stop summing to
// the total beside them.
$n_screenings = count($films);
$venues       = ctx_venues($films);
$counts       = ctx_venue_counts($films);

// A compact front-page module has even less room for a chain's schedule than
// The List does. Two days, so the threshold is lower.
$films = ctx_fold_venue($films, 'Alamo Drafthouse', 2);
$films = ctx_fold_venue($films, 'Fathom Events', 2);

// A festival's own threshold (3+ distinct films in a day) is fixed rather
// than tuned per page — see ctx_fold_festival().
$films = ctx_fold_festival($films);

// Then a single film with more than one showing across the two days — an
// alamo stack of its own, so two Spirited Aways read as one film on two
// evenings rather than as a duplicate.
$films = ctx_fold_repeats($films);
$ctx_extra_sheets = '';
foreach ($films as $s) if (!empty($s['is_group'])) $ctx_extra_sheets .= ctx_group_sheet($s);
$label   = $tonight['label'];

$theatre = ctx_theatre($conn, $now);
$film    = $theatre['film'];
$note    = $theatre['note'];
$is_live = $theatre['is_live'];
$show_ts = $theatre['show_ts'];

$journal = ctx_journal($conn, 4);
$lead    = $journal['lead'];
$items   = $journal['items'];

// The Directory card is parked below (search "04 · The Directory") — no
// point paying for this query while nothing renders it.
// $dir = ctx_members($conn, 7);

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
          <a class="card__more" href="/list"><span id="list-count"><?php echo $n_screenings; ?></span>
                <span id="list-noun"><?php echo $n_screenings === 1 ? 'screening' : 'screenings'; ?></span> &rarr;</a>
        </div>

        <div class="controls">
          <div class="seg">
            <button class="seg__btn" data-view="depth" title="In depth"><i class="fa-solid fa-square"></i></button>
            <button class="seg__btn is-on" data-view="grid" title="Posters"><i class="fa-solid fa-grip"></i></button>
            <button class="seg__btn" data-view="rows" title="List"><i class="fa-solid fa-list"></i></button>
          </div>

          <div class="chips">
            <button class="chip is-on" data-narrow="all">All<span class="chip__n"><?php echo $n_screenings; ?></span></button>
            <?php foreach ($venues as $v): $vs = ctx_slug($v); ?>
            <button class="chip" data-narrow="venue:<?php echo $e($vs); ?>">
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
              if (!empty($s['is_group'])) { ctx_fold_card($s, 'grid'); ctx_fold_children($s, 'grid'); continue; }
              $member = ($s['source'] ?? '') === 'user';
              $href   = !empty($s['url']) ? $s['url'] : '/list';
              $other  = date('j M', $s['timestamp']) !== date('j M', $now);
            ?>
            <?php $times = ctx_time_lines($s['showings'], $now); $stack = count($times) > 1; ?>
            <a class="shot<?php echo $member ? ' shot--member' : ''; ?><?php echo $stack ? ' shot--fold' : ''; ?>"
               data-venue="<?php echo $e(ctx_slug($s['venue'])); ?>" data-source="<?php echo $member ? 'user' : 'venue'; ?>"
               data-count="<?php echo count($times); ?>"<?php echo ctx_screening_hover($s); ?>
               href="<?php echo $e($href); ?>"<?php echo $member ? '' : ' target="_blank" rel="noopener"'; ?>>
              <span class="shot__art<?php echo $stack ? ' fold__stack' : ''; ?>">
                <?php if (!empty($s['festival'])): ?><span class="shot__festival"><?php echo $e($s['festival']); ?></span><?php endif; ?>
                <?php if (!empty($s['poster'])): ?>
                  <?php // One face per showing, capped at three — the stack says
                        // "more than one evening" without needing to be counted. ?>
                  <?php for ($i = min(count($times), 3); $i >= 1; $i--): ?>
                  <img<?php echo $stack ? ' class="fold__face fold__face--' . $i . '"' : ''; ?> src="<?php echo $e($s['poster']); ?>" alt="<?php echo $e($s['display_title']); ?>" loading="lazy" />
                  <?php if (!$stack) break; ?>
                  <?php endfor; ?>
                <?php else: ?>
                <span class="shot__blank"><?php echo $e($s['display_title']); ?></span>
                <?php endif; ?>
                <span class="shot__time"><?php foreach ($times as $l): ?><span class="shot__t"><?php echo $e($l); ?></span><?php endforeach; ?></span>
              </span>
              <span class="shot__title"><?php echo $e($s['display_title']); ?><?php if (!empty($s['series'])): ?><span class="shot__series"><?php echo $e($s['series']); ?></span><?php endif; ?></span>
              <span class="shot__venue">
                <?php // Abbreviated venue, so year and runtime fit beside it. ?>
                <?php if ($member): ?><span class="shot__by">&#9679; By a member</span>
                <?php else: ?><?php echo $e(implode(' · ', array_merge([ctx_venue_short($s['venue'])], ctx_bits($s, false)))); ?><?php endif; ?>
              </span>
            </a>
            <?php endforeach; ?>
            <p class="empty" id="grid-empty" style="display:none; grid-column:1/-1;">Nothing at that venue</p>
          </div>

          <div class="rows-view" id="rows-view">
            <?php foreach ($films as $s):
              if (!empty($s['is_group'])) { ctx_fold_card($s, 'rows'); ctx_fold_children($s, 'rows'); continue; }
              $member = ($s['source'] ?? '') === 'user';
              $href   = !empty($s['url']) ? $s['url'] : '/list';
              $other  = date('j M', $s['timestamp']) !== date('j M', $now);
            ?>
            <a class="line<?php echo $member ? ' line--member' : ''; ?>"
               data-venue="<?php echo $e(ctx_slug($s['venue'])); ?>" data-source="<?php echo $member ? 'user' : 'venue'; ?>"
               data-count="<?php echo count($s['showings'] ?? [1]); ?>"<?php echo ctx_screening_hover($s); ?>
               href="<?php echo $e($href); ?>"<?php echo $member ? '' : ' target="_blank" rel="noopener"'; ?>>
              <span class="line__time"><?php echo date('g:ia', $s['timestamp']); ?></span>
              <span>
                <span class="line__title"><?php echo $e($s['display_title']); ?>
                  <?php if (!empty($s['festival'])): ?><span class="line__festival"><?php echo $e($s['festival']); ?></span>
                  <?php elseif (!empty($s['series'])): ?><span class="line__series"><?php echo $e($s['series']); ?></span><?php endif; ?>
                </span>
                <span class="line__sub">
                  <?php if ($member): ?><span class="shot__by">&#9679; By a member</span> &middot; <?php endif; ?>
                  <?php echo $e(implode(' · ', ctx_bits($s))); ?>
                  &middot; <?php echo $e(implode(' · ', ctx_time_lines($s['showings'], $now))); ?>
                </span>
              </span>
              <span class="line__venue"><?php echo $other ? date('D', $s['timestamp']) : 'Today'; ?></span>
            </a>
            <?php endforeach; ?>
            <p class="empty" id="rows-empty" style="display:none;">Nothing at that venue</p>
          </div>

          <div class="depth-view" id="depth-view">
            <?php foreach ($films as $s) ctx_deep_card($s, $now); ?>
            <p class="empty" id="depth-empty" style="display:none;">Nothing at that venue</p>
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
            <?php
              $lead_url    = ctx_post_url($lead['id'], $lead['title']);
              $lead_images = ctx_post_images($lead);
            ?>
            <article class="lead">
              <?php // Two or three images fade between each other for the same
                    // reason the alamo stack exists — motion earns a glance a
                    // static card would not get. ?>
              <?php if ($lead_images): ?>
              <a class="lead__art<?php echo count($lead_images) > 1 ? ' lead__art--cycle' : ''; ?>" href="<?php echo $e($lead_url); ?>">
                <?php // Not lazy: this sits near the top of the front page, so deferring
                    // it only delays the one image the page actually leads with. ?>
                <?php foreach ($lead_images as $i => $img): ?>
                <img class="<?php echo $i === 0 ? 'is-on' : ''; ?>" src="/uploads/posts/<?php echo $e($img['file']); ?>" alt="" />
                <?php endforeach; ?>
              </a>
              <?php endif; ?>
              <div class="lead__kicker">
                <span><?php echo $e($lead['type'] ?: 'Essay'); ?></span>
                <span><?php echo date('j F Y', $lead['edited'] ?: $lead['stamp']); ?></span>
              </div>
              <h1 class="lead__title"><a href="<?php echo $e($lead_url); ?>"><?php echo $e($lead['title']); ?></a></h1>
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
              <div class="lead__foot"><a class="btn btn--quiet" href="<?php echo $e($lead_url); ?>">Read in full</a></div>
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
        <section class="theatre-card<?php echo $is_live ? ' theatre-card--live' : ''; ?>" id="theatre-card">
          <div class="theatre-card__in">
            <div class="theatre-card__poster" <?php if ($film): ?>style="background-image:url('/motw/<?php echo $e($film['poster']); ?>.png')"<?php endif; ?>>
              <?php // A little synced mirror of the real playback — muted, no
                    // controls, the same diff-from-showtime math as the full
                    // overlay. Only when something is actually on screen; the
                    // "next showing" state stays a still poster. ?>
              <?php if ($is_live): ?>
              <video id="theatre-card-preview" muted playsinline preload="none"
                     poster="/motw/<?php echo $e($film['poster']); ?>.png"></video>
              <?php endif; ?>
            </div>
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

        <?php // 04 · The Directory — parked, not removed. Flip to `if (true)`
              // to bring it back; .side's grid-template-rows (css/v7.scss)
              // was widened to give the Journal the space this used to take,
              // and would need a third row again too. ?>
        <?php if (false): ?>
        <!-- 04 · The Directory -->
        <section class="card">
          <div class="card__head">
            <span class="card__n">04</span>
            <span class="card__title">The Directory</span>
            <?php // /directory redirects guests to the front page, so it is a
                  // dead end for them — show the count without the link. ?>
            <?php if ($signed): ?>
            <a class="card__more" href="/directory"><?php echo $dir['count']; ?> members &rarr;</a>
            <?php else: ?>
            <span class="card__more card__more--flat"><?php echo $dir['count']; ?> members</span>
            <?php endif; ?>
          </div>
          <div class="card__body">
            <?php if ($signed): ?>
            <?php foreach ($dir['members'] as $m): ?>
            <a class="dir-row" href="/users/profile.php?id=<?php echo (int)$m['id']; ?>">
              <span class="member__face">
                <?php if (!empty($m['photo'])): ?>
                <img src="/uploads/profiles/<?php echo $e($m['photo']); ?>" alt="" />
                <?php else: ?><?php echo $e(mb_substr($m['name'], 0, 1)); ?><?php endif; ?>
              </span>
              <span class="member__body">
                <span class="member__name"><?php echo $e($m['name']); ?></span>
                <?php $m_top = array_column(ctx_roles_grouped($m['roles']), 'name'); ?>
                <?php if ($m_top): ?><span class="member__role"><?php echo $e(implode(', ', $m_top)); ?></span><?php endif; ?>
              </span>
            </a>
            <?php endforeach; ?>
            <?php else: ?>
            <?php // Say what is behind the door and open it, rather than
                  // showing an empty panel and leaving them to guess. ?>
            <div class="shut">
              <p class="shut__lede">The Directory is for members.</p>
              <button class="btn shut__cta" data-open="account">Become a member</button>
            </div>
            <?php endif; ?>
          </div>
        </section>
        <?php endif; ?>

      </div>
    </div>
  </main>

<?php require __DIR__ . '/_foot.php'; ?>
