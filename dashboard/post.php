<?php
session_start();
require '../database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['username'])) {
  echo json_encode(['success' => false, 'error' => 'not_logged_in']);
  exit;
}

$uid_q = $conn->prepare("SELECT `id` FROM `users` WHERE `email` = :email");
$uid_q->execute([':email' => $_SESSION['username']]);
$uid = $uid_q->fetchColumn();

if (!$uid) {
  echo json_encode(['success' => false, 'error' => 'user_not_found']);
  exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'create') {
  $title       = $_POST['title']       ?? '';
  $subtitle    = $_POST['subtitle']    ?? null;
  $content     = $_POST['content']     ?? '';
  $type        = $_POST['type']        ?? null;
  $photo_cred  = $_POST['photo_cred']  ?? null;
  $photo_cred2 = $_POST['photo_cred2'] ?? null;
  $photo_cred3 = $_POST['photo_cred3'] ?? null;
  // Anything else reads as the default rather than silently rejecting a post.
  $image_mode  = ($_POST['image_mode'] ?? '') === 'inline' ? 'inline' : 'cycle';
  $stamp       = time();

  $stmt = $conn->prepare("INSERT INTO `posts`
                          (uid, title, subtitle, content, type, photo_cred, photo_cred2, photo_cred3, image_mode, stamp, active)
                          VALUES (:uid, :title, :subtitle, :content, :type, :photo_cred, :photo_cred2, :photo_cred3, :image_mode, :stamp, 0)");
  $stmt->execute([
    ':uid'         => $uid,
    ':title'       => $title,
    ':subtitle'    => $subtitle ?: null,
    ':content'     => $content,
    ':type'        => $type ?: null,
    ':photo_cred'  => $photo_cred ?: null,
    ':photo_cred2' => $photo_cred2 ?: null,
    ':photo_cred3' => $photo_cred3 ?: null,
    ':image_mode'  => $image_mode,
    ':stamp'       => $stamp,
  ]);

  echo json_encode(['success' => true, 'post_id' => (int)$conn->lastInsertId()]);
}
else if ($action === 'update') {
  $post_id     = (int)($_POST['post_id'] ?? 0);
  $title       = $_POST['title']       ?? '';
  $subtitle    = $_POST['subtitle']    ?? null;
  $content     = $_POST['content']     ?? '';
  $type        = $_POST['type']        ?? null;
  $photo_cred  = $_POST['photo_cred']  ?? null;
  $photo_cred2 = $_POST['photo_cred2'] ?? null;
  $photo_cred3 = $_POST['photo_cred3'] ?? null;
  $image_mode  = ($_POST['image_mode'] ?? '') === 'inline' ? 'inline' : 'cycle';
  $edited      = time();

  $stmt = $conn->prepare("UPDATE `posts`
                          SET title=:title, subtitle=:subtitle, content=:content,
                              type=:type, photo_cred=:photo_cred, photo_cred2=:photo_cred2,
                              photo_cred3=:photo_cred3, image_mode=:image_mode, edited=:edited
                          WHERE id=:post_id AND uid=:uid");
  $stmt->execute([
    ':title'       => $title,
    ':subtitle'    => $subtitle ?: null,
    ':content'     => $content,
    ':type'        => $type ?: null,
    ':photo_cred'  => $photo_cred ?: null,
    ':photo_cred2' => $photo_cred2 ?: null,
    ':photo_cred3' => $photo_cred3 ?: null,
    ':image_mode'  => $image_mode,
    ':edited'      => $edited,
    ':post_id'     => $post_id,
    ':uid'         => $uid,
  ]);

  echo json_encode(['success' => true]);
}
else if ($action === 'publish') {
  $post_id = (int)($_POST['post_id'] ?? 0);

  $stmt = $conn->prepare("UPDATE `posts` SET active=1 WHERE id=:post_id AND uid=:uid");
  $stmt->execute([':post_id' => $post_id, ':uid' => $uid]);

  echo json_encode(['success' => true]);
}
else if ($action === 'unpublish') {
  $post_id = (int)($_POST['post_id'] ?? 0);

  $stmt = $conn->prepare("UPDATE `posts` SET active=0 WHERE id=:post_id AND uid=:uid AND active=1");
  $stmt->execute([':post_id' => $post_id, ':uid' => $uid]);

  echo json_encode(['success' => true]);
}
else if ($action === 'get') {
  $post_id = (int)($_GET['post_id'] ?? 0);

  $stmt = $conn->prepare("SELECT id, title, subtitle, content, type,
                          image, image2, image3, photo_cred, photo_cred2, photo_cred3, image_mode,
                          active, featured, stamp, edited
                          FROM `posts` WHERE id=:post_id AND uid=:uid");
  $stmt->execute([':post_id' => $post_id, ':uid' => $uid]);
  $post = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($post) {
    echo json_encode(['success' => true, 'post' => $post]);
  } else {
    echo json_encode(['success' => false, 'error' => 'not_found']);
  }
}
else if ($action === 'upload_image') {
  $post_id = (int)($_POST['post_id'] ?? 0);

  // Which of the three image columns this request targets. Whitelisted
  // rather than trusted, since it ends up interpolated into SQL — PDO can
  // parameterise a value but not a column name.
  $slots  = ['1' => 'image', '2' => 'image2', '3' => 'image3'];
  $slot   = $slots[$_POST['slot'] ?? $_GET['slot'] ?? '1'] ?? null;
  if (!$slot) { echo json_encode(['success' => false, 'error' => 'bad_slot']); exit; }

  // Ownership only. This used to carry AND active=0, copied from the delete
  // branch where requiring a draft is deliberate — you unpublish before you
  // destroy. Here it meant a published essay could have its words edited but
  // not its image, and the failure surfaced as a bare "image upload failed".
  // The two writes below never had the restriction, which is the giveaway,
  // and event.php's poster upload gets this right.
  $check = $conn->prepare("SELECT id, `$slot` AS current FROM `posts` WHERE id=:post_id AND uid=:uid");
  $check->execute([':post_id' => $post_id, ':uid' => $uid]);
  $row = $check->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    echo json_encode(['success' => false, 'error' => 'not_found']); exit;
  }

  // remove-only request (no file)
  if (isset($_GET['remove']) && $_GET['remove'] === '1') {
    if (!empty($row['current'])) {
      $old = __DIR__ . '/../uploads/posts/' . $row['current'];
      if (file_exists($old)) unlink($old);
    }
    $stmt = $conn->prepare("UPDATE `posts` SET `$slot`=NULL WHERE id=:post_id AND uid=:uid");
    $stmt->execute([':post_id' => $post_id, ':uid' => $uid]);
    echo json_encode(['success' => true]); exit;
  }

  if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'upload_error']); exit;
  }

  $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime  = finfo_file($finfo, $_FILES['image']['tmp_name']);
  finfo_close($finfo);

  if (!in_array($mime, $allowed_types)) {
    echo json_encode(['success' => false, 'error' => 'invalid_type']); exit;
  }

  if ($_FILES['image']['size'] > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'too_large']); exit;
  }

  $ext_map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
  $ext      = $ext_map[$mime];
  $filename = $post_id . '_' . $slot . '_' . time() . '.' . $ext;
  $dest     = __DIR__ . '/../uploads/posts/' . $filename;

  // delete old image if one exists
  if (!empty($row['current'])) {
    $old = __DIR__ . '/../uploads/posts/' . $row['current'];
    if (file_exists($old)) unlink($old);
  }

  if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
    echo json_encode(['success' => false, 'error' => 'move_failed']); exit;
  }

  $stmt = $conn->prepare("UPDATE `posts` SET `$slot`=:image WHERE id=:post_id AND uid=:uid");
  $stmt->execute([':image' => $filename, ':post_id' => $post_id, ':uid' => $uid]);

  echo json_encode(['success' => true, 'filename' => $filename]);
}
else if ($action === 'feature') {
  $post_id  = (int)($_POST['post_id'] ?? 0);
  $featured = (int)($_POST['featured'] ?? 0);

  $stmt = $conn->prepare("UPDATE `posts` SET featured=:featured WHERE id=:post_id AND uid=:uid");
  $stmt->execute([':featured' => $featured ? 1 : 0, ':post_id' => $post_id, ':uid' => $uid]);

  echo json_encode(['success' => true]);
}
else if ($action === 'delete') {
  $post_id = (int)($_POST['post_id'] ?? 0);

  // fetch image filename before deleting row
  $check = $conn->prepare("SELECT image FROM `posts` WHERE id=:post_id AND uid=:uid AND active=0");
  $check->execute([':post_id' => $post_id, ':uid' => $uid]);
  $row = $check->fetch(PDO::FETCH_ASSOC);

  $stmt = $conn->prepare("DELETE FROM `posts` WHERE id=:post_id AND uid=:uid AND active=0");
  $stmt->execute([':post_id' => $post_id, ':uid' => $uid]);

  // unlink image file if one existed
  if ($row && !empty($row['image'])) {
    $old = __DIR__ . '/../uploads/posts/' . $row['image'];
    if (file_exists($old)) unlink($old);
  }

  echo json_encode(['success' => true]);
}
else {
  echo json_encode(['success' => false, 'error' => 'unknown_action']);
}
?>
