<?php
// ═══════════════════════════════════════════════════════════════════════════
//  Admin rail links
//
//  Included by v7/_chrome.php, inside the same <nav class="rail__nav"> the
//  public site uses, when a page sets $ctx_admin_nav = true. Shares
//  _chrome.php's local scope, so $on() (the active-link closure) is already
//  defined here — this file is link markup only, nothing else.
//
//  No "back to site" link needed: the rail's wordmark at the top (outside
//  this nav) already goes to CTX_HOME regardless of which nav variant renders.
// ═══════════════════════════════════════════════════════════════════════════
?>
<a class="rail__link<?php echo $on('dashboard'); ?>" href="/_admin/">
  <span class="ico"><i class="fa-solid fa-gauge"></i></span><span class="txt">Dashboard</span></a>
<a class="rail__link<?php echo $on('films'); ?>" href="/_admin/films.php">
  <span class="ico"><i class="fa-solid fa-film"></i></span><span class="txt">Films</span></a>
<a class="rail__link<?php echo $on('showtimes'); ?>" href="/_admin/showtimes.php">
  <span class="ico"><i class="fa-solid fa-calendar-days"></i></span><span class="txt">Showtimes</span></a>
<a class="rail__link<?php echo $on('instagram'); ?>" href="/_admin/instagram.php">
  <span class="ico"><i class="fa-brands fa-instagram"></i></span><span class="txt">Instagram</span></a>
<a class="rail__link<?php echo $on('events'); ?>" href="/_admin/events.php">
  <span class="ico"><i class="fa-solid fa-map-location-dot"></i></span><span class="txt">Screenings</span></a>
<a class="rail__link<?php echo $on('visitors'); ?>" href="/_admin/visitors.php">
  <span class="ico"><i class="fa-solid fa-chart-simple"></i></span><span class="txt">Visitors</span></a>
