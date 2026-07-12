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

if ($action === 'list') {
  $LIMIT = 8;

  $posts_q = $conn->prepare("SELECT id, title, type, stamp, edited, active
                             FROM `posts` WHERE uid=:uid
                             ORDER BY COALESCE(edited, stamp) DESC LIMIT :lim");
  $posts_q->bindValue(':uid', $uid, PDO::PARAM_INT);
  $posts_q->bindValue(':lim', $LIMIT, PDO::PARAM_INT);
  $posts_q->execute();

  $events_q = $conn->prepare("SELECT id, title, location, stamp, edited, active
                              FROM `events` WHERE uid=:uid
                              ORDER BY COALESCE(edited, stamp) DESC LIMIT :lim");
  $events_q->bindValue(':uid', $uid, PDO::PARAM_INT);
  $events_q->bindValue(':lim', $LIMIT, PDO::PARAM_INT);
  $events_q->execute();

  $items = [];
  foreach ($posts_q->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $ts = (int)($p['edited'] ?: $p['stamp']);
    $items[] = [
      'kind'     => 'post',
      'type'     => $p['type'] ?: 'post',
      'id'       => (int)$p['id'],
      'title'    => $p['title'] ?: 'Untitled',
      'subtitle' => $p['type'] ? ucfirst($p['type']) : 'Post',
      'status'   => (int)$p['active'] === 1 ? 'live' : 'draft',
      'ts'       => $ts,
      'date'     => date('M j', $ts),
    ];
  }
  foreach ($events_q->fetchAll(PDO::FETCH_ASSOC) as $e) {
    $ts = (int)($e['edited'] ?: $e['stamp']);
    $items[] = [
      'kind'     => 'event',
      'type'     => 'event',
      'id'       => (int)$e['id'],
      'title'    => $e['title'],
      'subtitle' => $e['location'],
      'status'   => (int)$e['active'] === 1 ? 'live' : 'draft',
      'ts'       => $ts,
      'date'     => date('M j', $ts),
    ];
  }

  usort($items, fn($a, $b) => $b['ts'] <=> $a['ts']);
  $items = array_slice($items, 0, $LIMIT);

  echo json_encode(['success' => true, 'items' => $items]);
}
else {
  echo json_encode(['success' => false, 'error' => 'unknown_action']);
}
