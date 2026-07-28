<?php
// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — a member's profile
//  Who they are, what they've written, what they've been watching.
// ═══════════════════════════════════════════════════════════════════════════
require dirname(__DIR__) . '/v7/_lib.php';
require_once dirname(__DIR__) . '/list/scraper_letterboxd.php';

$me = ctx_me($conn);
if (!$me || ctx_state($conn) !== 'member') { header('Location: /'); exit; }

$profile_id = (int)($_GET['id'] ?? 0);
$q = $conn->prepare("SELECT * FROM `users` WHERE `id` = :id AND `active` = 1 LIMIT 1");
$q->execute([':id' => $profile_id]);
$who = $q->fetch(PDO::FETCH_ASSOC);
if (!$who) { header('Location: /directory'); exit; }

$is_me = (int)$who['id'] === (int)$me['id'];

// Their writing.
$q = $conn->prepare(
  "SELECT p.id, p.title, p.subtitle, p.type, p.image, p.stamp, p.edited
   FROM posts p WHERE p.active = 1 AND p.uid = :uid AND p.type IN ('review','essay')
   ORDER BY COALESCE(p.edited, p.stamp) DESC"
);
$q->execute([':uid' => $profile_id]);
$writing = $q->fetchAll(PDO::FETCH_ASSOC);

// Letterboxd — cached by the scraper, so this is cheap on repeat views.
$lb        = !empty($who['lb']) ? fetch_letterboxd_profile($who['lb']) : ['favorites' => [], 'recent' => []];
$favorites = $lb['favorites'] ?? [];
$recent    = $lb['recent'] ?? [];

$e = 'ctx_e';

$ctx_title  = $who['name'] . ' — Cinema, TX';
$ctx_active = $is_me ? 'profile' : 'directory';
$ctx_scroll = true;
$ctx_video  = false;

