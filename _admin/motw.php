<?php
require __DIR__ . '/_guard.php';
admin_check_csrf();

// This used to branch on `if (!$conn->query(...))`, but query() returns a
// statement object whether or not it matched any row, so the branch was dead
// and only the else ran. That path then prepared :motw_on and bound :motw,
// which PDO rejects — so setting the film of the week has not worked at all.
// It also exited without redirecting, leaving the admin on a blank page.
//
// There is only ever one film of the week, so: clear the flag, then set it.

$f_id = (int)($_POST['film_id2'] ?? 0);

$film = $conn->prepare("SELECT `id` FROM `films` WHERE `id` = :id LIMIT 1");
$film->execute([':id' => $f_id]);
if (!$film->fetch()) { http_response_code(400); exit('No such film.'); }

$conn->prepare("UPDATE `films` SET `motw` = 0 WHERE `motw` = 1")->execute();
$conn->prepare("UPDATE `films` SET `motw` = 1 WHERE `id` = :id")->execute([':id' => $f_id]);

header('Location: /_admin');
exit;
