<?php
// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — About
//  Copy carried over verbatim; only the setting changes.
// ═══════════════════════════════════════════════════════════════════════════
require dirname(__DIR__) . '/v7/_lib.php';

$ctx_title  = 'About — Cinema, TX';
$ctx_active = 'about';
$ctx_scroll = true;
$ctx_video  = false;

require dirname(__DIR__) . '/v7/_head.php';
require dirname(__DIR__) . '/v7/_chrome.php';
?>

  <main class="canvas">
    <article class="reading">

      <div class="reading__kicker"><span>Austin, Texas</span></div>

      <h1 class="reading__title">What the f#ck is this sh*t?</h1>

      <div class="reading__body prose">
        <p>We are a small directory of film culture in Austin, Texas.</p>
        <p><strong>Registration is limited.</strong><br />Most data is subject to review.<sup>*</sup></p>
        <p><a class="reading__link" href="mailto:contact.cinematx@gmail.com">contact.cinematx@gmail.com</a></p>
      </div>

      <div class="reading__fineprint">
        <sup>*</sup> Very few cookies are used on this site. Passwords are one-way encrypted.
        No unvolunteered data is collected. We are committed to privacy and transparency —
        email for more info, though there is none.
      </div>

    </article>
  </main>

<?php require dirname(__DIR__) . '/v7/_foot.php'; ?>
