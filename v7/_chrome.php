<?php
// ═══════════════════════════════════════════════════════════════════════════
//  v7 — rail and bar
//
//  Quiet chrome. It is navigation and utility, never content — which is the
//  distinction v3 and v5 lost when they let everything compete with the thing
//  you actually came to read.
//
//  Set before including:
//    $ctx_active     string  one of: index list about dashboard directory profile
//                            (or, when $ctx_admin_nav is set: dashboard films
//                            showtimes instagram)
//    $ctx_admin_nav  bool    swaps the rail's link list for _admin/_nav.php —
//                            the wrapper, toggle and theme behaviour are the
//                            same rail everywhere, only the links differ
// ═══════════════════════════════════════════════════════════════════════════

$ctx_active = $ctx_active ?? '';
$me         = ctx_me($conn);
$signed     = $me !== null;
// Member-only links are gated on full membership, not merely on being signed
// in — someone mid-onboarding or awaiting an access code cannot use them.
$full       = ctx_state($conn) === 'member';
$on         = fn($k) => $ctx_active === $k ? ' is-on' : '';
?>
<aside class="rail">
  <div class="rail__top">
    <?php // Two marks, one shown at a time: the wordmark when there is room for
          // it, the icon when the rail is narrow. alt carries the name so the
          // link is never anonymous once the words are gone. ?>
    <a class="rail__mark" href="<?php echo CTX_HOME; ?>">
      <img class="rail__icon" src="/img/iconimg.png" alt="Cinema, TX" width="100" height="100" />
      <span class="rail__word">Cinema<span class="c">,</span> TX<sup class="mark__beta">Beta</sup></span>
    </a>
  </div>

  <nav class="rail__nav">
    <?php if (!empty($ctx_admin_nav)): ?>
      <?php require dirname(__DIR__) . '/_admin/_nav.php'; ?>
    <?php else: ?>
    <a class="rail__link<?php echo $on('index'); ?>" href="<?php echo CTX_HOME; ?>">
      <span class="ico"><i class="fa-solid fa-film"></i></span><span class="txt">Tonight</span></a>
    <a class="rail__link<?php echo $on('list'); ?>" href="/list">
      <span class="ico"><i class="fa-solid fa-calendar-days"></i></span><span class="txt">The List</span></a>
    <a class="rail__link<?php echo $on('theatre'); ?>" href="/th1">
      <span class="ico"><i class="fa-solid fa-clapperboard"></i></span><span class="txt">Theatre</span></a>
    <?php // Guests still have no "You" menu to fall back to, so About only
          // joins the mobile collapse for members who actually have one. ?>
    <a class="rail__link<?php echo $full ? ' rail__link--full' : ''; ?><?php echo $on('about'); ?>" href="/about">
      <span class="ico"><i class="fa-solid fa-circle-info"></i></span><span class="txt">About</span></a>

    <?php if ($full): ?>
    <div class="rail__group">Yours</div>
    <a class="rail__link rail__link--full<?php echo $on('dashboard'); ?>" href="/dashboard">
      <span class="ico"><i class="fa-solid fa-gauge"></i></span><span class="txt">Dashboard</span></a>
    <a class="rail__link rail__link--full<?php echo $on('directory'); ?>" href="/directory">
      <span class="ico"><i class="fa-solid fa-users"></i></span><span class="txt">Directory</span></a>
    <a class="rail__link rail__link--full<?php echo $on('profile'); ?>" href="/users/profile.php?id=<?php echo (int)$me['id']; ?>">
      <span class="ico"><i class="fa-solid fa-user"></i></span><span class="txt">Profile</span></a>

    <?php // The mobile bottom bar has no room for four member-only icons
          // beside the four public ones — collapses to this one trigger,
          // hidden on the desktop rail where the full set already fits.
          // A small popover anchored above the button, not a sheet: this is
          // four links, not a screen's worth of content, so it shouldn't
          // behave like one — no scrim, no covering the page. initYouMenu()
          // moves #you-menu to <body> and positions it itself, the same way
          // the custom-select menu escapes the rail's own clipping. ?>
    <button class="rail__link rail__link--compact" id="you-trigger" type="button"
            aria-haspopup="true" aria-expanded="false">
      <span class="ico"><i class="fa-solid fa-ellipsis"></i></span><span class="txt">You</span></button>
    <div class="you-menu" id="you-menu">
      <a class="you-menu__link<?php echo $on('profile'); ?>" href="/users/profile.php?id=<?php echo (int)$me['id']; ?>">
        <i class="fa-solid fa-user"></i><span>Profile</span></a>
      <a class="you-menu__link<?php echo $on('dashboard'); ?>" href="/dashboard">
        <i class="fa-solid fa-gauge"></i><span>Dashboard</span></a>
      <a class="you-menu__link<?php echo $on('directory'); ?>" href="/directory">
        <i class="fa-solid fa-users"></i><span>Directory</span></a>
      <a class="you-menu__link" href="/dashboard#account">
        <i class="fa-solid fa-gear"></i><span>Settings</span></a>
      <a class="you-menu__link<?php echo $on('about'); ?>" href="/about">
        <i class="fa-solid fa-circle-info"></i><span>About</span></a>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </nav>
