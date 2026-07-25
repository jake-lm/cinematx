// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — v3 behaviour
//
//  Written from scratch, no jQuery. Talks to the same PHP endpoints the
//  existing UI uses — /posts/quick.php, /dashboard/signup.php,
//  /dashboard/recent.php — so nothing on the server changes.
//
//  The theatre sync algorithm is carried over intact from theatre_1() in
//  script-jlm.js: park pre-show, seek to elapsed, muted autoplay, correct
//  drift every 3s, snap back on scrub. The [th1] console noise is dropped.
// ═══════════════════════════════════════════════════════════════════════════

(function () {
  'use strict';

  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  function post(url, data) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: new URLSearchParams(data).toString(),
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); });
  }

  var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; };

  // ══ Navigation ═══════════════════════════════════════════════════════════

  function initNav() {
    var nav    = $('.nav');
    var toggle = $('#nav-toggle');
    if (toggle && nav) {
      toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        nav.classList.toggle('is-open');
      });
    }

    // Recent-activity dropdown
    var btn  = $('#recent-btn');
    var drop = $('#recent-drop');
    if (!btn || !drop) return;

    var loaded = false;

    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      drop.classList.toggle('is-open');
      if (!drop.classList.contains('is-open') || loaded) return;

      fetch('/dashboard/recent.php?action=list', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          loaded = true;
          var items = (res && res.items) || [];
          if (!items.length) { drop.innerHTML = '<div class="drop__empty">Nothing yet</div>'; return; }

          // recent.php returns no href — build it from kind + id.
          drop.innerHTML = items.map(function (it) {
            var href = it.kind === 'event'
              ? '/events/?id=' + encodeURIComponent(it.id)
              : '/posts/index.php?id=' + encodeURIComponent(it.id);
            return '<a class="drop__row" href="' + href + '">' +
                     '<span class="drop__title">' + esc(it.title || 'Untitled') + '</span>' +
                     '<span class="drop__meta">' +
                       '<span>' + esc(it.subtitle || it.type || '') + '</span>' +
                       (it.status === 'draft' ? '<span style="color:var(--hot)">draft</span>' : '') +
                       '<span class="drop__date">' + esc(it.date || '') + '</span>' +
                     '</span>' +
                   '</a>';
          }).join('');
        })
        .catch(function () { drop.innerHTML = '<div class="drop__empty">Could not load</div>'; });
    });

    document.addEventListener('click', function (e) {
      if (!drop.contains(e.target) && e.target !== btn) drop.classList.remove('is-open');
    });
  }

  // ══ Marquee ══════════════════════════════════════════════════════════════
  // The track holds the listing twice so the -50% translate loops seamlessly.
  // Duration scales with content width to keep a constant reading speed.

  function initMarquee() {
    var track = $('#marquee-track');
    if (!track) return;
    var group = track.firstElementChild;
    if (!group) return;

    var w = group.getBoundingClientRect().width;
    if (w > 0) track.style.animationDuration = Math.max(18, Math.round(w / 52)) + 's';
  }

  // ══ The paper — expand the essay ═════════════════════════════════════════
  // max-height + scrollHeight so the reveal actually animates.

  function initPaper() {
    var btn  = $('#paper-more');
    var body = $('#paper-body');
    var fade = $('#paper-fade');
    if (!btn || !body) return;

    var collapsed = getComputedStyle(body).maxHeight;

    btn.addEventListener('click', function () {
      var open = btn.classList.toggle('is-open');
      if (open) {
        body.style.maxHeight = body.scrollHeight + 'px';
        if (fade) fade.style.opacity = '0';
        btn.textContent = 'Collapse ↑';
      } else {
        body.style.maxHeight = collapsed;
        if (fade) fade.style.opacity = '1';
        btn.textContent = 'Read in full →';
      }
    });
  }

  // ══ Composer ═════════════════════════════════════════════════════════════

  function initComposer() {
    var area  = $('#composer-area');
    var send  = $('#composer-send');
    var count = $('#composer-count');
    var feed  = $('#feed');
    if (!area || !send) return;

    var LIMIT = 600;

    area.addEventListener('input', function () {
      if (count) count.textContent = area.value.length + ' / ' + LIMIT;
      send.disabled = !area.value.trim();
    });

    // Direct binding — delegation here silently dies if anything above it
    // throws during setup.
    send.addEventListener('click', function () {
      var content = area.value.trim();
      if (!content) return;

      send.disabled = true;
      var label = send.textContent;
      send.textContent = '...';

      post('/posts/quick.php', { type: 'post', content: content, title: '', subtitle: '' })
        .then(function (res) {
          if (!res || !res.success || !res.post) throw new Error('rejected');
          var p = res.post;
          area.value = '';
          if (count) count.textContent = '0 / ' + LIMIT;

          if (feed) {
            var el = document.createElement('div');
            el.className = 'post';
            el.setAttribute('data-post-id', p.id);
            el.innerHTML =
              '<button class="post__del" data-post-id="' + p.id + '" title="Delete">&times;</button>' +
              '<span class="post__av"><i class="fa-solid fa-user"></i></span>' +
              '<div class="post__main">' +
                '<div class="post__body">' + esc(p.content).replace(/\n/g, '<br>') + '</div>' +
                '<div class="post__meta"><span>' + esc(p.author_name || 'You') + '</span><span>just now</span></div>' +
              '</div>';
            feed.insertBefore(el, feed.firstChild);
          }
        })
        .catch(function () { area.placeholder = 'Could not post — try again.'; })
        .then(function () { send.textContent = label; send.disabled = !area.value.trim(); });
    });
  }

  // ══ Feed — two-step delete ═══════════════════════════════════════════════

  function initFeed() {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.post__del') : null;
      if (!btn) return;
      e.stopPropagation();

      var card = btn.closest('.post');
      var pid  = btn.getAttribute('data-post-id');

      if (!btn.classList.contains('is-confirming')) {
        btn.textContent = 'delete?';
        btn.classList.add('is-confirming');
        setTimeout(function () {
          if (btn.classList.contains('is-confirming')) {
            btn.innerHTML = '&times;';
            btn.classList.remove('is-confirming');
          }
        }, 2500);
        return;
      }

      btn.textContent = '...';
      btn.disabled = true;

      post('/posts/quick.php', { type: 'delete', post_id: pid })
        .then(function (res) {
          if (res && res.success) {
            card.style.transition = 'opacity 180ms linear';
            card.style.opacity = '0';
            setTimeout(function () { card.remove(); }, 190);
          } else { throw new Error('rejected'); }
        })
        .catch(function () {
          btn.innerHTML = '&times;';
          btn.disabled = false;
          btn.classList.remove('is-confirming');
        });
    });
  }

  // ══ Join — two-step signup ═══════════════════════════════════════════════

  function initSignup() {
    var form = $('#join-form');
    if (!form) return;

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = form.querySelector('[type=submit]');
      var err = $('#join-error');
      var label = btn.value;
      btn.value = '...';
      btn.disabled = true;

      post('/dashboard/signup.php?action=signup', new FormData(form))
        .then(function (res) {
          if (res && res.success) {
            var uid = $('#join-uid');
            if (uid) uid.value = res.uid;
            var s1 = $('#join-step-1'), s2 = $('#join-step-2');
            if (s1) s1.style.display = 'none';
            if (s2) s2.style.display = 'block';
            return;
          }
          var msgs = {
            '102': 'Fill every field and make sure the passwords match.',
            '104': 'That email is already registered.',
            '108': 'Invalid access code.'
          };
          if (err) {
            err.textContent = msgs[res && res.error] || 'Something went wrong.';
            err.style.display = 'block';
          }
          btn.value = label;
          btn.disabled = false;
        })
        .catch(function () {
          if (err) { err.textContent = 'Server error. Try again.'; err.style.display = 'block'; }
          btn.value = label;
          btn.disabled = false;
        });
    });
  }

  // ══ Theatre ══════════════════════════════════════════════════════════════

  function initTheatre() {
    var open  = $('#theatre-open');
    var close = $('#theatre-close');
    var shell = $('#theatre');
    if (!shell) return;

    var started = false;

    function openTheatre() {
      shell.classList.add('is-open');
      document.body.style.overflow = 'hidden';
      if (!started) { started = true; startPlayback(); }
    }

    function closeTheatre() {
      shell.classList.remove('is-open');
      document.body.style.overflow = '';
    }

    if (open)  open.addEventListener('click', openTheatre);
    if (close) close.addEventListener('click', closeTheatre);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && shell.classList.contains('is-open')) closeTheatre();
    });

    // ── Sync ───────────────────────────────────────────────────────────────
    function startPlayback() {
      var showtime = window.CTX_SHOWTIME || 0;
      var dur      = window.CTX_DUR || 0;
      var filename = window.CTX_FILE || '';

      if (!showtime || !filename || typeof videojs === 'undefined') return;

      var player = videojs('theatre-player');

      player.ready(function () {
        var diff = Math.floor(Date.now() / 1000 - showtime);
        if (diff < 0 || diff >= dur) return;   // pre-show or already over

        // stream.php serves HTTP range requests, which the built-in PHP
        // server does not — required for seeking.
        player.src({ type: 'video/mp4', src: '/motw/stream.php?f=' + encodeURIComponent(filename) });

        player.one('loadedmetadata', function () {
          var d2 = Math.floor(Date.now() / 1000 - showtime);
          player.muted(true);
          player.currentTime(d2);

          player.one('seeked', function () {
            player.play().then(function () {
              setTimeout(function () {
                if (player.muted()) showUnmute(player);
              }, 500);
            }).catch(function () { player.muted(false); });
          });
        });
      });

      // Reload when the showtime arrives.
      if (Date.now() / 1000 < showtime) {
        var wait = setInterval(function () {
          if (Date.now() / 1000 >= showtime) { clearInterval(wait); location.reload(); }
        }, 5000);
      }

      // Correct drift beyond 2s.
      setInterval(function () {
        var d = Math.floor(Date.now() / 1000 - showtime);
        if (d < 0 || d >= dur) return;
        if (Math.abs(Math.floor(player.currentTime()) - d) > 2) player.currentTime(d);
      }, 3000);

      // Snap back on any scrub attempt — the showtime is the showtime.
      var el = $('#theatre-player');
      if (el) {
        ['mouseup', 'touchend'].forEach(function (evt) {
          el.addEventListener(evt, function () {
            var d = Math.floor(Date.now() / 1000 - showtime);
            if (d >= 0 && d < dur) player.currentTime(d);
          });
        });
      }

      initPlayerControls(player);
    }

    function showUnmute(player) {
      var stage = $('#theatre-stage');
      if (!stage) return;
      var b = document.createElement('button');
      b.className = 'btn';
      b.textContent = 'Click to unmute';
      b.style.cssText = 'position:absolute;bottom:20px;right:20px;z-index:20;';
      stage.appendChild(b);
      b.addEventListener('click', function () { player.muted(false); b.remove(); });
    }

    function initPlayerControls(player) {
      var mute = $('#ctrl-mute');
      var vol  = $('#ctrl-vol');
      var full = $('#ctrl-full');

      if (mute) mute.addEventListener('click', function () {
        var m = !player.muted();
        player.muted(m);
        mute.innerHTML = '<i class="fa-solid fa-volume-' + (m ? 'xmark' : 'high') + '"></i>';
      });

      if (vol) vol.addEventListener('input', function () {
        player.volume(parseFloat(vol.value));
        if (parseFloat(vol.value) > 0 && player.muted()) {
          player.muted(false);
          if (mute) mute.innerHTML = '<i class="fa-solid fa-volume-high"></i>';
        }
      });

      if (full) full.addEventListener('click', function () {
        if (player.isFullscreen()) player.exitFullscreen(); else player.requestFullscreen();
      });
    }
  }

  // ══ Boot ═════════════════════════════════════════════════════════════════

  function boot() {
    initNav();
    initMarquee();
    initPaper();
    initComposer();
    initFeed();
    initSignup();
    initTheatre();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();

})();
