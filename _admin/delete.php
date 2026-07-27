<?php
require __DIR__ . '/_guard.php';
admin_check_csrf();

    $f_id = (int)($_POST['film_id'] ?? 0);

    $sql = $conn->prepare("SELECT * FROM `films` WHERE `id` = :f_id LIMIT 1");
    $sql->execute([':f_id' => $f_id]);
    $film = $sql->fetch();

    // Without this, a missing row made $film false and the paths below became
    // "/motw/.mp4" — unlink() on a directory-adjacent path, for a delete that
    // was never going to match anything anyway.
    if (!$film) { http_response_code(400); exit('No such film.'); }

    // basename() for the same reason stream.php uses it: these columns end up
    // in a filesystem path, and nothing else guarantees they stay in /motw.
    $dir    = $_SERVER['DOCUMENT_ROOT'] . '/motw/';
    $filepath = $dir . basename((string)$film['filename']) . '.mp4';
    $poster   = $dir . basename((string)$film['poster'])   . '.png';

    if (is_file($filepath)) unlink($filepath);
    if (is_file($poster))   unlink($poster);

    $stmt = $conn->prepare("UPDATE `films` SET `active` = 0 WHERE `id` = :f_id");
    $stmt->execute([':f_id' => $f_id]);

    header("Location: /_admin");
    exit;
?>
