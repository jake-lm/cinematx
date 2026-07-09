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

function handle_poster_upload($event_id, $old_poster) {
  if (!isset($_FILES['poster']) || $_FILES['poster']['error'] === UPLOAD_ERR_NO_FILE) {
    return ['ok' => true, 'filename' => $old_poster]; // no new file — keep existing
  }
  if ($_FILES['poster']['error'] !== UPLOAD_ERR_OK) {
    return ['ok' => false, 'error' => 'upload_error'];
  }

  $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime  = finfo_file($finfo, $_FILES['poster']['tmp_name']);
  finfo_close($finfo);

  if (!in_array($mime, $allowed_types)) {
    return ['ok' => false, 'error' => 'invalid_type'];
  }
  if ($_FILES['poster']['size'] > 5 * 1024 * 1024) {
    return ['ok' => false, 'error' => 'too_large'];
  }

  $ext_map  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
  $ext      = $ext_map[$mime];
  $filename = $event_id . '_' . time() . '.' . $ext;
  $dest     = __DIR__ . '/../uploads/events/' . $filename;

  if (!move_uploaded_file($_FILES['poster']['tmp_name'], $dest)) {
    return ['ok' => false, 'error' => 'move_failed'];
  }

  if (!empty($old_poster)) {
    $old = __DIR__ . '/../uploads/events/' . $old_poster;
    if (file_exists($old)) unlink($old);
  }

  return ['ok' => true, 'filename' => $filename];
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
                          VALUES (:uid, :title, :screentime, :location, :address, :stamp, 1)");
  $stmt->execute([
    ':uid'        => $uid,
    ':title'      => $title,
    ':screentime' => $screentime,
    ':location'   => $location,
    ':address'    => $address ?: null,
    ':stamp'      => time(),
  ]);
  $event_id = (int)$conn->lastInsertId();

  $poster_result = handle_poster_upload($event_id, null);
  if (!$poster_result['ok']) {
    echo json_encode(['success' => true, 'event_id' => $event_id, 'poster_error' => $poster_result['error']]); exit;
  }
  if ($poster_result['filename']) {
    $conn->prepare("UPDATE `events` SET poster=:poster WHERE id=:id")
         ->execute([':poster' => $poster_result['filename'], ':id' => $event_id]);
  }

  echo json_encode(['success' => true, 'event_id' => $event_id]);
}
else if ($action === 'update') {
  $event_id = (int)($_POST['event_id'] ?? 0);

  $check = $conn->prepare("SELECT poster FROM `events` WHERE id=:id AND uid=:uid");
  $check->execute([':id' => $event_id, ':uid' => $uid]);
  $row = $check->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    echo json_encode(['success' => false, 'error' => 'not_found']); exit;
  }

  $title      = trim($_POST['title']      ?? '');
  $location   = trim($_POST['location']   ?? '');
  $address    = trim($_POST['address']    ?? '');
  $screentime = parse_screentime($_POST['screentime'] ?? '');

  if (!$title || !$location || !$screentime) {
    echo json_encode(['success' => false, 'error' => 'missing_fields']); exit;
  }

  $poster_result = handle_poster_upload($event_id, $row['poster']);
  $poster = $poster_result['ok'] ? $poster_result['filename'] : $row['poster'];

  $stmt = $conn->prepare("UPDATE `events`
                          SET title=:title, screentime=:screentime, location=:location,
                              address=:address, poster=:poster, edited=:edited
                          WHERE id=:id AND uid=:uid");
  $stmt->execute([
    ':title'      => $title,
    ':screentime' => $screentime,
    ':location'   => $location,
    ':address'    => $address ?: null,
    ':poster'     => $poster,
    ':edited'     => time(),
    ':id'         => $event_id,
    ':uid'        => $uid,
  ]);

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
else if ($action === 'delete') {
  $event_id = (int)($_POST['event_id'] ?? 0);

  $check = $conn->prepare("SELECT poster FROM `events` WHERE id=:id AND uid=:uid");
  $check->execute([':id' => $event_id, ':uid' => $uid]);
  $row = $check->fetch(PDO::FETCH_ASSOC);

  $stmt = $conn->prepare("DELETE FROM `events` WHERE id=:id AND uid=:uid");
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
