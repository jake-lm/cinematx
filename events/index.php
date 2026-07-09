<?php
session_start();
require '../database.php';

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

if (!$event) {
  header('Location: /list'); exit;
}

$display_datetime = date('l, F j, Y \a\t g:ia', $event['screentime']);
?>
<html>
<head>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta property="og:title" content="<?php echo htmlspecialchars($event['title']); ?>" />
  <meta property="og:description" content="<?php echo htmlspecialchars($display_datetime . ' — ' . $event['location']); ?>" />
  <?php if ($event['poster']): ?>
  <meta property="og:image" content="/uploads/events/<?php echo htmlspecialchars($event['poster']); ?>" />
  <?php endif; ?>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/7.0.0/normalize.css" />
  <link rel="icon" href="/img/iconimg.png" type="image/x-icon" />
  <link rel="shortcut icon" href="/img/iconimg.png" type="image/x-icon" />
  <link rel="stylesheet" href="/css/sass.css" />
  <script src="https://kit.fontawesome.com/7ea7b5f42f.js" crossorigin="anonymous"></script>
  <script src="https://code.jquery.com/jquery-3.2.1.min.js" integrity="sha256-hwg4gsxgFZhOsEEamdOYGBf13FyQuiTwlAQgxVSNgt4=" crossorigin="anonymous"></script>
  <script src="/js/script-jlm.js"></script>
  <title><?php echo htmlspecialchars($event['title']); ?> — Cinema, TX</title>
</head>
<body id="event-page">

<div class="main-content">

  <?php include '../header.php'; ?>

  <div class="home-base">
    <div class="post-single">

      <span class="post-type-pill">Screening</span>

      <h1 class="post-headline"><?php echo htmlspecialchars($event['title']); ?></h1>

      <div class="post-meta">
        <span><?php echo $display_datetime; ?></span>
        <span class="post-meta-dot">&middot;</span>
        <span><?php echo htmlspecialchars($event['location']); ?></span>
      </div>

      <?php if ($event['poster']): ?>
      <div class="post-hero-wrap">
        <img class="post-hero" src="/uploads/events/<?php echo htmlspecialchars($event['poster']); ?>" alt="<?php echo htmlspecialchars($event['title']); ?>" />
      </div>
      <?php endif; ?>

      <hr class="post-divider" />

      <div class="event-details">
        <div class="event-details-row">
          <span class="event-details-label">When</span>
          <span class="event-details-value"><?php echo $display_datetime; ?></span>
        </div>
        <div class="event-details-row">
          <span class="event-details-label">Where</span>
          <span class="event-details-value"><?php echo htmlspecialchars($event['location']); ?></span>
        </div>
        <?php if (!empty($event['address'])): ?>
        <div class="event-details-row">
          <span class="event-details-label">Address</span>
          <span class="event-details-value">
            <?php echo htmlspecialchars($event['address']); ?>
            <a class="event-directions-link" href="https://www.google.com/maps/search/?api=1&query=<?php echo urlencode($event['address']); ?>" target="_blank" rel="noopener">Get directions &rarr;</a>
          </span>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($event['author_name']): ?>
      <div class="event-submitter">
        <a class="feed-card-avatar" href="/users/profile.php?id=<?php echo $event['uid']; ?>">
          <?php if (!empty($event['author_photo'])): ?>
          <img src="/uploads/profiles/<?php echo htmlspecialchars($event['author_photo']); ?>" alt="" />
          <?php else: ?>
          <i class="fa-solid fa-user"></i>
          <?php endif; ?>
        </a>
        <span>Submitted by <a class="author-link" href="/users/profile.php?id=<?php echo $event['uid']; ?>"><?php echo htmlspecialchars($event['author_name']); ?></a></span>
      </div>
      <?php endif; ?>

    </div>
  </div>

</div>

</body>
</html>