</aside>

<header class="bar">
  <button class="ibtn rail__toggle" id="rail-toggle" title="Collapse"><i class="fa-solid fa-bars"></i></button>
  <a class="bar__mark" href="<?php echo CTX_HOME; ?>">Cinema<span class="c">,</span> TX<sup class="mark__beta">Beta</sup></a>
  <?php // First name only — "Welcome to the Cinema, Jake Martinez" reads like
        // a form letter. Absent when signed out, and the rotator omits the
        // comma entirely rather than trailing one.
  $first = $me && !empty($me['name']) ? preg_split('/\s+/', trim($me['name']))[0] : ''; ?>
  <span class="welcome" id="welcome"<?php echo $first ? ' data-name="' . ctx_e($first) . '"' : ''; ?>>Welcome to the Cinema<?php echo $first ? ', ' . ctx_e($first) : ''; ?></span>

  <div class="bar__end">
    <?php
    // Marquee theme switcher — parked 2026-07-30. Built and demoed (palettes
    // in v7.scss, initThemeSwitcher() in v7.js, the family/mode read in
    // _head.php's FOUC script — none of that needs to change), but held back
    // rather than shipped. Flip this to `!empty($ctx_admin_nav)` to restore
    // the swatch popover below on public pages.
    $ctx_theme_switcher_enabled = false;
    if (!$ctx_theme_switcher_enabled || !empty($ctx_admin_nav)): ?>
    <button class="ibtn" id="theme" title="Dark"><i class="fa-solid fa-moon"></i></button>
    <?php else: ?>
    <?php // Paper/Marquee family plus light/dark mode — admin keeps the plain
          // button above untouched; initTheme() and initThemeSwitcher() in
          // v7.js each no-op when their own markup isn't on the page, so the
          // two never run against each other. ?>
    <button class="ibtn" id="theme-trigger" title="Theme" aria-haspopup="true" aria-expanded="false"><i class="fa-solid fa-moon"></i></button>
    <div class="theme-menu" id="theme-menu">
      <div class="theme-swatches">
        <button type="button" class="theme-swatch" data-family="paper">
          <svg class="theme-swatch__art" viewBox="0 0 72 54" xmlns="http://www.w3.org/2000/svg">
            <rect width="72" height="54" fill="#F4F1EB"/>
            <rect width="16" height="54" fill="#FBF9F5"/>
            <rect x="4" y="8" width="8" height="8" rx="2" fill="#922E32"/>
            <rect x="24" y="8" width="30" height="5" fill="#1A1813"/>
            <rect x="24" y="20" width="40" height="14" fill="#E5E0D5"/>
            <rect x="24" y="38" width="24" height="4" fill="#6E6960"/>
          </svg>
          <span>Paper</span>
        </button>
        <button type="button" class="theme-swatch" data-family="marquee">
          <svg class="theme-swatch__art" viewBox="0 0 72 54" xmlns="http://www.w3.org/2000/svg">
            <rect width="72" height="54" fill="#14120F"/>
            <rect width="16" height="54" fill="#1E1B17"/>
            <rect x="4" y="8" width="8" height="8" rx="2" fill="#922E32"/>
            <rect x="24" y="8" width="30" height="5" fill="#F2C14E"/>
            <rect x="24" y="20" width="40" height="14" fill="#2A2620"/>
            <rect x="24" y="38" width="24" height="4" fill="#B5AFA0"/>
          </svg>
          <span>Marquee</span>
        </button>
      </div>
      <button type="button" class="theme-mode" id="theme-mode"><i class="fa-solid fa-moon"></i><span>Dark</span></button>
    </div>
    <?php endif; ?>
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
