<?php
error_reporting(1);
session_start();
require '../database.php';
?>
<html>
<head>
  <meta name="author" content="Dr. Zoidberg" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
  <link rel="stylesheet" href="../css/sass.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/7.0.0/normalize.css" />
  <link rel="icon" href="/img/iconimg.png" type="image/x-icon"/>
  <link rel="shortcut icon" href="/img/iconimg.png" type="image/x-icon"/>
  <script src="https://kit.fontawesome.com/7ea7b5f42f.js" crossorigin="anonymous"></script>
  <script src="https://code.jquery.com/jquery-3.2.1.min.js" integrity="sha256-hwg4gsxgFZhOsEEamdOYGBf13FyQuiTwlAQgxVSNgt4=" crossorigin="anonymous"></script>
  <script src="../js/script.js"></script>
  <script src="../js/script-jlm.js"></script>
  <script src="../js/dashboard.js"></script>
  <title>Cinema, TX — Dashboard</title>
</head>
<body id="dashboard">

<div class="main-content">

<?php require '../header.php'; ?>

<div class="home-base">
  <div class="content-block-w">

  <?php if(isset($_SESSION['username'])): ?>
  <?php
    $user = $_SESSION['username'];
    $sql1 = $conn->prepare("SELECT * FROM `users` WHERE `email` = :email");
    $sql1->execute([':email' => $user]);
    $qUser = $sql1->fetch();
    require '../roles.php';

    function slugify($text) {
      $text = mb_strtolower(trim($text));
      $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
      $text = preg_replace('/[\s-]+/', '-', $text);
      return trim($text, '-');
    }

    $posts_q = $conn->prepare("SELECT id, title, subtitle, type, stamp, edited, active
                               FROM `posts` WHERE uid=:uid
                               ORDER BY COALESCE(edited, stamp) DESC");
    $posts_q->execute([':uid' => $qUser['id']]);
    $all_posts = $posts_q->fetchAll(PDO::FETCH_ASSOC);
    $post_count = count($all_posts);
  ?>

  <nav class="dash-nav">
    <span class="dash-tab" data-panel="write">Write</span>
    <span class="dash-tab" data-panel="posts">Posts<?php if($post_count > 0): ?> <span class="dash-count"><?php echo $post_count; ?></span><?php endif; ?></span>
    <span class="dash-tab" data-panel="account">Account</span>
  </nav>

  <div class="dash-panel" id="panel-posts">
    <?php if(!empty($all_posts)): ?>
    <ul class="drafts-list">
      <?php foreach($all_posts as $post): ?>
      <?php $is_live = (int)$post['active'] === 1; ?>
      <li class="draft-row <?php echo $is_live ? 'post-live' : ''; ?>"
          data-id="<?php echo $post['id']; ?>"
          data-active="<?php echo (int)$post['active']; ?>"
          data-title="<?php echo htmlspecialchars($post['title'] ?? ''); ?>"
          data-subtitle="<?php echo htmlspecialchars($post['subtitle'] ?? ''); ?>"
          data-type="<?php echo htmlspecialchars($post['type'] ?? ''); ?>">
        <div class="draft-info">
          <span class="draft-title"><?php echo $post['title'] ? htmlspecialchars($post['title']) : '<em>Untitled</em>'; ?></span>
          <?php if($post['type']): ?><span class="draft-type"><?php echo htmlspecialchars($post['type']); ?></span><?php endif; ?>
          <span class="post-status <?php echo $is_live ? 'status-live' : 'status-draft'; ?>"><?php echo $is_live ? 'live' : 'draft'; ?></span>
          <span class="draft-date"><?php echo date('M j, Y', $post['edited'] ?: $post['stamp']); ?></span>
        </div>
        <div class="post-row-actions">
          <?php if($is_live): ?>
            <a class="post-view" href="/posts/?id=<?php echo $post['id']; ?>" target="_blank">view</a>
            <button class="post-edit" data-id="<?php echo $post['id']; ?>">edit</button>
            <button class="post-unpublish" data-id="<?php echo $post['id']; ?>">unpublish</button>
          <?php else: ?>
            <button class="draft-delete" data-id="<?php echo $post['id']; ?>">&#x2715;</button>
          <?php endif; ?>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <p class="drafts-empty">Nothing here yet.</p>
    <?php endif; ?>
  </div>

  <div class="dash-panel" id="panel-account">
    <form action="/dashboard/signup.php?action=updateprof" method="post">
      <label>Email</label>
      <input class="input" type="text" value="<?php echo htmlspecialchars($qUser['email']); ?>" placeholder="Email" disabled />
      <input type="hidden" value="<?php echo htmlspecialchars($qUser['email']); ?>" name="email" />
      <label>Name</label>
      <input class="input" type="text" value="<?php echo htmlspecialchars($qUser['name']); ?>" name="uname" placeholder="Name" />
      <label>Phone</label>
      <input class="input" type="tel" value="<?php echo htmlspecialchars($qUser['phone']); ?>" name="phone" placeholder="Phone" />
      <label>Role</label>
      <select class="input" name="dept">
        <option value="0">Select your role</option>
        <?php foreach($roles as $role): ?>
        <option value="<?php echo $role; ?>" <?php echo $qUser['dept'] === $role ? 'selected' : ''; ?>><?php echo $role; ?></option>
        <?php endforeach; ?>
      </select>
      <label>Website</label>
      <input class="input" type="text" value="<?php echo htmlspecialchars($qUser['website']); ?>" name="website" placeholder="Website" />
      <label>Letterboxd</label>
      <input class="input" type="text" value="<?php echo htmlspecialchars($qUser['lb']); ?>" name="lb" placeholder="Letterboxd" />
      <input type="hidden" value="<?php echo $qUser['id']; ?>" name="uid" />
      <input type="submit" class="submit" value="Update" />
    </form>
  </div>

  <div class="dash-panel" id="panel-write">
    <div class="write-wrap">
      <input  type="text" id="post-title"    class="write-title"   placeholder="Title"            autocomplete="off" />
      <input  type="text" id="post-subtitle" class="write-subtitle" placeholder="Subtitle"         autocomplete="off" />
      <select id="post-type" class="write-type">
        <option value="">Type — optional</option>
        <option value="review">Review</option>
        <option value="essay">Essay</option>
        <option value="note">Note</option>
      </select>
      <textarea id="post-content" class="write-content" placeholder="Write something..."></textarea>
      <div class="write-image-wrap">
        <div class="write-image-row">
          <label class="write-image-btn" id="post-image-label">
            <i class="fa-solid fa-image"></i> Main image
            <input type="file" id="post-image" accept="image/jpeg,image/png,image/webp,image/gif" disabled />
          </label>
          <span class="write-image-hint" id="post-image-hint">Save a draft first</span>
        </div>
        <div class="write-image-preview" id="post-image-preview" style="display:none;">
          <img id="post-image-thumb" src="" alt="preview" />
          <button class="write-image-remove" id="post-image-remove" title="Remove image">&#x2715;</button>
        </div>
        <input type="text" id="post-photo-cred" class="write-photo-cred" placeholder="Photo credit — optional" autocomplete="off" />
      </div>
      <div class="write-footer">
        <span id="autosave-status" class="autosave-status"></span>
        <div class="write-actions">
          <button id="post-save"    class="submit write-save">Save Draft</button>
          <button id="post-publish" class="submit write-publish" disabled>Publish</button>
        </div>
      </div>
    </div>
  </div>

  <?php else: ?>
  <?php
    $error = $_GET['error'] ?? null;
    if ($error === "100") echo "<div class='error'>Retry email or password.</div><br>";
    else if ($error === "102") echo "<div class='error'>Registration error.</div><br>";
    else if ($error === "104") echo "<div class='error'>Email already registered.</div><br>";
    else if ($error === "108") echo "<div class='error'>Invalid access code.</div><br>";
  ?>
  <div class="title">Sign in</div><br>
  <div class="text" style="padding:0;margin:0;">
    <form action="/dashboard/signup.php?action=signup" method="post" enctype="multipart/form-data">
      <label>Email</label>
      <input class="input" type="text" name="email" placeholder="Email" />
      <label>Password</label>
      <input class="input" type="password" name="pw" placeholder="Password" />
      <label>Confirm Password</label>
      <input class="input" type="password" name="pw2" placeholder="Confirm password" />
      <label>Access Code</label>
      <input class="input" type="text" name="code" placeholder="Ask around." />
      <input type="submit" class="submit" value="Sign up" />
    </form>
  </div>
  <?php endif; ?>

  </div>
</div>

</div>

</body>
</html>
