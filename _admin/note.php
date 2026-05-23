<?php
error_reporting(-1);
require '../database.php';

    $f_id = $_POST['film_id'];
    $note = $_POST['note'];
    $now = time();

    $stmt1 = $conn->prepare("INSERT INTO `notes` (f_id, note, stamp)
                            VALUES (:f_id, :note, :stamp)");

    $stmt1->bindParam(':f_id',$f_id);
    $stmt1->bindParam(':note',$note);
    $stmt1->bindParam(':stamp',$now);
    $stmt1->execute();

    //header("Location: http://www.j-l-m.net/_admin");
    //exit;






















?>
