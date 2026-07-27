<?php
// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — a member-submitted screening
//
//  The last public page on the old stylesheet, and The List links straight
//  into it, so it was a visible seam mid-design.
//
//  A reading surface rather than a listing: by the time you are here you have
//  chosen this one screening and want the particulars — when, where, and how
//  to get there.
// ═══════════════════════════════════════════════════════════════════════════
require dirname(__DIR__) . '/v7/_lib.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /list'); exit; }

$stmt = $conn->prepare(
  "SELECT e.*, u.name AS author_name, u.photo AS author_photo
   FROM events e
   LEFT JOIN users u ON e.uid = u.id
   WHERE e.id = :id AND e.active = 1"
);
$stmt->execute([':id' => $id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) { header('Location: /list'); exit; }

$e    = 'ctx_e';
$when = date('l, F j, Y \a\t g:ia', $event['screentime']);
$past = $event['screentime'] < $CTX_NOW;

$ctx_title  = $event['title'] . ' — Cinema, TX';
$ctx_active = 'list';                 // it was reached from The List
$ctx_scroll = true;
$ctx_video  = false;

ob_start(); ?>
<meta property="og:title" content="<?php echo $e($event['title']); ?>" />
<meta property="og:description" content="<?php echo $e($when . ' — ' . $event['location']); ?>" />
<?php if (!empty($event['poster'])): ?>
<meta property="og:image" content="/uploads/events/<?php echo $e($event['poster']); ?>" />
<?php endif; ?>
<?php $ctx_meta = ob_get_clean();

require dirname(__DIR__) . '/v7/_head.php';
require dirname(__DIR__) . '/v7/_chrome.php';
?>

  <main class="canvas">
    <article class="reading">

      <div class="reading__kicker">
        <span>Submitted by a member</span>
        <?php if ($past): ?><span class="reading__past">Already screened</span><?php endif; ?>
      </div>

      <h1 class="reading__title"><?php echo $e($event['title']); ?></h1>

      <div class="reading__by">
        <?php echo $e($when); ?> &middot; <?php echo $e($event['location']); ?>
      </div>

      <?php if (!empty($event['poster'])): ?>
      <div class="reading__figure">
        <img src="/uploads/events/<?php echo $e($event['poster']); ?>" alt="<?php echo $e($event['title']); ?>" />
      </div>
      <?php endif; ?>

      <?php // No blurb here on purpose — the events table stores a title, a
            // time and a place, and nothing else. If a description column is
            // ever added, it belongs above this block. ?>
      <div class="deets">
        <div class="deets__row">
          <span class="deets__label">When</span>
          <span class="deets__value"><?php echo $e($when); ?></span>
        </div>
        <div class="deets__row">
          <span class="deets__label">Where</span>
          <span class="deets__value"><?php echo $e($event['location']); ?></span>
        </div>
        <?php if (!empty($event['address'])): ?>
        <div class="deets__row">
          <span class="deets__label">Address</span>
          <span class="deets__value">
            <?php echo $e($event['address']); ?>
            <a class="deets__map" href="https://www.google.com/maps/search/?api=1&amp;query=<?php echo urlencode($event['address']); ?>"
               target="_blank" rel="noopener">Get directions <i class="fa-solid fa-arrow-up-right-from-square"></i></a>
          </span>
        </div>
        <?php endif; ?>
      </div>

      <?php if (!empty($event['author_name'])): ?>
      <div class="reading__share">
        <a class="face" href="/users/profile.php?id=<?php echo (int)$event['uid']; ?>" title="<?php echo $e($event['author_name']); ?>">
          <?php if (!empty($event['author_photo'])): ?>
          <img src="/uploads/profiles/<?php echo $e($event['author_photo']); ?>" alt="" />
          <?php else: ?><?php echo $e(mb_substr($event['author_name'], 0, 1)); ?><?php endif; ?>
        </a>
        <span class="reading__share-label">Submitted by</span>
        <a class="reading__link" href="/users/profile.php?id=<?php echo (int)$event['uid']; ?>"><?php echo $e($event['author_name']); ?></a>
      </div>
      <?php endif; ?>

      <div class="reading__fineprint">
        Member-submitted. <a class="reading__link" href="/list">The List</a> has everything else playing this week.
      </div>

    </article>
  </main>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
