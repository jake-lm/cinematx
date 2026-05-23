<?php
error_reporting(1);
require '../database.php';

$f_id = $_POST['film_id2'];

$sql = $conn->prepare("SELECT * FROM `films` WHERE `id` = $f_id");
$sql->execute();
$film = $sql->fetch();

$motw_off = 0;
$motw_on = 1;

$qMotw = $conn->query("SELECT * FROM `films` WHERE `motw` = 1");

if( ! $qMotw) {
  $stmt = $conn->prepare("UPDATE `films` SET motw=? WHERE id=?");
  $stmt->execute(array('1',$f_id));
  echo 'here';
}
else {

  $stmt = $conn->prepare("UPDATE `films` SET `motw` = :motw_off WHERE `motw` = :motw_on");
  $stmt->bindParam(':motw_off', $motw_off);
  $stmt->bindParam(':motw_on', $motw_on);
  $stmt->execute();

  $stmt = $conn->prepare("UPDATE `films` SET `motw` = :motw_on WHERE `id` = :f_id");
  $stmt->bindParam(':motw', $motw_on);
  $stmt->bindParam(':f_id', $f_id);
  $stmt->execute();
  exit();

}
?>
