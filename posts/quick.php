<?php
session_start();
require '../database.php';
header('Content-Type: application/json');

if (!isset($_SESSION['username'])) {
  echo json_encode(['success' => false, 'error' => 'not_logged_in']); exit;
}

$uid_q = $conn->prepare("SELECT id FROM users WHERE email = :email");
$uid_q->execute([':email' => $_SESSION['username']]);
$uid = $uid_q->fetchColumn();

if (!$uid) {
  echo json_encode(['success' => false, 'error' => 'user_not_found']); exit;
}

$type     = trim($_POST['type']     ?? '');
$content  = trim($_POST['content']  ?? '');
$title    = trim($_POST['title']    ?? '');
$subtitle = trim($_POST['subtitle'] ?? '');

if (!$content && !$title) {
  echo json_encode(['success' => false, 'error' => 'content required']); exit;
}

$post_type = in_array($type, ['review', 'essay', 'note']) ? $type : null;

$stmt = $conn->prepare(
  "INSERT INTO posts (uid, title, subtitle, content, type, stamp, active)
   VALUES (:uid, :title, :subtitle, :content, :type, :stamp, 1)"
);
$stmt->execute([
  ':uid'      => $uid,
  ':title'    => $title    ?: null,
  ':subtitle' => $subtitle ?: null,
  ':content'  => $content,
  ':type'     => $post_type,
  ':stamp'    => time(),
]);

echo json_encode(['success' => true]);
