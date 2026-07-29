<?php
// A member can hold more than one of these — a name for the era before
// "Filmmaker" swallowed too much distinction and this file was a flat
// eleven-item list. Filmmaker is the only one with children: checking it
// is what makes someone a Filmmaker, and the specific ones underneath
// (Director, Producer, …) are additional, not instead of it. The rest are
// flat — nothing under them to expand into.
$roles = [
    'Filmmaker'  => ['Director', 'Producer', 'Cinematographer', 'Editor', 'Writer'],
    'Actor'      => null,
    'Critic'     => null,
    'Enthusiast' => null,
    'Member'     => null,
];

if (!function_exists('ctx_user_roles')) {

/** Every role a member has selected, flat — e.g. ['Filmmaker', 'Director', 'Producer']. */
function ctx_user_roles($conn, $uid) {
    $q = $conn->prepare("SELECT role FROM user_roles WHERE uid = :uid ORDER BY id ASC");
    $q->execute([':uid' => (int)$uid]);
    return $q->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * A flat role list, arranged for display: one entry per top-level tag the
 * member actually has, in $roles's own order, each carrying whichever of its
 * children (if any) were also picked. A tag with no children in the
 * taxonomy (Actor, Critic, …) always gets an empty subs list — nothing to
 * expand into, which is what tells the template to skip the caret.
 */
function ctx_roles_grouped(array $flat) {
    global $roles;
    $out = [];
    foreach ($roles as $top => $subs) {
        if (!in_array($top, $flat, true)) continue;
        $picked = [];
        foreach ((array)$subs as $s) if (in_array($s, $flat, true)) $picked[] = $s;
        $out[] = ['name' => $top, 'subs' => $picked];
    }
    return $out;
}

/**
 * Replace-all write for a member's roles: whatever is passed is the complete
 * set from here on, which matches how a page full of checkboxes actually
 * submits — there is no "the ones that changed", only "the ones that are
 * checked right now". Anything not in $roles's own taxonomy is dropped
 * rather than trusted, the same reasoning as any other whitelisted write.
 */
function ctx_save_user_roles($conn, $uid, array $selected) {
    global $roles;
    $valid = [];
    foreach ($roles as $top => $subs) {
        $valid[] = $top;
        foreach ((array)$subs as $s) $valid[] = $s;
    }
    $selected = array_values(array_unique(array_intersect($selected, $valid)));

    $conn->prepare("DELETE FROM `user_roles` WHERE `uid` = :uid")->execute([':uid' => (int)$uid]);
    if (!$selected) return;
    $insert = $conn->prepare("INSERT INTO `user_roles` (uid, role) VALUES (:uid, :role)");
    foreach ($selected as $role) $insert->execute([':uid' => (int)$uid, ':role' => $role]);
}

} // function_exists guard
?>
