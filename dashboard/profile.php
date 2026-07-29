<?php
session_start();
require '../database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['username'])) {
  echo json_encode(['success' => false, 'error' => 'not_logged_in']);
  exit;
}

$uid_q = $conn->prepare("SELECT `id`, `photo`, `banner` FROM `users` WHERE `email` = :email");
$uid_q->execute([':email' => $_SESSION['username']]);
$row = $uid_q->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  echo json_encode(['success' => false, 'error' => 'user_not_found']);
  exit;
}
$uid = $row['id'];

$action = $_GET['action'] ?? '';

/**
 * The square profile photo and the wide header banner are the same upload
 * shape — a validated image written to uploads/<dir>/, its filename saved to
 * one column, the old file removed first. $files_key differs only because
 * the photo uploader already shipped POSTing as `photo`; the banner is new,
 * so it gets the more generic `image` rather than perpetuating that name.
 */
function upload_user_image($conn, $uid, $field, $dir, $files_key, $existing) {
  if (isset($_GET['remove']) && $_GET['remove'] === '1') {
    if (!empty($existing)) {
      $old = __DIR__ . '/../uploads/' . $dir . '/' . $existing;
      if (file_exists($old)) unlink($old);
    }
    $conn->prepare("UPDATE `users` SET `$field`=NULL WHERE id=:uid")->execute([':uid' => $uid]);
    echo json_encode(['success' => true]); exit;
  }

  if (!isset($_FILES[$files_key]) || $_FILES[$files_key]['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'upload_error']); exit;
  }

  $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime  = finfo_file($finfo, $_FILES[$files_key]['tmp_name']);
  finfo_close($finfo);

  if (!in_array($mime, $allowed_types)) {
    echo json_encode(['success' => false, 'error' => 'invalid_type']); exit;
  }
  if ($_FILES[$files_key]['size'] > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'too_large']); exit;
  }

  $ext_map  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
  $ext      = $ext_map[$mime];
  $filename = $uid . '_' . $field . '_' . time() . '.' . $ext;
  $destDir  = __DIR__ . '/../uploads/' . $dir;
  if (!is_dir($destDir)) mkdir($destDir, 0775, true);
  $dest = $destDir . '/' . $filename;

  if (!empty($existing)) {
    $old = __DIR__ . '/../uploads/' . $dir . '/' . $existing;
    if (file_exists($old)) unlink($old);
  }

  if (!move_uploaded_file($_FILES[$files_key]['tmp_name'], $dest)) {
    echo json_encode(['success' => false, 'error' => 'move_failed']); exit;
  }

  $conn->prepare("UPDATE `users` SET `$field`=:v WHERE id=:uid")->execute([':v' => $filename, ':uid' => $uid]);
  echo json_encode(['success' => true, 'filename' => $filename]);
  exit;
}

if ($action === 'upload_photo') {
  upload_user_image($conn, $uid, 'photo', 'profiles', 'photo', $row['photo']);
}
else if ($action === 'upload_banner') {
  upload_user_image($conn, $uid, 'banner', 'banners', 'image', $row['banner']);
}
else {
  echo json_encode(['success' => false, 'error' => 'unknown_action']);
}
