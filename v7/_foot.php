<?php
// ═══════════════════════════════════════════════════════════════════════════
//  v7 — shell close, scrim, and the account panel
//
//  The account panel is universal: sign-in and join are utility, so they live
//  in the chrome on every page rather than taking space in the content.
//
//  Set before including:
//    $ctx_video   bool   the page loads video.js and the sync globals
//    $ctx_theatre array  from ctx_theatre(); required when $ctx_video
//    $ctx_overlay bool   emit the full-screen theatre overlay (default true).
//                        /th1/ and /th2/ mount their own player in the canvas,
//                        so they load video.js but suppress the overlay — two
//                        players would otherwise fight over the control ids.
// ═══════════════════════════════════════════════════════════════════════════

$ctx_video   = $ctx_video   ?? false;
$ctx_overlay = $ctx_overlay ?? true;
$signed    = ctx_me($conn) !== null;
?>
</div><!-- /.app -->

<div class="scrim" id="scrim"></div>

<?php
// Overlays belonging to the page itself — captured into $ctx_extra_sheets
// before this include so they land inside the scrim's stacking context.
if (!empty($ctx_extra_sheets)) echo $ctx_extra_sheets;
?>

<?php if (!$signed): ?>
<aside class="sheet sheet--side" id="sheet-account">
  <div class="sheet__head">
    <span class="sheet__title">Become a member</span>
    <button class="ibtn sheet__x" data-close title="Close"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="sheet__body">
    <?php
    $err = $_GET['error'] ?? null;
    $msg = ['100' => 'Retry your email or password.',
            '102' => 'Registration error.',
            '104' => 'That email is already registered.',
            '108' => 'That access code is not valid.',
            '109' => 'Too many failed attempts. Wait fifteen minutes and try again.',
            '110' => 'Passwords must be at least 8 characters.',
            '106' => 'Add your name and pick a role.'][$err] ?? null;
    if ($msg): ?><div class="alert"><?php echo ctx_e($msg); ?></div><?php endif; ?>

    <div id="join-step-1">
      <div class="alert" id="join-error" style="display:none;"></div>
      <form id="join-form">
        <input type="hidden" name="ajax" value="1" />
        <div class="field"><label class="field__label" for="j-email">Email</label>
          <input class="field__input" id="j-email" type="email" name="email" autocomplete="email" /></div>
        <div class="field"><label class="field__label" for="j-pw">Password</label>
          <input class="field__input" id="j-pw" type="password" name="pw" placeholder="8+ characters" autocomplete="new-password" /></div>
        <div class="field"><label class="field__label" for="j-pw2">Confirm password</label>
          <input class="field__input" id="j-pw2" type="password" name="pw2" autocomplete="new-password" /></div>
        <div class="field"><label class="field__label" for="j-code">Access code</label>
          <input class="field__input" id="j-code" type="text" name="code" placeholder="Ask around." /></div>
        <input class="btn btn--block" type="submit" value="Sign up" />
      </form>
    </div>

    <?php // Step 2 (name, role, the optional fields) used to live here too,
          // shown inline after a successful signup — a second copy of the
          // exact form _gate.php's "onboard" state already is, now that the
          // role picker is a checkbox hierarchy and not a one-line <select>.
          // initJoin() redirects to / on success instead, which lands there
          // on its own via ctx_state(). One form, not two. ?>

    <div class="field__label" style="margin:var(--s-6) 0 var(--s-3);">Already a member</div>
    <form action="/dashboard/signup.php?action=login" method="post">
      <div class="field"><label class="field__label" for="s-email">Email</label>
        <input class="field__input" id="s-email" type="email" name="email" /></div>
      <div class="field"><label class="field__label" for="s-pw">Password</label>
        <input class="field__input" id="s-pw" type="password" name="pw" /></div>
      <button class="btn btn--quiet btn--block" type="submit">Sign in</button>
    </form>
  </div>
</aside>
<?php endif; ?>

<?php if ($ctx_video && $ctx_overlay):
  $film    = $ctx_theatre['film']    ?? null;
  $show_ts = $ctx_theatre['show_ts'] ?? 0;
?>
<div class="theatre" id="theatre">
  <div class="theatre__bar">
    <span class="theatre__name"><?php echo $film ? ctx_e($film['title']) : 'Cinema, TX'; ?></span>
    <button class="theatre__close" id="theatre-close">Close</button>
  </div>
  <div class="theatre__stage" id="theatre-stage">
    <video id="theatre-player" class="video-js" oncontextmenu="return false;"
           preload="none" muted playsinline
           poster="<?php echo $film ? '/motw/' . ctx_e($film['poster']) . '.png' : ''; ?>"></video>
  </div>
  <div class="theatre__foot">
    <button class="ctrl" id="ctrl-mute" title="Mute"><i class="fa-solid fa-volume-high"></i></button>
    <input class="vol" id="ctrl-vol" type="range" min="0" max="1" step="0.05" value="1" />
    <button class="ctrl" id="ctrl-full" title="Fullscreen"><i class="fa-solid fa-expand"></i></button>
    <span class="theatre__meta"><?php echo $film ? ctx_e($film['director']) : ''; ?> &middot; Synced to showtime</span>
  </div>
</div>
<?php endif; ?>

<?php
// Page-specific scripts, emitted last. The dashboard uses this to bring in
// jQuery and dashboard.js, whose 700-odd lines of working upload / autosave /
// publish logic are deliberately untouched by the conversion.
if (!empty($ctx_scripts)) echo $ctx_scripts;
?>
</body>
</html>
