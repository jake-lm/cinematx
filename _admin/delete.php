<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require '../database.php';

    $f_id = $_POST['film_id'];
    $sql = $conn->prepare("SELECT * FROM `films` WHERE `id` = $f_id");
    $sql->execute();
    $film = $sql->fetch();
    $now = time();

    $filepath = $_SERVER['DOCUMENT_ROOT'] . '/motw/' . $film['filename'] . '.mp4';
    $poster = $_SERVER['DOCUMENT_ROOT'] . '/motw/' . $film['poster'] .'.png';

    unlink($filepath);
    unlink($poster);

    $active = 0;
    //$sql = "UPDATE `films` SET `active`=? WHERE `id`=?";
    //$conn->prepare($sql)->execute([$active, $f_id]);
    $conn->exec("DELETE FROM `films` WHERE `id` = $f_id");
    $conn->exec("DELETE FROM `notes` WHERE `f_id` = $f_id");
    $conn->exec("DELETE FROM `showtimes` WHERE `id` = $f_id");

    echo 'deleted.';

    //header("Location: http://www.j-l-m.net/_admin");
    //exit;






















?>
