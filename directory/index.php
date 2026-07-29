<?php
// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — The Directory
//
//  Every member, rendered server-side and filtered in the browser. The old
//  page paged through function.php's loadmore / listsearch / mydept branches,
//  all of which interpolated POST data straight into SQL — and listsearch's
//  OR chain also broke the `active` guard, leaking inactive accounts into
//  results. At this scale none of that machinery earns its keep.
// ═══════════════════════════════════════════════════════════════════════════
require dirname(__DIR__) . '/v7/_lib.php';

$me = ctx_me($conn);
if (!$me || ctx_state($conn) !== 'member') { header('Location: /'); exit; }

$q = $conn->prepare(
  "SELECT id, name, email, phone, website, lb, photo, sign_date
   FROM `users`
   WHERE `active` = 1 AND `name` != ''
     AND EXISTS (SELECT 1 FROM `user_roles` ur WHERE ur.uid = users.id)
   ORDER BY `sign_date` DESC"
);
$q->execute();
$members = $q->fetchAll(PDO::FETCH_ASSOC);

// A query per member rather than a JOIN this page would only have to
// de-duplicate — the roster is a few dozen rows at most, not thousands.
foreach ($members as &$m) $m['roles'] = ctx_roles_grouped(ctx_user_roles($conn, $m['id']));
unset($m);

// Whose entries this member has saved — one query, where the old entries.php
// ran a COUNT per row.
$q = $conn->prepare("SELECT `uid` FROM `mylist` WHERE `fid` = :fid");
$q->execute([':fid' => $me['id']]);
$saved = array_flip($q->fetchAll(PDO::FETCH_COLUMN));

// Chips are the top-level tags only (Filmmaker, not Director/Producer/…) —
// the same "profile gets the detail, the list gets the summary" split as
// the roster row itself.
$roles_present = [];
foreach ($members as $m) {
    foreach ($m['roles'] as $rg) $roles_present[$rg['name']] = ($roles_present[$rg['name']] ?? 0) + 1;
}
ksort($roles_present);

$e = 'ctx_e';

$ctx_title  = 'Directory — Cinema, TX';
$ctx_active = 'directory';
$ctx_scroll = true;
$ctx_video  = false;

require dirname(__DIR__) . '/v7/_head.php';
require dirname(__DIR__) . '/v7/_chrome.php';
?>

  <main class="canvas">
    <div class="listing">

      <div class="listing__head">
        <div class="listing__title">
          <span class="card__n">05</span>
          <h1 class="listing__h">The Directory</h1>
          <span class="listing__sub"><span id="dir-count"><?php echo count($members); ?></span> members</span>
        </div>

        <div class="listing__controls">
          <label class="search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input class="search__input" id="dir-search" type="search"
                   placeholder="Name, role, area code…" autocomplete="off" />
          </label>

          <div class="chips">
            <button class="chip is-on" data-role="all">All<span class="chip__n"><?php echo count($members); ?></span></button>
            <?php foreach ($roles_present as $r => $n): ?>
            <button class="chip" data-role="<?php echo $e(ctx_slug($r)); ?>">
              <?php echo $e($r); ?><span class="chip__n"><?php echo $n; ?></span>
            </button>
            <?php endforeach; ?>
            <?php if ($saved): ?>
            <button class="chip chip--member" data-role="saved">Saved<span class="chip__n"><?php echo count($saved); ?></span></button>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="roster" id="roster">
        <?php foreach ($members as $m):
          $is_saved  = isset($saved[$m['id']]);
          $is_me     = (int)$m['id'] === (int)$me['id'];
          $top_names = array_column($m['roles'], 'name');
          // Every level of every tag — searching "director" should find a
          // Filmmaker who picked Director, not just literal top-level names.
          $all_role_words = $top_names;
          foreach ($m['roles'] as $rg) $all_role_words = array_merge($all_role_words, $rg['subs']);
          // One searchable haystack, so the client filter stays trivial.
          $hay = mb_strtolower(trim($m['name'] . ' ' . implode(' ', $all_role_words) . ' ' . $m['email'] . ' ' . $m['phone']));
          // Space-separated: a member with more than one tag now needs to
          // match more than one filter chip.
          $role_slugs = implode(' ', array_map('ctx_slug', $top_names));
        ?>
        <div class="member" data-role="<?php echo $e($role_slugs); ?>"
             data-saved="<?php echo $is_saved ? '1' : '0'; ?>"
             data-search="<?php echo $e($hay); ?>">

          <a class="member__face" href="/users/profile.php?id=<?php echo (int)$m['id']; ?>">
            <?php if (!empty($m['photo'])): ?>
            <img src="/uploads/profiles/<?php echo $e($m['photo']); ?>" alt="" />
            <?php else: ?><?php echo $e(mb_substr($m['name'], 0, 1)); ?><?php endif; ?>
          </a>

          <div class="member__body">
            <a class="member__name" href="/users/profile.php?id=<?php echo (int)$m['id']; ?>">
              <?php echo $e($m['name']); ?><?php if ($is_me): ?> <span class="member__you">you</span><?php endif; ?>
            </a>
            <?php if ($top_names): ?><div class="member__role"><?php echo $e(implode(', ', $top_names)); ?></div><?php endif; ?>
          </div>

          <div class="member__links">
            <?php if (!empty($m['email'])): ?>
            <a class="ilink" href="mailto:<?php echo $e($m['email']); ?>" title="<?php echo $e($m['email']); ?>"><i class="fa-solid fa-envelope"></i></a>
            <?php endif; ?>
            <?php if (!empty($m['phone'])): ?>
            <a class="ilink" href="tel:<?php echo $e($m['phone']); ?>" title="<?php echo $e($m['phone']); ?>"><i class="fa-solid fa-phone"></i></a>
            <?php endif; ?>
            <?php if (!empty($m['lb'])): ?>
            <a class="ilink" href="https://letterboxd.com/<?php echo $e($m['lb']); ?>/" target="_blank" rel="noopener" title="Letterboxd"><i class="fa-solid fa-clapperboard"></i></a>
            <?php endif; ?>
            <?php if (!empty($m['website'])): ?>
            <a class="ilink" href="<?php echo $e($m['website']); ?>" target="_blank" rel="noopener" title="Website"><i class="fa-solid fa-link"></i></a>
            <?php endif; ?>
            <?php if (!$is_me): ?>
            <button class="ilink ilink--save<?php echo $is_saved ? ' is-on' : ''; ?>"
                    data-save="<?php echo (int)$m['id']; ?>"
                    title="<?php echo $is_saved ? 'Saved to your list' : 'Save to your list'; ?>">
              <i class="fa-<?php echo $is_saved ? 'solid' : 'regular'; ?> fa-bookmark"></i>
            </button>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <p class="empty" id="dir-empty" style="display:none;">Nobody matches that.</p>
    </div>
  </main>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
