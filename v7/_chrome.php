<?php
// ═══════════════════════════════════════════════════════════════════════════
//  v7 — rail and bar
//
//  Quiet chrome. It is navigation and utility, never content — which is the
//  distinction v3 and v5 lost when they let everything compete with the thing
//  you actually came to read.
//
//  Set before including:
//    $ctx_active  string  one of: index list about dashboard directory profile
// ═══════════════════════════════════════════════════════════════════════════

$ctx_active = $ctx_active ?? '';
$me         = ctx_me($conn);
$signed     = $me !== null;
// Member-only links are gated on full membership, not merely on being signed
// in — someone mid-onboarding or awaiting an access code cannot use them.
$full       = ctx_state($conn) === 'member';
$dir        = ctx_members($conn, 7);
$on         = fn($k) => $ctx_active === $k ? ' is-on' : '';
?>
<aside class="rail">
  <div class="rail__top"><a class="rail__mark" href="<?php echo CTX_HOME; ?>">Cinema<span class="c">,</span> TX</a></div>

  <nav class="rail__nav">
    <a class="rail__link<?php echo $on('index'); ?>" href="<?php echo CTX_HOME; ?>">
      <span class="ico"><i class="fa-solid fa-film"></i></span><span class="txt">Tonight</span></a>
    <a class="rail__link<?php echo $on('list'); ?>" href="/list">
      <span class="ico"><i class="fa-solid fa-calendar-days"></i></span><span class="txt">The List</span></a>
    <a class="rail__link<?php echo $on('theatre'); ?>" href="/th1">
      <span class="ico"><i class="fa-solid fa-clapperboard"></i></span><span class="txt">Theatre</span></a>
    <a class="rail__link<?php echo $on('about'); ?>" href="/about">
      <span class="ico"><i class="fa-solid fa-circle-info"></i></span><span class="txt">About</span></a>

    <?php if ($full): ?>
    <div class="rail__group">Yours</div>
    <a class="rail__link<?php echo $on('dashboard'); ?>" href="/dashboard">
      <span class="ico"><i class="fa-solid fa-gauge"></i></span><span class="txt">Dashboard</span></a>
    <a class="rail__link<?php echo $on('directory'); ?>" href="/directory">
      <span class="ico"><i class="fa-solid fa-users"></i></span><span class="txt">Directory</span></a>
    <a class="rail__link<?php echo $on('profile'); ?>" href="/users/profile.php?id=<?php echo (int)$me['id']; ?>">
      <span class="ico"><i class="fa-solid fa-user"></i></span><span class="txt">Profile</span></a>
    <?php endif; ?>
  </nav>

  <div class="rail__foot">
    <div class="faces">
      <?php foreach ($dir['members'] as $m): ?>
      <a class="face" href="/users/profile.php?id=<?php echo (int)$m['id']; ?>" title="<?php echo ctx_e($m['name']); ?>">
        <?php if (!empty($m['photo'])): ?><img src="/uploads/profiles/<?php echo ctx_e($m['photo']); ?>" alt="" />
        <?php else: ?><?php echo ctx_e(mb_substr($m['name'], 0, 1)); ?><?php endif; ?>
      </a>
      <?php endforeach; ?>
      <?php $rest = $dir['count'] - count($dir['members']); if ($rest > 0): ?>
      <a class="face" href="/directory" title="All members">+<?php echo $rest; ?></a>
      <?php endif; ?>
    </div>
  </div>
</aside>

<header class="bar">
  <button class="ibtn rail__toggle" id="rail-toggle" title="Collapse"><i class="fa-solid fa-bars"></i></button>
  <a class="bar__mark" href="<?php echo CTX_HOME; ?>">Cinema<span class="c">,</span> TX</a>
  <span class="welcome" id="welcome">Welcome to the Cinema</span>

  <div class="bar__end">
    <button class="ibtn" id="theme" title="Dark"><i class="fa-solid fa-moon"></i></button>
    <?php if ($signed): ?>
      <?php if ($full): ?><a class="ibtn" href="/dashboard" title="Write"><i class="fa-solid fa-pen"></i></a><?php endif; ?>
      <form action="/dashboard/signup.php?action=logout" method="post" style="display:flex;">
        <button class="ibtn" type="submit" title="Sign out"><i class="fa-solid fa-arrow-right-from-bracket"></i></button>
      </form>
    <?php else: ?>
      <button class="btn" data-open="account">Sign in</button>
    <?php endif; ?>
  </div>
</header>
