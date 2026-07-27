<?php
// ═══════════════════════════════════════════════════════════════════════════
//  v7 — onboarding gates
//
//  The two states between signing up and being a member. Every reference
//  build before this one branched only on signed-in/signed-out and left new
//  accounts with nowhere to land.
//
//    onboard  signed in, but no name or role yet   → firstcontact
//    gated    named, but active = 0                → activateacct
//
//  Deliberately a single calm card with one thing to do. Comfort is
//  subtractive: this is not the moment to show someone a front page.
//
//  Set before including:  $ctx_gate  'onboard' | 'gated'
// ═══════════════════════════════════════════════════════════════════════════

$err = $_GET['error'] ?? null;
$me  = ctx_me($conn);
?>
<main class="canvas">
  <div class="gate">
    <div class="gate__card">

      <?php if ($ctx_gate === 'onboard'): ?>

        <span class="gate__step">Step 2 of 2</span>
        <h1 class="gate__title">Tell us who you are</h1>
        <p class="gate__lede">
          A name and a role, and you're in. Everything else is optional — you can
          fill it in later from your profile.
        </p>

        <?php if ($err === '106'): ?>
        <div class="alert">A name and a role are both required.</div>
        <?php endif; ?>

        <form action="/dashboard/signup.php?action=firstcontact" method="post">
          <div class="field">
            <label class="field__label" for="g-name">Name</label>
            <input class="field__input" id="g-name" type="text" name="uname"
                   value="<?php echo ctx_e($me['name'] ?? ''); ?>" required />
          </div>

          <div class="field">
            <label class="field__label" for="g-role">Role</label>
            <select class="field__input" id="g-role" name="dept" required>
              <option value="0">Select one</option>
              <?php foreach ($roles as $r): ?>
              <option value="<?php echo ctx_e($r); ?>"<?php echo ($me['dept'] ?? '') === $r ? ' selected' : ''; ?>><?php echo ctx_e($r); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="gate__optional">
            <div class="gate__optional-head">Optional</div>
            <div class="gate__row">
              <div class="field">
                <label class="field__label" for="g-lb">Letterboxd</label>
                <input class="field__input" id="g-lb" type="text" name="lb"
                       value="<?php echo ctx_e($me['lb'] ?? ''); ?>" placeholder="username" />
              </div>
              <div class="field">
                <label class="field__label" for="g-site">Website</label>
                <input class="field__input" id="g-site" type="text" name="website"
                       value="<?php echo ctx_e($me['website'] ?? ''); ?>" />
              </div>
            </div>
            <div class="field">
              <label class="field__label" for="g-phone">Phone</label>
              <input class="field__input" id="g-phone" type="tel" name="phone"
                     value="<?php echo ctx_e($me['phone'] ?? ''); ?>" />
            </div>
          </div>

          <button class="btn btn--block" type="submit">Continue</button>
        </form>

      <?php else: ?>

        <span class="gate__step">Almost there</span>
        <h1 class="gate__title">You'll need a code</h1>
        <p class="gate__lede">
          Cinema, TX is small on purpose. Membership runs on access codes —
          ask a member, or look around your local coffee shop.
        </p>

        <?php if ($err === '108'): ?>
        <div class="alert">That code wasn't recognised.</div>
        <?php endif; ?>

        <form action="/dashboard/signup.php?action=activateacct" method="post">
          <div class="field">
            <label class="field__label" for="g-code">Access code</label>
            <input class="field__input" id="g-code" type="text" name="code"
                   autocomplete="off" autofocus required />
          </div>
          <button class="btn btn--block" type="submit">Join</button>
        </form>

        <p class="gate__aside">
          Signed in as <?php echo ctx_e($me['email'] ?? ''); ?>.
          Not you? <a href="/dashboard/signup.php?action=logout">Sign out</a>.
        </p>

      <?php endif; ?>

    </div>
  </div>
</main>
