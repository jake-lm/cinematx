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
  "SELECT e.*, u.name AS author_name, u.photo AS author_photo, u.admin AS author_admin
   FROM events e
   LEFT JOIN users u ON e.uid = u.id
   WHERE e.id = :id AND e.active = 1"
);
$stmt->execute([':id' => $id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) { header('Location: /list'); exit; }

$e      = 'ctx_e';
$when   = date('l, F j, Y \a\t g:ia', $event['screentime']);
$past   = $event['screentime'] < $CTX_NOW;
// An admin-added screening (see _admin/events.php) reads as a plain
// listing — no "submitted by a member" framing, since it isn't one.
$member = empty($event['author_admin']);

$ctx_title  = $event['title'] . ' — Cinema, TX';
$ctx_active = 'list';                 // it was reached from The List
$ctx_scroll = true;
$ctx_video  = false;

ob_start(); ?>
<meta property="og:url" content="<?php echo $e(ctx_url('/events/?id=' . $id)); ?>" />
<meta property="og:title" content="<?php echo $e($event['title']); ?>" />
<meta property="og:description" content="<?php echo $e($when . ' — ' . $event['location']); ?>" />
<?php if (!empty($event['poster'])): ?>
<meta property="og:image" content="<?php echo $e(ctx_url('/uploads/events/' . $event['poster'])); ?>" />
<?php endif; ?>
<?php $ctx_meta = ob_get_clean();

require dirname(__DIR__) . '/v7/_head.php';
require dirname(__DIR__) . '/v7/_chrome.php';
?>

  <main class="canvas">
    <article class="reading">

      <?php if ($member || $past): ?>
      <div class="reading__kicker">
        <?php if ($member): ?><span>Submitted by a member</span><?php endif; ?>
        <?php if ($past): ?><span class="reading__past">Already screened</span><?php endif; ?>
      </div>
      <?php endif; ?>

      <h1 class="reading__title"><?php echo $e($event['title']); ?></h1>

      <?php
      $facts = [$when, $event['location']];
      if (!empty($event['year']))     $facts[] = $event['year'];
      if (!empty($event['runtime']))  $facts[] = $event['runtime'] . 'm';
      if (!empty($event['genres']))   $facts[] = $event['genres'];
      if (!empty($event['director'])) $facts[] = 'dir. ' . $event['director'];
      ?>
      <div class="reading__by">
        <?php echo implode(' &middot; ', array_map($e, $facts)); ?>
      </div>

      <?php if (!empty($event['poster'])): ?>
      <div class="reading__figure">
        <img src="/uploads/events/<?php echo $e($event['poster']); ?>" alt="<?php echo $e($event['title']); ?>" />
      </div>
      <?php endif; ?>

      <?php if (!empty($event['synopsis'])): ?>
      <div class="reading__body">
        <p><?php echo nl2br($e($event['synopsis'])); ?></p>
      </div>
      <?php endif; ?>

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

      <?php if ($member && !empty($event['author_name'])): ?>
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
        <?php if ($member): ?>Member-submitted. <?php endif; ?><a class="reading__link" href="/list">The List</a> has everything else playing this week.
      </div>

    </article>
  </main>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
