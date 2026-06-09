<?php
session_start();
require '../database.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /'); exit; }

$stmt = $conn->prepare(
  "SELECT p.*, u.name AS author_name
   FROM posts p
   LEFT JOIN users u ON p.uid = u.id
   WHERE p.id = :id AND p.active = 1"
);
$stmt->execute([':id' => $id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
  http_response_code(404);
  header('Location: /');
  exit;
}

function slugify($text) {
  $text = mb_strtolower(trim($text));
  $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
  $text = preg_replace('/[\s-]+/', '-', $text);
  return trim($text, '-');
}

$canonical    = '/posts/' . slugify($post['title']) . '-' . $post['id'];
$display_date = date('F j, Y', $post['edited'] ?: $post['stamp']);
?>
<html prefix="og: https://ogp.me/ns#">
<head>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta property="og:title" content="<?php echo htmlspecialchars($post['title']); ?>" />
  <?php if($post['subtitle']): ?>
  <meta property="og:description" content="<?php echo htmlspecialchars($post['subtitle']); ?>" />
  <?php endif; ?>
  <?php if($post['image']): ?>
  <meta property="og:image" content="/uploads/posts/<?php echo htmlspecialchars($post['image']); ?>" />
  <?php endif; ?>
  <link rel="canonical" href="<?php echo $canonical; ?>" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/7.0.0/normalize.css" />
  <link rel="icon" href="/img/iconimg.png" type="image/x-icon" />
  <link rel="shortcut icon" href="/img/iconimg.png" type="image/x-icon" />
  <link rel="stylesheet" href="/css/sass.css" />
  <script src="https://kit.fontawesome.com/7ea7b5f42f.js" crossorigin="anonymous"></script>
  <script src="https://code.jquery.com/jquery-3.2.1.min.js" integrity="sha256-hwg4gsxgFZhOsEEamdOYGBf13FyQuiTwlAQgxVSNgt4=" crossorigin="anonymous"></script>
  <script src="/js/script-jlm.js"></script>
  <title><?php echo htmlspecialchars($post['title']); ?> — Cinema, TX</title>
</head>
<body id="post-page">

<div class="main-content">

  <?php include '../header.php'; ?>

  <div class="home-base">
    <div class="post-single">

      <?php if($post['type']): ?>
      <span class="post-type-pill"><?php echo htmlspecialchars($post['type']); ?></span>
      <?php endif; ?>

      <h1 class="post-headline"><?php echo htmlspecialchars($post['title']); ?></h1>

      <?php if($post['subtitle']): ?>
      <p class="post-sub"><?php echo htmlspecialchars($post['subtitle']); ?></p>
      <?php endif; ?>

      <div class="post-meta">
        <?php if($post['author_name']): ?>
          <span><?php echo htmlspecialchars($post['author_name']); ?></span>
          <span class="post-meta-dot">&middot;</span>
        <?php endif; ?>
        <span><?php echo $display_date; ?></span>
      </div>

      <?php if($post['image']): ?>
      <div class="post-hero-wrap">
        <img class="post-hero" src="/uploads/posts/<?php echo htmlspecialchars($post['image']); ?>" alt="<?php echo htmlspecialchars($post['title']); ?>" />
        <div class="post-share">
          <a class="share-btn share-twitter" href="#" title="Share on X">
            <span class="share-label">Share</span>
            <i class="fa-brands fa-x-twitter"></i>
          </a>
          <a class="share-btn share-instagram" href="#" title="Share on Instagram">
            <span class="share-label">Share</span>
            <i class="fa-brands fa-instagram"></i>
          </a>
          <a class="share-btn share-reddit" href="#" title="Share on Reddit">
            <span class="share-label">Share</span>
            <i class="fa-brands fa-reddit-alien"></i>
          </a>
        </div>
        <?php if($post['photo_cred']): ?>
        <span class="post-photo-cred"><?php echo htmlspecialchars($post['photo_cred']); ?></span>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <hr class="post-divider" />

      <div class="post-body">
        <?php echo nl2br(htmlspecialchars($post['content'])); ?>
      </div>

    </div>
  </div>

</div>

</body>
</html>