require dirname(__DIR__) . '/v7/_head.php';
require dirname(__DIR__) . '/v7/_chrome.php';
?>

  <main class="canvas">
    <div class="listing">

      <!-- ── Who ─────────────────────────────────────────────────────── -->
      <header class="who">
        <div class="who__face"<?php echo $is_me ? ' id="who-face"' : ''; ?>>
          <?php if (!empty($who['photo'])): ?>
          <img src="/uploads/profiles/<?php echo $e($who['photo']); ?>" alt="<?php echo $e($who['name']); ?>" />
          <?php else: ?><span class="who__face-letter"><?php echo $e(mb_substr($who['name'], 0, 1)); ?></span><?php endif; ?>
          <?php if ($is_me): ?>
          <label class="who__face-edit" for="who-face-input" title="Change photo">
            <i class="fa-solid fa-camera"></i>
          </label>
          <input type="file" id="who-face-input" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none;" />
          <?php endif; ?>
        </div>

        <div class="who__body">
          <h1 class="who__name"><?php echo $e($who['name']); ?></h1>
          <div class="who__meta">
            <?php if (!empty($who['dept'])): ?><span class="pill"><?php echo $e($who['dept']); ?></span><?php endif; ?>
            <?php if (!empty($who['sign_date'])): ?><span>Since <?php echo date('F Y', (int)$who['sign_date']); ?></span><?php endif; ?>
          </div>

          <div class="who__links">
            <?php if (!empty($who['email'])): ?>
            <a class="ilink" href="mailto:<?php echo $e($who['email']); ?>" title="<?php echo $e($who['email']); ?>"><i class="fa-solid fa-envelope"></i></a>
            <?php endif; ?>
            <?php if (!empty($who['phone'])): ?>
            <a class="ilink" href="tel:<?php echo $e($who['phone']); ?>" title="<?php echo $e($who['phone']); ?>"><i class="fa-solid fa-phone"></i></a>
            <?php endif; ?>
            <?php if (!empty($who['lb'])): ?>
            <a class="ilink" href="https://letterboxd.com/<?php echo $e($who['lb']); ?>/" target="_blank" rel="noopener" title="Letterboxd"><i class="fa-solid fa-clapperboard"></i></a>
            <?php endif; ?>
            <?php if (!empty($who['website'])): ?>
            <a class="ilink" href="<?php echo $e($who['website']); ?>" target="_blank" rel="noopener" title="Website"><i class="fa-solid fa-link"></i></a>
            <?php endif; ?>
            <?php if ($is_me): ?>
            <a class="btn btn--quiet" href="/dashboard#account">Edit profile</a>
            <?php endif; ?>
          </div>
        </div>
      </header>

      <div class="profile">

        <!-- ── Writing ───────────────────────────────────────────────── -->
        <section class="profile__main">
          <div class="day__label">
            <span class="day__name">Writing</span>
            <span class="day__rule"></span>
            <span class="day__count"><?php echo count($writing); ?></span>
          </div>

          <?php if ($writing): ?>
          <div class="rows-view is-on">
            <?php foreach ($writing as $p): ?>
            <a class="line" href="<?php echo $e(ctx_post_url($p['id'], $p['title'])); ?>">
              <span class="line__time"><?php echo $e($p['type']); ?></span>
              <span>
                <span class="line__title"><?php echo $e($p['title']); ?></span>
                <?php if (!empty($p['subtitle'])): ?>
                <span class="line__sub"><?php echo $e($p['subtitle']); ?></span>
                <?php endif; ?>
              </span>
              <span class="line__venue"><?php echo date('j M Y', $p['edited'] ?: $p['stamp']); ?></span>
            </a>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <p class="empty">Nothing published yet.</p>
          <?php endif; ?>

        </section>

        <!-- ── Letterboxd ────────────────────────────────────────────── -->
        <aside class="profile__side">
          <?php if ($favorites || $recent): ?>
          <section class="card">
            <div class="card__head">
              <span class="card__title">Letterboxd</span>
              <a class="card__more" href="https://letterboxd.com/<?php echo $e($who['lb']); ?>/" target="_blank" rel="noopener">Profile &rarr;</a>
            </div>

            <div class="card__body">
              <?php if ($favorites): ?>
              <div class="lb">
                <div class="lb__head">Favourites</div>
                <div class="lb__faves">
                  <?php foreach ($favorites as $f): ?>
                  <a class="lb__fave" href="<?php echo $e($f['link']); ?>" target="_blank" rel="noopener" title="<?php echo $e($f['title']); ?>">
                    <?php if (!empty($f['poster'])): ?>
                    <img src="<?php echo $e($f['poster']); ?>" alt="<?php echo $e($f['title']); ?>" loading="lazy" />
                    <?php else: ?><span class="lb__blank"><?php echo $e($f['title']); ?></span><?php endif; ?>
                  </a>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endif; ?>

              <?php if ($recent): ?>
              <div class="lb">
                <div class="lb__head">Recently watched</div>
                <?php foreach ($recent as $r): ?>
                <a class="lb__row" href="<?php echo $e($r['link']); ?>" target="_blank" rel="noopener">
                  <span class="lb__title"><?php echo $e($r['title']); ?><?php if (!empty($r['year'])): ?> <span class="lb__year"><?php echo $e($r['year']); ?></span><?php endif; ?></span>
                  <?php if (!empty($r['watched_date'])): ?>
                  <span class="lb__date"><?php echo date('j M', strtotime($r['watched_date'])); ?></span>
                  <?php endif; ?>
                </a>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
            </div>
          </section>
          <?php elseif ($is_me && empty($who['lb'])): ?>
          <section class="card">
            <div class="card__head"><span class="card__title">Letterboxd</span></div>
            <div class="card__body">
              <div class="lb-connect">
                <input type="text" id="lb-input" class="field__input" placeholder="username" autocomplete="off" />
                <button class="btn" id="lb-save">Save</button>
              </div>
            </div>
          </section>
          <?php elseif ($is_me): ?>
          <section class="card">
            <div class="card__head"><span class="card__title">Letterboxd</span></div>
            <div class="card__body">
              <p class="pitch">Nothing came back from Letterboxd for <?php echo $e($who['lb']); ?> yet — double check the username in the dashboard.</p>
            </div>
          </section>
          <?php endif; ?>
        </aside>

      </div>
    </div>
  </main>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
