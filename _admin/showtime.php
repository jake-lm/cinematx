<?php
error_reporting(E_ALL);
require '../database.php';

$f_id = $_POST['film_id'];
$showtime = $_POST['showtime'];
$theatre = $_POST['theatre'];
//echo $showtime;
$showtime = DateTime::createFromFormat('Y-m-d\TH:i', $showtime, new DateTimeZone('America/Chicago'));
$showtime = $showtime->getTimestamp();

$sql = $conn->prepare("SELECT * FROM `films` WHERE `id` = $f_id");
$sql->execute();
$film=$sql->fetch();

$endtime = $showtime + $film['dur'];
$stmt = $conn->prepare("INSERT INTO `showtimes` (f_id, showtime, endtime, theatre)
                       VALUES (:f_id, :showtime, :endtime, :theatre)");

$stmt->bindParam(':f_id', $f_id);
$stmt->bindParam(':showtime', $showtime);
$stmt->bindParam(':endtime', $endtime);
$stmt->bindParam(':theatre', $theatre);
$stmt->execute();

header("Location: /_admin");
exit;
?>
