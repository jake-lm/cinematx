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

function parse_screentime($str) {
  $tz = new DateTimeZone('America/Chicago');
  $dt = DateTime::createFromFormat('Y-m-d\TH:i', $str, $tz);
  return $dt ? $dt->getTimestamp() : null;
}

if ($action === 'create') {
  $title      = trim($_POST['title']      ?? '');
  $location   = trim($_POST['location']   ?? '');
  $address    = trim($_POST['address']    ?? '');
  $screentime = parse_screentime($_POST['screentime'] ?? '');

  if (!$title || !$location || !$screentime) {
    echo json_encode(['success' => false, 'error' => 'missing_fields']); exit;
  }

  $stmt = $conn->prepare("INSERT INTO `events` (uid, title, screentime, location, address, stamp, active)
                          VALUES (:uid, :title, :screentime, :location, :address, :stamp, 0)");
  $stmt->execute([
    ':uid'        => $uid,
    ':title'      => $title,
    ':screentime' => $screentime,
    ':location'   => $location,
    ':address'    => $address ?: null,
    ':stamp'      => time(),
  ]);

  echo json_encode(['success' => true, 'event_id' => (int)$conn->lastInsertId()]);
}
else if ($action === 'update') {
  $event_id   = (int)($_POST['event_id'] ?? 0);
  $title      = trim($_POST['title']      ?? '');
  $location   = trim($_POST['location']   ?? '');
  $address    = trim($_POST['address']    ?? '');
  $screentime = parse_screentime($_POST['screentime'] ?? '');

  if (!$title || !$location || !$screentime) {
    echo json_encode(['success' => false, 'error' => 'missing_fields']); exit;
  }

  $stmt = $conn->prepare("UPDATE `events`
                          SET title=:title, screentime=:screentime, location=:location,
                              address=:address, edited=:edited
                          WHERE id=:id AND uid=:uid");
  $stmt->execute([
    ':title'      => $title,
    ':screentime' => $screentime,
    ':location'   => $location,
    ':address'    => $address ?: null,
    ':edited'     => time(),
    ':id'         => $event_id,
    ':uid'        => $uid,
  ]);

  echo json_encode(['success' => true]);
}
else if ($action === 'publish') {
  $event_id = (int)($_POST['event_id'] ?? 0);

  $stmt = $conn->prepare("UPDATE `events` SET active=1 WHERE id=:id AND uid=:uid");
  $stmt->execute([':id' => $event_id, ':uid' => $uid]);

  echo json_encode(['success' => true]);
}
else if ($action === 'unpublish') {
  $event_id = (int)($_POST['event_id'] ?? 0);

  $stmt = $conn->prepare("UPDATE `events` SET active=0 WHERE id=:id AND uid=:uid AND active=1");
  $stmt->execute([':id' => $event_id, ':uid' => $uid]);

  echo json_encode(['success' => true]);
}
else if ($action === 'get') {
  $event_id = (int)($_GET['event_id'] ?? 0);

  $stmt = $conn->prepare("SELECT id, title, poster, screentime, location, address, stamp, edited, active
                          FROM `events` WHERE id=:id AND uid=:uid");
  $stmt->execute([':id' => $event_id, ':uid' => $uid]);
  $event = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($event) {
    $tz = new DateTimeZone('America/Chicago');
    $event['screentime_local'] = (new DateTime('@' . $event['screentime']))->setTimezone($tz)->format('Y-m-d\TH:i');
    echo json_encode(['success' => true, 'event' => $event]);
  } else {
    echo json_encode(['success' => false, 'error' => 'not_found']);
  }
}
else if ($action === 'upload_poster') {
  $event_id = (int)($_POST['event_id'] ?? 0);

  $check = $conn->prepare("SELECT id, poster FROM `events` WHERE id=:id AND uid=:uid");
  $check->execute([':id' => $event_id, ':uid' => $uid]);
  $row = $check->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    echo json_encode(['success' => false, 'error' => 'not_found']); exit;
  }

  if (isset($_GET['remove']) && $_GET['remove'] === '1') {
    if (!empty($row['poster'])) {
      $old = __DIR__ . '/../uploads/events/' . $row['poster'];
      if (file_exists($old)) unlink($old);
    }
    $conn->prepare("UPDATE `events` SET poster=NULL WHERE id=:id AND uid=:uid")
         ->execute([':id' => $event_id, ':uid' => $uid]);
    echo json_encode(['success' => true]); exit;
  }

  if (!isset($_FILES['poster']) || $_FILES['poster']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'upload_error']); exit;
  }

  $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime  = finfo_file($finfo, $_FILES['poster']['tmp_name']);
  finfo_close($finfo);

  if (!in_array($mime, $allowed_types)) {
    echo json_encode(['success' => false, 'error' => 'invalid_type']); exit;
  }
  if ($_FILES['poster']['size'] > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'too_large']); exit;
  }

  $ext_map  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
  $ext      = $ext_map[$mime];
  $filename = $event_id . '_' . time() . '.' . $ext;
  $dest     = __DIR__ . '/../uploads/events/' . $filename;

  if (!empty($row['poster'])) {
    $old = __DIR__ . '/../uploads/events/' . $row['poster'];
    if (file_exists($old)) unlink($old);
  }

  if (!move_uploaded_file($_FILES['poster']['tmp_name'], $dest)) {
    echo json_encode(['success' => false, 'error' => 'move_failed']); exit;
  }

  $conn->prepare("UPDATE `events` SET poster=:poster WHERE id=:id AND uid=:uid")
       ->execute([':poster' => $filename, ':id' => $event_id, ':uid' => $uid]);

  echo json_encode(['success' => true, 'filename' => $filename]);
}
else if ($action === 'delete') {
  $event_id = (int)($_POST['event_id'] ?? 0);

  $check = $conn->prepare("SELECT poster FROM `events` WHERE id=:id AND uid=:uid AND active=0");
  $check->execute([':id' => $event_id, ':uid' => $uid]);
  $row = $check->fetch(PDO::FETCH_ASSOC);

  $stmt = $conn->prepare("DELETE FROM `events` WHERE id=:id AND uid=:uid AND active=0");
  $stmt->execute([':id' => $event_id, ':uid' => $uid]);

  if ($row && !empty($row['poster'])) {
    $old = __DIR__ . '/../uploads/events/' . $row['poster'];
    if (file_exists($old)) unlink($old);
  }

  echo json_encode(['success' => true]);
}
else {
  echo json_encode(['success' => false, 'error' => 'unknown_action']);
}
