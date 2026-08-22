<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin — create or update an admin-added screening
//
//  Same `events` table the member-submission flow (dashboard/event.php)
//  writes to, so it renders through every existing consumer — /list,
//  /events/?id=, and (via list/instagram.php's ig_arthouse_films() source
//  check) the Instagram admin panel — with no separate code path. What
//  makes a row "admin-added" rather than a real member submission is purely
//  that its uid belongs to an admin (list/fetch_screenings.php checks
//  users.admin at read time), so this handler only ever needs to scope
//  updates/deletes to the signed-in admin's own uid, same as the member
//  flow already scopes to its own.
//
//  Published immediately (active = 1) — no draft/publish step, since this
//  is trusted admin input, not something to review before it goes live.
//
//  Poster upload happens in the same request as the rest of the fields,
//  unlike the member flow's separate two-step create-then-upload — matters
//  less here since it's one person filling out one form, not a save now/
//  polish later flow. Validation (mime allowlist, 5MB cap, filename
//  pattern) matches dashboard/event.php's upload_poster action exactly.
// ═══════════════════════════════════════════════════════════════════════════
require __DIR__ . '/_guard.php';
admin_check_csrf();

function events_parse_screentime($str) {
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $str, new DateTimeZone('America/Chicago'));
    return $dt ? $dt->getTimestamp() : null;
}

function events_fail($msg) {
    header('Location: /_admin/events.php?error=' . urlencode($msg));
    exit;
}

const EVENT_POSTER_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
const EVENT_POSTER_MAX   = 5 * 1024 * 1024;

// Validates and moves the upload, returning the new filename — or null if
// no file was submitted at all (not an error; the poster stays whatever it
// already was). Throws via events_fail() on anything actually wrong so a
// bad upload never leaves a half-saved row.
function events_handle_poster($event_id, $existingPoster) {
    if (!isset($_FILES['poster']) || $_FILES['poster']['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($_FILES['poster']['error'] !== UPLOAD_ERR_OK) events_fail('Poster upload failed.');
    if ($_FILES['poster']['size'] > EVENT_POSTER_MAX) events_fail('Poster is too large (5MB max).');

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $_FILES['poster']['tmp_name']);
    finfo_close($finfo);
    if (!isset(EVENT_POSTER_TYPES[$mime])) events_fail('Poster must be a JPEG, PNG, WebP, or GIF.');

    $dir = dirname(__DIR__) . '/uploads/events';
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    $filename = $event_id . '_' . time() . '.' . EVENT_POSTER_TYPES[$mime];
    if (!move_uploaded_file($_FILES['poster']['tmp_name'], $dir . '/' . $filename)) {
        events_fail('Could not save the uploaded poster.');
    }
    if ($existingPoster && file_exists($dir . '/' . $existingPoster)) unlink($dir . '/' . $existingPoster);

    return $filename;
}

$event_id   = (int) ($_POST['event_id'] ?? 0);
$title      = trim($_POST['title']    ?? '');
$director   = trim($_POST['director'] ?? '');
$location   = trim($_POST['location'] ?? '');
$address    = trim($_POST['address']  ?? '');
$synopsis   = trim($_POST['synopsis'] ?? '');
$screentime = events_parse_screentime($_POST['screentime'] ?? '');

if ($title === '' || $location === '' || $screentime === null) {
    events_fail('Title, venue, and date & time are all required.');
}

if ($event_id) {
    $check = $conn->prepare("SELECT poster FROM `events` WHERE id = :id AND uid = :uid");
    $check->execute([':id' => $event_id, ':uid' => $admin_user['id']]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);
    if (!$existing) events_fail('That screening was not found.');

    $poster = events_handle_poster($event_id, $existing['poster']);

    $sql = "UPDATE `events` SET title=:title, director=:director, screentime=:screentime, location=:location,
                address=:address, synopsis=:synopsis, edited=:edited" . ($poster !== null ? ', poster=:poster' : '') . "
            WHERE id=:id AND uid=:uid";
    $params = [
        ':title' => $title, ':director' => $director ?: null, ':screentime' => $screentime, ':location' => $location,
        ':address' => $address ?: null, ':synopsis' => $synopsis ?: null, ':edited' => time(),
        ':id' => $event_id, ':uid' => $admin_user['id'],
    ];
    if ($poster !== null) $params[':poster'] = $poster;
    $conn->prepare($sql)->execute($params);
} else {
    $stmt = $conn->prepare(
        "INSERT INTO `events` (uid, title, director, screentime, location, address, synopsis, stamp, active)
         VALUES (:uid, :title, :director, :screentime, :location, :address, :synopsis, :stamp, 1)"
    );
    $stmt->execute([
        ':uid' => $admin_user['id'], ':title' => $title, ':director' => $director ?: null, ':screentime' => $screentime,
        ':location' => $location, ':address' => $address ?: null, ':synopsis' => $synopsis ?: null,
        ':stamp' => time(),
    ]);
    $event_id = (int) $conn->lastInsertId();

    $poster = events_handle_poster($event_id, null);
    if ($poster !== null) {
        $conn->prepare("UPDATE `events` SET poster=:poster WHERE id=:id")
             ->execute([':poster' => $poster, ':id' => $event_id]);
    }
}

header('Location: /_admin/events.php');
exit;
