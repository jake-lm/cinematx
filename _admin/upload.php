<?php
error_reporting(-1);
require '../database.php';

require 'getid3/getid3.php';

    $title = $_POST['title'];
    $director = $_POST['director'];
    $wiki = $_POST['wiki'];
    $program = $_POST['program'];
    $now = time();
    $motw = 0;

    function stripTitle($input_t) {
      $input_t = str_replace(' ', '', $input_t);
      $input_t = str_replace("'", "", $input_t);
      return $input_t;
    }
    $filename = stripTitle($title);
    $director = stripTitle($director);
    $filename = $filename . '-' . $director . '-' . time();

    $poster = $filename;

    $file_tmp =$_FILES['film']['tmp_name'];

    $poster_tmp =$_FILES['poster']['tmp_name'];

    $getID3 = new getID3;
    $file = $getID3->analyze($file_tmp);
    $dur = $file['playtime_string'];
    $dur = preg_replace("/^([\d]{1,2})\:([\d]{2})$/", "00:$1:$2", $dur);
    sscanf($dur, "%d:%d:%d", $hours, $minutes, $seconds);
    $dur = $hours * 3600 + $minutes * 60 + $seconds;
    //echo $dur;
    $active = 1;

    move_uploaded_file($file_tmp,'../motw/'.$filename.'.mp4');
    move_uploaded_file($poster_tmp,'../motw/'.$poster.'.png');

    $stmt = $conn->prepare("INSERT INTO `films` (title, director, wiki, program, dur, filename, poster, motw, active)
                           VALUES (:title, :director, :wiki, :program, :dur, :filename, :poster, :motw, :active)");

    $stmt->bindParam(':title', $title);
    $stmt->bindParam(':director', $_POST['director']);
    $stmt->bindParam(':wiki', $wiki);
    $stmt->bindParam(':program', $program);
    $stmt->bindParam(':dur', $dur);
    $stmt->bindParam(':filename', $filename);
    $stmt->bindParam(':motw', $motw);
    $stmt->bindParam(':active', $active);
    $stmt->bindParam(':poster', $poster);
    $stmt->execute();

    header("Location: /_admin");
    exit;

























?>
