<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Saved-members endpoint
//
//  This file used to carry the directory's paging and search: loadmore,
//  mostactive, mydept, mylist, new, reload and listsearch. Every one of them
//  interpolated POST data straight into SQL, and listsearch's OR chain also
//  broke the `active` guard, so inactive accounts leaked into results.
//
//  The converted directory renders all members server-side and filters in the
//  browser, so none of that is reachable any more and it has been removed
//  along with entries.php, the row template it rendered. Both remain in git
//  history if ever needed.
// ═══════════════════════════════════════════════════════════════════════════
session_start();
require __DIR__ . '/database.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

// Whose list this is always comes from the session — never from POST, which
// previously let a caller write into another member's list.
$q = $conn->prepare("SELECT `id` FROM `users` WHERE `email` = :email LIMIT 1");
$q->execute([':email' => $_SESSION['username'] ?? null]);
$me = $q->fetchColumn();

if (!$me) { echo json_encode(['ok' => false, 'error' => 'not_signed_in']); exit; }

$uid = (int)($_POST['uid'] ?? 0);

if ($action === 'addto') {
    if (!$uid || $uid === (int)$me) { echo json_encode(['ok' => false]); exit; }

    $dup = $conn->prepare("SELECT COUNT(*) FROM `mylist` WHERE `uid` = :uid AND `fid` = :fid");
    $dup->execute([':uid' => $uid, ':fid' => $me]);

    if (!$dup->fetchColumn()) {
        $conn->prepare("INSERT INTO `mylist` (uid, fid, f_date) VALUES (:uid, :fid, :f_date)")
             ->execute([':uid' => $uid, ':fid' => $me, ':f_date' => time()]);
    }
    echo json_encode(['ok' => true, 'saved' => true]);
}
else if ($action === 'removefrom') {
    if (!$uid) { echo json_encode(['ok' => false]); exit; }

    $conn->prepare("DELETE FROM `mylist` WHERE `uid` = :uid AND `fid` = :fid")
         ->execute([':uid' => $uid, ':fid' => $me]);
    echo json_encode(['ok' => true, 'saved' => false]);
}
else {
    echo json_encode(['ok' => false, 'error' => 'unknown_action']);
}
