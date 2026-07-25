// ═══════════════════════════════════════════════════════════════════════════
//  v2 UI — behaviour shim.
//  script-jlm.js supplies all the real interaction (theatre, panels, feed,
//  composer) and is loaded first. This file only covers the two places where
//  the new layout and the legacy code disagree.
// ═══════════════════════════════════════════════════════════════════════════

$(function () {

  // ── Mobile navigation ────────────────────────────────────────────────────
  // The old chrome slid the nav in from the right by writing inline `right`
  // values onto .menu. The new nav slides from the left via a class, so the
  // legacy resize handler has to stop fighting it.
  $(window).off('resize');
  $('.menu').css({ left: '', right: '' });

  $(document).on('click', '.menu-btn', function (e) {
    e.stopPropagation();
    $('.menu').toggleClass('open');
  });

  // Tapping the content area closes an open nav.
  $(document).on('click', '.home-base', function () {
    $('.menu').removeClass('open');
  });

});
