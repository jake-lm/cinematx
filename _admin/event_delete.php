<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — delete an admin-added screening
//
//  Scoped to uid = the signed-in admin, same as the member flow's own
//  delete scopes to its own uid — this panel can only ever remove a row it
//  added itself, never a real member's submission.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();

$event_id = (int) ($_POST['event_id'] ?? 0);

$check = $conn->prepare("SELECT poster FROM `events` WHERE id = :id AND uid = :uid");
$check->execute([':id' => $event_id, ':uid' => $admin_user['id']]);
$row = $check->fetch(PDO::FETCH_ASSOC);

$conn->prepare("DELETE FROM `events` WHERE id = :id AND uid = :uid")
     ->execute([':id' => $event_id, ':uid' => $admin_user['id']]);

if ($row && !empty($row['poster'])) {
    $path = dirname(__DIR__) . '/uploads/events/' . $row['poster'];
    if (file_exists($path)) unlink($path);
}

header('Location: /_admin/events.php');
exit;
