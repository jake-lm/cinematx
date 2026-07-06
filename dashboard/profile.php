<?php
session_start();
require '../database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['username'])) {
  echo json_encode(['success' => false, 'error' => 'not_logged_in']);
  exit;
}

$uid_q = $conn->prepare("SELECT `id`, `photo` FROM `users` WHERE `email` = :email");
$uid_q->execute([':email' => $_SESSION['username']]);
$row = $uid_q->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  echo json_encode(['success' => false, 'error' => 'user_not_found']);
  exit;
}
$uid = $row['id'];

$action = $_GET['action'] ?? '';

if ($action === 'upload_photo') {

  // remove-only request (no file)
  if (isset($_GET['remove']) && $_GET['remove'] === '1') {
    if (!empty($row['photo'])) {
      $old = __DIR__ . '/../uploads/profiles/' . $row['photo'];
      if (file_exists($old)) unlink($old);
    }
    $stmt = $conn->prepare("UPDATE `users` SET photo=NULL WHERE id=:uid");
    $stmt->execute([':uid' => $uid]);
    echo json_encode(['success' => true]); exit;
  }

  if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'upload_error']); exit;
  }

  $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime  = finfo_file($finfo, $_FILES['photo']['tmp_name']);
  finfo_close($finfo);

  if (!in_array($mime, $allowed_types)) {
    echo json_encode(['success' => false, 'error' => 'invalid_type']); exit;
  }

  if ($_FILES['photo']['size'] > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'too_large']); exit;
  }

  $ext_map  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
  $ext      = $ext_map[$mime];
  $filename = $uid . '_' . time() . '.' . $ext;
  $dest     = __DIR__ . '/../uploads/profiles/' . $filename;

  // delete old photo if one exists
  if (!empty($row['photo'])) {
    $old = __DIR__ . '/../uploads/profiles/' . $row['photo'];
    if (file_exists($old)) unlink($old);
  }

  if (!move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
    echo json_encode(['success' => false, 'error' => 'move_failed']); exit;
  }

  $stmt = $conn->prepare("UPDATE `users` SET photo=:photo WHERE id=:uid");
  $stmt->execute([':photo' => $filename, ':uid' => $uid]);

  echo json_encode(['success' => true, 'filename' => $filename]);
}
else {
  echo json_encode(['success' => false, 'error' => 'unknown_action']);
}
