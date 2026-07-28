<?php
// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — a single piece of writing
//  A reading surface: normal page scroll, a real measure, serif body.
// ═══════════════════════════════════════════════════════════════════════════
require dirname(__DIR__) . '/v7/_lib.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /'); exit; }

$q = $conn->prepare(
  "SELECT p.*, u.name AS author_name
   FROM posts p LEFT JOIN users u ON p.uid = u.id
   WHERE p.id = :id AND p.active = 1 LIMIT 1"
);
$q->execute([':id' => $id]);
$post = $q->fetch(PDO::FETCH_ASSOC);

if (!$post) { http_response_code(404); header('Location: /'); exit; }

$canonical = ctx_post_url($post['id'], $post['title']);
$date      = date('j F Y', $post['edited'] ?: $post['stamp']);

// More from the journal, for the foot of the piece.
$q = $conn->prepare(
  "SELECT p.id, p.title, p.type, p.stamp, p.edited, u.name AS author_name
   FROM posts p LEFT JOIN users u ON p.uid = u.id
   WHERE p.active = 1 AND p.type IN ('review','essay') AND p.id != :id
   ORDER BY COALESCE(p.edited, p.stamp) DESC LIMIT 4"
);
$q->execute([':id' => $id]);
$more = $q->fetchAll(PDO::FETCH_ASSOC);

$e = 'ctx_e';

// Shell
$ctx_title  = $post['title'] . ' — Cinema, TX';
$ctx_active = '';
$ctx_scroll = true;
$ctx_video  = false;

ob_start(); ?>
<link rel="canonical" href="<?php echo $e(ctx_url($canonical)); ?>" />
<meta property="og:type" content="article" />
<meta property="og:url" content="<?php echo $e(ctx_url($canonical)); ?>" />
<meta property="og:title" content="<?php echo $e($post['title']); ?>" />
<?php if (!empty($post['subtitle'])): ?>
<meta property="og:description" content="<?php echo $e($post['subtitle']); ?>" />
<?php endif; ?>
<?php if (!empty($post['image'])): ?>
<meta property="og:image" content="<?php echo $e(ctx_url('/uploads/posts/' . $post['image'])); ?>" />
<?php endif; ?>
<?php $ctx_meta = ob_get_clean();

require dirname(__DIR__) . '/v7/_head.php';
require dirname(__DIR__) . '/v7/_chrome.php';
?>

  <main class="canvas">
    <article class="reading">

      <div class="reading__kicker">
        <?php if (!empty($post['featured'])): ?>
        <span class="pill pill--featured">Featured</span>
        <?php endif; ?>
        <?php if (!empty($post['type'])): ?>
        <span class="pill"><?php echo $e($post['type']); ?></span>
        <?php endif; ?>
        <span><?php echo $e($date); ?></span>
      </div>

      <h1 class="reading__title"><?php echo $e($post['title']); ?></h1>

      <?php if (!empty($post['subtitle'])): ?>
      <p class="reading__deck"><?php echo $e($post['subtitle']); ?></p>
      <?php endif; ?>

      <div class="reading__by">
        <?php if (!empty($post['author_name'])): ?>
        <a href="/users/profile.php?id=<?php echo (int)$post['uid']; ?>"><?php echo $e($post['author_name']); ?></a>
        <span>&middot;</span>
        <?php endif; ?>
        <span>Cinema, TX</span>
      </div>

      <?php
        $post_images = ctx_post_images($post);
        // Inline only means something once there is more than one image to
        // spread — with just the hero, cycle and inline render identically,
        // so there is no reason to make the paragraph-splitting path do the
        // work when the single-figure path already gets there.
        $inline = ($post['image_mode'] ?? 'cycle') === 'inline' && count($post_images) > 1;
      ?>

      <?php if (!$inline && count($post_images) === 1): $img = $post_images[0]; ?>
      <figure class="reading__figure">
        <img src="/uploads/posts/<?php echo $e($img['file']); ?>" alt="<?php echo $e($post['title']); ?>" />
        <?php if (!empty($img['cred'])): ?>
        <figcaption><?php echo $e($img['cred']); ?></figcaption>
        <?php endif; ?>
      </figure>
      <?php elseif (!$inline && $post_images): ?>
      <figure class="reading__figure reading__figure--cycle">
        <?php foreach ($post_images as $i => $img): ?>
        <img class="<?php echo $i === 0 ? 'is-on' : ''; ?>" src="/uploads/posts/<?php echo $e($img['file']); ?>" alt="<?php echo $e($post['title']); ?>" />
        <?php endforeach; ?>
        <?php foreach ($post_images as $i => $img): if (!empty($img['cred'])): ?>
        <figcaption class="<?php echo $i === 0 ? 'is-on' : ''; ?>"><?php echo $e($img['cred']); ?></figcaption>
        <?php endif; endforeach; ?>
      </figure>
      <?php endif; ?>

      <?php if ($inline):
        $paragraphs = ctx_post_paragraphs($post['content']);
        $positions  = ctx_post_image_positions(count($paragraphs), count($post_images));
      ?>
      <div class="reading__body prose">
        <?php foreach ($paragraphs as $n => $para): $num = $n + 1; ?>
        <p><?php echo nl2br($e($para)); ?></p>
        <?php foreach ($positions as $img_i => $pos): if ($pos === $num): $img = $post_images[$img_i]; ?>
        <figure class="reading__figure reading__figure--inline">
          <img src="/uploads/posts/<?php echo $e($img['file']); ?>" alt="<?php echo $e($post['title']); ?>" />
          <?php if (!empty($img['cred'])): ?><figcaption><?php echo $e($img['cred']); ?></figcaption><?php endif; ?>
        </figure>
        <?php endif; endforeach; ?>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="reading__body prose"><?php echo nl2br($e($post['content'])); ?></div>
      <?php endif; ?>

      <?php
      // The old page's share buttons all pointed at "#". These are real: X and
      // Reddit take share URLs, Instagram has no web share endpoint, so that
      // slot becomes copy-link — which is the one people actually use.
      $share_url = ctx_url($canonical);
      ?>
      <div class="reading__share">
        <span class="reading__share-label">Share</span>
        <a class="share" target="_blank" rel="noopener" title="Share on X"
           href="https://x.com/intent/tweet?url=<?php echo urlencode($share_url); ?>&text=<?php echo urlencode($post['title']); ?>">
          <i class="fa-brands fa-x-twitter"></i></a>
        <a class="share" target="_blank" rel="noopener" title="Share on Reddit"
           href="https://reddit.com/submit?url=<?php echo urlencode($share_url); ?>&title=<?php echo urlencode($post['title']); ?>">
          <i class="fa-brands fa-reddit-alien"></i></a>
        <button class="share" id="copy-link" data-url="<?php echo $e($share_url); ?>" title="Copy link">
          <i class="fa-solid fa-link"></i></button>
      </div>

      <?php if ($more): ?>
      <div class="reading__more">
        <div class="reading__more-head">More from the journal</div>
        <?php foreach ($more as $m): ?>
        <a class="row" href="<?php echo $e(ctx_post_url($m['id'], $m['title'])); ?>">
          <span class="row__type"><?php echo $e($m['type']); ?></span>
          <span class="row__title"><?php echo $e($m['title']); ?></span>
          <span class="row__meta"><?php echo date('j M', $m['edited'] ?: $m['stamp']); ?></span>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </article>
  </main>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
