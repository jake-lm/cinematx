<?php
error_reporting(0);
session_start();
require '../database.php';
header('Content-Type: application/json');

if (!isset($_SESSION['username'])) {
  echo json_encode(['success' => false, 'error' => 'not_logged_in']); exit;
}

$uid_q = $conn->prepare("SELECT id, name FROM users WHERE email = :email");
$uid_q->execute([':email' => $_SESSION['username']]);
$uid_row = $uid_q->fetch(PDO::FETCH_ASSOC);

if (!$uid_row) {
  echo json_encode(['success' => false, 'error' => 'user_not_found']); exit;
}
$uid         = $uid_row['id'];
$author_name = $uid_row['name'];

$type = trim($_POST['type'] ?? '');

if ($type === 'delete') {
  $post_id = (int)($_POST['post_id'] ?? 0);
  if (!$post_id) { echo json_encode(['success' => false, 'error' => 'missing id']); exit; }
  $stmt = $conn->prepare("UPDATE posts SET active=0 WHERE id=:id AND uid=:uid");
  $stmt->execute([':id' => $post_id, ':uid' => $uid]);
  echo json_encode(['success' => true]); exit;
}

$content  = trim($_POST['content']  ?? '');
$title    = trim($_POST['title']    ?? '');
$subtitle = trim($_POST['subtitle'] ?? '');

if (!$content && !$title) {
  echo json_encode(['success' => false, 'error' => 'content required']); exit;
}

$post_type = in_array($type, ['post', 'review', 'essay', 'note']) ? $type : 'post';

try {
  $stmt = $conn->prepare(
    "INSERT INTO posts (uid, title, subtitle, content, type, stamp, active)
     VALUES (:uid, :title, :subtitle, :content, :type, :stamp, 1)"
  );
  $stmt->execute([
    ':uid'      => $uid,
    ':title'    => $title    ?: '',
    ':subtitle' => $subtitle ?: null,
    ':content'  => $content,
    ':type'     => $post_type,
    ':stamp'    => time(),
  ]);
} catch (PDOException $e) {
  echo json_encode(['success' => false, 'error' => $e->getMessage()]); exit;
}

echo json_encode([
  'success' => true,
  'post' => [
    'id'          => (int)$conn->lastInsertId(),
    'content'     => $content,
    'author_name' => $author_name,
    'date'        => date('M j'),
  ]
]);
