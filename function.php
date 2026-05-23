<?php
ini_set('display_errors', 1);
session_start();
error_reporting(1);
$action = $_GET['action'];
require 'database.php';

$user = $_SESSION['username'];
$sql1 = $conn->prepare("SELECT * FROM `users` WHERE `email` = '$user'");
$sql1->execute();
$qUser=$sql1->fetch();
$u_id = $qUser['id'];

if($action === "loadmore") {
  $limit = $_POST['limit'];
  $option = $_POST['option'];
  $dept = $_POST['dept'];
  if($option === "dept") {
    $sql1 = $conn->prepare("SELECT * FROM `users` WHERE `active` = 1 AND `dept` = '".$dept."' AND `name` != '' ORDER BY '" . $option . "' DESC LIMIT 8 OFFSET " . $limit);
    $rows_count = $conn->query("SELECT count(*) FROM `users` WHERE `active` = 1 AND `dept` = '".$dept."' AND `name` != ''")->fetchColumn();

  }
  else {
    $sql1 = $conn->prepare("SELECT * FROM `users` WHERE `active` = 1 AND `dept` != '0' AND `name` != '' ORDER BY '" . $option . "' DESC LIMIT 8 OFFSET " . $limit);
    $rows_count = $conn->query("SELECT count(*) FROM `users` WHERE `active` = 1 AND `dept` != '0' AND `name` != ''")->fetchColumn();
  }
  $sql1->execute();

  $rows_return = 8; // rows to print

  if($limit > $rows_count) $rows_return = 0;

  for($i = 0;$i < $rows_return ;++$i) {
    $lUser = $sql1->fetch();
    $name = $lUser['name'];

    if ($i % 2 == 0 )
      $type = "odd";
    else
      $type = "even";

    include 'entries.php';
  }
}
else if ($action === "mostactive") { // recent logins
  $sort = $_POST['sort'];
  $sql1 = $conn->prepare("SELECT * FROM `users` WHERE `active` = 1 AND `dept` != '0' AND `name` != '' ORDER BY `last_date` DESC LIMIT 8");
  $sql1->execute();

  for($i = 0;$i < 8 ;++$i) {
    $lUser = $sql1->fetch();
    $name = $lUser['name'];

    if ($i % 2 == 0 )
      $type = "odd";
    else
      $type = "even";

      include 'entries.php';

  }
}
else if ($action === "mydept") { // same dept
  $dept = $_POST['dept'];
  $sql1 = $conn->prepare("SELECT * FROM `users` WHERE `active` = 1 AND `dept` LIKE '%".$dept."%' AND `name` != '' ORDER BY `dept` DESC LIMIT 8");
  $sql1->execute();

  for($i = 0;$i < 8 ;++$i) {
    $lUser = $sql1->fetch();
    $name = $lUser['name'];

    if ($i % 2 == 0 )
      $type = "odd";
    else
      $type = "even";

      include 'entries.php';

  }
}
else if ($action === "mylist") { // my list

  $sql2 = $conn->prepare("SELECT * FROM `mylist` WHERE `fid` = '".$u_id."' ORDER BY `id` DESC LIMIT 8");
  $sql2->execute();

  for($i = 0;$i < 8 ;++$i) {
    $mUser = $sql2->fetch();
    $mid = $mUser['uid'];
    $sql1 = $conn->prepare("SELECT * FROM `users` WHERE `id` = '".$mid."' ORDER BY `id` DESC LIMIT 8");
    $sql1->execute();
    $lUser = $sql1->fetch();
    $name = $lUser['name'];

    if ($i % 2 == 0 )
      $type = "odd";
    else
      $type = "even";

      include 'entries.php';

  }
}
else if ($action === "new") { // most recent sign ups
  $sql1 = $conn->prepare("SELECT * FROM `users` WHERE `active` = 1 AND `dept` != '0' AND `name` != '' ORDER BY `sign_date` DESC LIMIT 8");
  $sql1->execute();

  for($i = 0;$i < 8 ;++$i) {
    $lUser = $sql1->fetch();
    $name = $lUser['name'];

    if ($i % 2 == 0 )
      $type = "odd";
    else
      $type = "even";

      include 'entries.php';

  }
}
else if($action === "reload") {
  $sql1 = $conn->prepare("SELECT * FROM `users` WHERE `active` = 1 AND `dept` != '0' AND `name` != '' ORDER BY `sign_date` DESC LIMIT 8");
  $sql1->execute();

  for($i = 0;$i < 8 ;++$i) {
    $lUser = $sql1->fetch();
    $name = $lUser['name'];

    if ($i % 2 == 0 )
      $type = "odd";
    else
      $type = "even";

      include 'entries.php';

  }
}
else if($action === "listsearch") { // shitty search
  $search = $_POST['search'];
  $sql1 = $conn->prepare("SELECT * FROM `users` WHERE `active` = 1 AND `dept` != '0'
    AND `name` LIKE '%".$search."%' OR `email` LIKE '%".$search."%' OR `phone` LIKE '%".$search."%'
    OR `dept` LIKE '%".$search."%' OR `position` LIKE '%".$search."%' ORDER BY `name` DESC LIMIT 8");
  $sql1->execute();

  for($i = 0;$i < 8 ;++$i) {
    $lUser = $sql1->fetch();
    $name = $lUser['name'];

    if ($i % 2 == 0 )
      $type = "odd";
    else
      $type = "even";

      include 'entries.php';

  }
}
else if($action === "addto") {
  $uid = $_POST['uid'];
  $fid = $_POST['fid'];
  $f_date = time();

  if($uid === $fid || $uid === 0 || $fid === 0 || $uid === null || $fid === null) {
    exit;
  }

  $stmt = $conn->prepare("INSERT INTO `mylist` (uid, fid, f_date)
                         VALUES (:uid, :fid, :f_date)");

  $stmt->bindParam(':uid', $uid);
  $stmt->bindParam(':fid', $fid);
  $stmt->bindParam(':f_date', $f_date);

  $stmt->execute();
}
else if($action === "removefrom") {
  $uid = $_POST['uid'];
  $fid = $_POST['fid'];

  if($uid === $fid || $uid === 0 || $pid === 0 || $uid === null || $fid === null) {
    exit;
  }

  $stmt = $conn->prepare("DELETE FROM `mylist` WHERE `uid` = :uid AND `fid` = :fid");

  $stmt->bindParam(':uid', $uid);
  $stmt->bindParam(':fid', $fid);

  $stmt->execute();
}
?>
