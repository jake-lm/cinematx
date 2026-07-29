<?php
require __DIR__ . '/_guard.php';
admin_check_csrf();

require 'getid3/getid3.php';

    $title = $_POST['title'];
    $director = $_POST['director'];
    $wiki = $_POST['wiki'];
    $program = $_POST['program'];
    $now = time();
    $motw = 0;

    // This becomes a filesystem path, so it has to be a filename and nothing
    // else. Stripping spaces and apostrophes left slashes and dots intact —
    // a title of "../../x" wrote outside /motw entirely.
    function stripTitle($input_t) {
      $input_t = preg_replace('/[^A-Za-z0-9]/', '', (string)$input_t);
      return $input_t !== '' ? substr($input_t, 0, 60) : 'untitled';
    }
    $filename = stripTitle($title);
    $director = stripTitle($director);
    $filename = $filename . '-' . $director . '-' . time();

    $poster = $filename;

    $file_tmp   = $_FILES['film']['tmp_name']   ?? '';
    $poster_tmp = $_FILES['poster']['tmp_name'] ?? '';

    // getID3 on a missing upload produces a confusing failure several lines
    // down rather than here, where the cause is obvious.
    if (!is_uploaded_file($file_tmp) || !is_uploaded_file($poster_tmp)) {
      http_response_code(400);
      exit('Both a film and a poster are required.');
    }

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

    // Upload and the programmer's note happen in one submit now — a note is
    // optional, and when given it's attached to the film just created rather
    // than asking which film it belongs to.
    $note = trim($_POST['note'] ?? '');
    if ($note !== '') {
      $f_id = $conn->lastInsertId();
      $noteStmt = $conn->prepare("INSERT INTO `notes` (f_id, note, stamp)
                                  VALUES (:f_id, :note, :stamp)");
      $noteStmt->bindParam(':f_id', $f_id);
      $noteStmt->bindParam(':note', $note);
      $noteStmt->bindParam(':stamp', $now);
      $noteStmt->execute();
    }

    header("Location: /_admin/films.php");
    exit;

























?>
