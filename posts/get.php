<?php
require '../database.php';
header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { echo json_encode(['error' => 'invalid']); exit; }

$stmt = $conn->prepare(
  "SELECT p.*, u.name AS author_name
   FROM posts p LEFT JOIN users u ON p.uid = u.id
   WHERE p.id = :id AND p.active = 1"
);
$stmt->execute([':id' => $id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) { echo json_encode(['error' => 'not found']); exit; }

echo json_encode(['post' => $post]);
