<?php
require __DIR__ . '/_guard.php';
admin_check_csrf();

$f_id     = (int)($_POST['film_id'] ?? 0);
$theatre  = (int)($_POST['theatre'] ?? 0);

// createFromFormat returns false on a malformed value, and calling
// ->getTimestamp() on that is a fatal.
$showtime = DateTime::createFromFormat('Y-m-d\TH:i', $_POST['showtime'] ?? '', new DateTimeZone('America/Chicago'));
if (!$showtime) { http_response_code(400); exit('Bad showtime.'); }
$showtime = $showtime->getTimestamp();

$sql = $conn->prepare("SELECT * FROM `films` WHERE `id` = :id");
$sql->execute([':id' => $f_id]);
$film = $sql->fetch();
if (!$film) { http_response_code(400); exit('No such film.'); }

$endtime = $showtime + $film['dur'];
$stmt = $conn->prepare("INSERT INTO `showtimes` (f_id, showtime, endtime, theatre)
                       VALUES (:f_id, :showtime, :endtime, :theatre)");

$stmt->bindParam(':f_id', $f_id);
$stmt->bindParam(':showtime', $showtime);
$stmt->bindParam(':endtime', $endtime);
$stmt->bindParam(':theatre', $theatre);
$stmt->execute();

header("Location: /_admin/showtimes.php");
exit;
?>
