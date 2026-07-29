<?php
// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — Admin dashboard
//
//  Four panels, deliberately unequal. Each one shows the thing it is about —
//  the Instagram card as it would post, the actual poster art, the actual next
//  showtimes — rather than a sentence describing it and a button. A panel you
//  can see into answers "does this need me?" without being opened, and the
//  size differences give the eye somewhere to land first.
//
//  _guard.php before _lib.php and before any output: authentication is not
//  something to do after loading the page's context.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
require dirname(__DIR__) . '/v7/_lib.php';
require dirname(__DIR__) . '/list/instagram.php';

$now = $CTX_NOW;

$films = $conn->query("SELECT `id`, `title`, `director`, `poster` FROM `films` WHERE `active` = 1 ORDER BY `id` DESC LIMIT 8")
              ->fetchAll(PDO::FETCH_ASSOC);
$film_count = (int) $conn->query("SELECT COUNT(*) FROM `films` WHERE `active` = 1")->fetchColumn();

$booked = $conn->prepare(
  "SELECT s.`showtime`, s.`theatre`, f.`title`
   FROM `showtimes` s LEFT JOIN `films` f ON f.`id` = s.`f_id`
   WHERE s.`endtime` > :now ORDER BY s.`showtime` ASC LIMIT 5"
);
$booked->execute([':now' => $now]);
$booked = $booked->fetchAll(PDO::FETCH_ASSOC);

$booked_count = $conn->prepare("SELECT COUNT(*) FROM `showtimes` WHERE `endtime` > :now");
$booked_count->execute([':now' => $now]);
$booked_count = (int) $booked_count->fetchColumn();

// Today's Instagram card. The admin Instagram page regenerates on every view
// so its preview is always current; the dashboard reuses whatever is already
// on disk, because rendering a 1080×1350 PNG to draw a thumbnail of it would
// be silly.
$ig_films   = ig_today_films($conn);
$ig_caption = ig_build_caption($ig_films, $now);
$ig_name    = 'ig-' . date('Y-m-d', $now) . '.png';
$ig_path    = dirname(__DIR__) . '/uploads/social/' . $ig_name;
if (!file_exists($ig_path)) {
    [$ig_path, ] = ig_save_image(ig_build_image($ig_films, $now), $now);
}
$ig_url    = '/uploads/social/' . $ig_name . '?t=' . @filemtime($ig_path);
$ig_posted = file_exists(dirname(__DIR__) . '/uploads/social/.posted-' . date('Y-m-d', $now));

$v = ctx_visit_stats(14);
$e = 'ctx_e';

$ctx_title     = 'Admin — Cinema, TX';
$ctx_active    = 'dashboard';
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
        <h1 class="adm__title">Dashboard</h1>
        <span class="adm__meta"><?php echo date('l, j F', $now); ?></span>
      </div>

      <div class="adm-bento">

        <?php // The loud one. It is the only thing here with a daily deadline. ?>
        <section class="card adm-card adm-tall">
          <div class="card__head">
            <span class="card__title">Instagram</span>
            <span class="post-status <?php echo $ig_posted ? 'status-live' : 'status-draft'; ?>">
              <?php echo $ig_posted ? 'Posted' : 'Pending'; ?>
            </span>
            <a class="card__more" href="/_admin/instagram.php">Review &rarr;</a>
          </div>
          <div class="card__body card__body--flush">
            <div class="ig-mock ig-mock--peek">
              <div class="ig-mock__head">
                <span class="ig-mock__avatar">C</span>
                <span class="ig-mock__handle">cinematx</span>
              </div>
              <span class="ig-mock__shot">
                <img class="ig-mock__image" src="<?php echo $e($ig_url); ?>" alt="Today's Instagram card" />
              </span>
              <div class="ig-mock__caption ig-mock__caption--clamp"><?php echo $e($ig_caption); ?></div>
            </div>
          </div>
        </section>

        <div class="adm-bento__col">

        <section class="card adm-card">
          <div class="card__head">
            <span class="card__title">Films</span>
            <span class="adm-count"><?php echo $film_count; ?></span>
            <a class="card__more" href="/_admin/films.php">Open &rarr;</a>
          </div>
          <div class="card__body">
            <?php if ($films): ?>
            <div class="adm-posters">
              <?php foreach (array_slice($films, 0, 4) as $f):
                $poster = $f['poster'] ? '/_admin/thumb.php?w=240&f=' . rawurlencode($f['poster']) : null; ?>
              <span class="adm-poster" title="<?php echo $e($f['title']); ?>">
                <?php if ($poster): ?>
                  <img src="<?php echo $e($poster); ?>" alt="<?php echo $e($f['title']); ?>" loading="lazy" />
                <?php else: ?>
                  <span class="adm-poster__blank"><?php echo $e($f['title']); ?></span>
                <?php endif; ?>
              </span>
              <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="adm-empty">No films yet</div>
            <?php endif; ?>
          </div>
        </section>

        <section class="card adm-card">
          <div class="card__head">
            <span class="card__title">Showtimes</span>
            <span class="adm-count"><?php echo $booked_count; ?></span>
            <a class="card__more" href="/_admin/showtimes.php">Open &rarr;</a>
          </div>
          <div class="card__body card__body--flush">
            <?php if ($booked): ?>
              <?php foreach ($booked as $b): ?>
              <div class="adm-row">
                <span class="adm-row__text">
                  <span class="adm-row__title"><?php echo $e($b['title']); ?></span>
                  <span class="adm-row__sub"><?php echo date('D j M, g:ia', $b['showtime']); ?></span>
                </span>
                <span class="adm-row__when">Th<?php echo (int)$b['theatre']; ?></span>
              </div>
              <?php endforeach; ?>
            <?php else: ?>
            <div class="adm-empty">Nothing scheduled</div>
            <?php endif; ?>
          </div>
        </section>

        <section class="card adm-card adm-wide">
          <div class="card__head">
            <span class="card__title">Visitors</span>
            <span class="adm-count"><?php echo number_format($v['today']); ?> today &middot; <?php echo number_format($v['hits']['today']); ?> visits</span>
            <a class="card__more" href="/_admin/visitors.php">Open &rarr;</a>
          </div>
          <div class="card__body">
            <?php $g_stats = $v; $g_peek = true; require __DIR__ . '/_graph.php'; ?>
          </div>
        </section>

        </div>
      </div>
    </div>
  </main>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
