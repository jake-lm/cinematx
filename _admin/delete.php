<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require '../database.php';

    $f_id = $_POST['film_id'];
    $sql = $conn->prepare("SELECT * FROM `films` WHERE `id` = :f_id");
    $sql->bindParam(':f_id', $f_id);
    $sql->execute();
    $film = $sql->fetch();

    $filepath = $_SERVER['DOCUMENT_ROOT'] . '/motw/' . $film['filename'] . '.mp4';
    $poster   = $_SERVER['DOCUMENT_ROOT'] . '/motw/' . $film['poster'] . '.png';

    unlink($filepath);
    unlink($poster);

    $stmt = $conn->prepare("UPDATE `films` SET `active` = 0 WHERE `id` = :f_id");
    $stmt->bindParam(':f_id', $f_id);
    $stmt->execute();

    header("Location: /_admin");
    exit;
?>
