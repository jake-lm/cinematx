// ═══════════════════════════════════════════════════════════════════════════
//  Admin — upload progress
//
//  Ported from the jQuery handler in script.js so the admin panel no longer
//  pulls jQuery, script.js and script-jlm.js for one progress bar. The rest of
//  script.js is homepage banner code that has nothing to do here.
//
//  Films are large. A form post with no feedback looks identical to a hung
//  browser, so this is worth the XHR.
// ═══════════════════════════════════════════════════════════════════════════

(function () {
  'use strict';

  var form = document.getElementById('upload-form');
  if (!form) return;

  var bar    = document.getElementById('upload-progress');
  var note   = document.getElementById('upload-note');
  var button = document.getElementById('upload-button');

  form.addEventListener('submit', function (e) {
    e.preventDefault();

    // FormData over the form element carries the CSRF field with it.
    var xhr   = new XMLHttpRequest();
    var start = null;

    if (button) { button.disabled = true; button.value = 'Uploading…'; }
    if (bar) bar.hidden = false;

    xhr.upload.onprogress = function (ev) {
      if (!ev.lengthComputable) { if (bar) bar.removeAttribute('value'); return; }

      var pct = (ev.loaded / ev.total) * 100;
      if (bar) bar.value = pct;

      if (start === null) { start = Date.now(); return; }
      if (pct <= 0) return;

      var elapsed = (Date.now() - start) / 1000;
      var left    = (elapsed * (100 - pct)) / pct;
      if (note) {
        note.textContent = Math.floor(left / 60) + 'm ' + Math.floor(left % 60) + 's remaining';
      }
    };

    xhr.onload = function () {
      if (xhr.status === 200) { window.location = '/_admin'; return; }
      fail(xhr.status === 400 ? 'Upload rejected — check both files are attached.' : 'Upload failed.');
    };
    xhr.onerror = function () { fail('Upload failed — connection lost.'); };

    function fail(msg) {
      if (note) note.textContent = msg;
      if (bar) bar.hidden = true;
      if (button) { button.disabled = false; button.value = 'Upload'; }
    }

    xhr.open('POST', '/_admin/upload.php', true);
    xhr.send(new FormData(form));
  });
})();
