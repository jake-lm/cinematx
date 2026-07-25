// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — v4 behaviour
//  Vanilla. Talks to the unchanged PHP endpoints: /posts/quick.php,
//  /dashboard/signup.php, /dashboard/recent.php.
//  The theatre sync algorithm is carried over from theatre_1().
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

  // ══ Theme ════════════════════════════════════════════════════════════════
  // Follows the system unless the reader has chosen. The choice persists.
  // The initial application happens inline in <head> to avoid a flash.

  var THEME_KEY = 'ctx-theme';

  // Must agree with the stylesheet, which defaults to paper regardless of the
  // system setting. Consulting prefers-color-scheme here would desync them.
  function effectiveTheme() {
    return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
  }

  function initTheme() {
    var btn = $('#theme-btn');
    if (!btn) return;

    function paint() {
      var dark = effectiveTheme() === 'dark';
      btn.innerHTML = '<i class="fa-solid fa-' + (dark ? 'sun' : 'moon') + '"></i>';
      btn.setAttribute('title', dark ? 'Light mode' : 'Dark mode');
    }

    paint();

    btn.addEventListener('click', function () {
      var next = effectiveTheme() === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      try { localStorage.setItem(THEME_KEY, next); } catch (e) {}
      paint();
    });
  }

  // ══ Bar: nav toggle + account panel ══════════════════════════════════════

  function initBar() {
    var bar = $('#bar');
    var tog = $('#bar-toggle');
    if (tog && bar) {
      tog.addEventListener('click', function (e) { e.stopPropagation(); bar.classList.toggle('is-open'); });
    }

    var panel = $('#panel');
    var scrim = $('#scrim');
    if (!panel || !scrim) return;

    function open()  { panel.classList.add('is-on');  scrim.classList.add('is-on');  document.body.style.overflow = 'hidden'; }
    function close() { panel.classList.remove('is-on'); scrim.classList.remove('is-on'); document.body.style.overflow = ''; }

    $$('[data-panel-open]').forEach(function (b) { b.addEventListener('click', open); });
    $$('[data-panel-close]').forEach(function (b) { b.addEventListener('click', close); });
    scrim.addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && panel.classList.contains('is-on')) close();
    });

    // Deep link — /v4/#join opens the panel straight away.
    if (location.hash === '#join') open();
  }

  // ══ Lead essay — expand in place ═════════════════════════════════════════

  function initLead() {
    var btn  = $('#lead-more');
    var body = $('#lead-body');
    var fade = $('#lead-fade');
    if (!btn || !body) return;

    var collapsed = getComputedStyle(body).maxHeight;

    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var open = btn.classList.toggle('is-open');
      if (open) {
        body.style.maxHeight = body.scrollHeight + 'px';
        if (fade) fade.style.opacity = '0';
        btn.textContent = 'Collapse';
      } else {
        body.style.maxHeight = collapsed;
        if (fade) fade.style.opacity = '1';
        btn.textContent = 'Read in full';
      }
    });
  }

  // ══ Composer ═════════════════════════════════════════════════════════════

  function initComposer() {
    var area  = $('#composer-area');
    var send  = $('#composer-send');
    var count = $('#composer-count');
    var feed  = $('#statuses');
    if (!area || !send) return;

    var LIMIT = 600;

    area.addEventListener('input', function () {
      if (count) count.textContent = area.value.length + '/' + LIMIT;
      send.disabled = !area.value.trim();
    });

    send.addEventListener('click', function () {
      var content = area.value.trim();
      if (!content) return;

      send.disabled = true;
      var label = send.textContent;
      send.textContent = '…';

      post('/posts/quick.php', { type: 'post', content: content, title: '', subtitle: '' })
        .then(function (res) {
          if (!res || !res.success || !res.post) throw new Error('rejected');
          var p = res.post;
          area.value = '';
          if (count) count.textContent = '0/' + LIMIT;

          if (feed) {
            var el = document.createElement('div');
            el.className = 'status';
            el.setAttribute('data-post-id', p.id);
            el.innerHTML =
              '<button class="status__del" data-post-id="' + p.id + '" title="Delete">&times;</button>' +
              '<span class="status__av"><i class="fa-solid fa-user"></i></span>' +
              '<div class="status__main">' +
                '<div class="status__text">' + esc(p.content).replace(/\n/g, '<br>') + '</div>' +
                '<div class="status__meta"><span>' + esc(p.author_name || 'You') + '</span><span>just now</span></div>' +
              '</div>';
            feed.insertBefore(el, feed.firstChild);
          }
        })
        .catch(function () { area.placeholder = 'Could not post — try again.'; })
        .then(function () { send.textContent = label; send.disabled = !area.value.trim(); });
    });
  }

  // ══ Statuses — two-step delete ═══════════════════════════════════════════

  function initStatuses() {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.status__del') : null;
      if (!btn) return;
      e.stopPropagation();

      var card = btn.closest('.status');
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

      btn.textContent = '…';
      btn.disabled = true;

      post('/posts/quick.php', { type: 'delete', post_id: pid })
        .then(function (res) {
          if (!res || !res.success) throw new Error('rejected');
          card.style.transition = 'opacity 180ms linear';
          card.style.opacity = '0';
          setTimeout(function () { card.remove(); }, 190);
        })
        .catch(function () {
          btn.innerHTML = '&times;';
          btn.disabled = false;
          btn.classList.remove('is-confirming');
        });
    });
  }

  // ══ Join — two-step signup, inside the account panel ═════════════════════

  function initJoin() {
    var form = $('#join-form');
    if (!form) return;

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = form.querySelector('[type=submit]');
      var err = $('#join-error');
      var label = btn.value;
      btn.value = '…';
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
          if (err) { err.textContent = msgs[res && res.error] || 'Something went wrong.'; err.style.display = 'block'; }
          btn.value = label; btn.disabled = false;
        })
        .catch(function () {
          if (err) { err.textContent = 'Server error. Try again.'; err.style.display = 'block'; }
          btn.value = label; btn.disabled = false;
        });
    });
  }

  // ══ Theatre ══════════════════════════════════════════════════════════════

  function initTheatre() {
    var shell = $('#theatre');
    if (!shell) return;

    var started = false;

    function open() {
      shell.classList.add('is-on');
      document.body.style.overflow = 'hidden';
      if (!started) { started = true; play(); }
    }
    function close() {
      shell.classList.remove('is-on');
      document.body.style.overflow = '';
    }

    var trigger = $('#cinema');
    if (trigger) trigger.addEventListener('click', open);
    var closeBtn = $('#theatre-close');
    if (closeBtn) closeBtn.addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && shell.classList.contains('is-on')) close();
    });

    function play() {
      var showtime = window.CTX_SHOWTIME || 0;
      var dur      = window.CTX_DUR || 0;
      var filename = window.CTX_FILE || '';
      if (!showtime || !filename || typeof videojs === 'undefined') return;

      var player = videojs('theatre-player');

      player.ready(function () {
        var diff = Math.floor(Date.now() / 1000 - showtime);
        if (diff < 0 || diff >= dur) return;   // pre-show, or already over

        // stream.php handles HTTP range requests — required for seeking.
        player.src({ type: 'video/mp4', src: '/motw/stream.php?f=' + encodeURIComponent(filename) });

        player.one('loadedmetadata', function () {
          player.muted(true);
          player.currentTime(Math.floor(Date.now() / 1000 - showtime));
          player.one('seeked', function () {
            player.play().then(function () {
              setTimeout(function () { if (player.muted()) nudge(player); }, 500);
            }).catch(function () { player.muted(false); });
          });
        });
      });

      if (Date.now() / 1000 < showtime) {
        var wait = setInterval(function () {
          if (Date.now() / 1000 >= showtime) { clearInterval(wait); location.reload(); }
        }, 5000);
      }

      setInterval(function () {
        var d = Math.floor(Date.now() / 1000 - showtime);
        if (d < 0 || d >= dur) return;
        if (Math.abs(Math.floor(player.currentTime()) - d) > 2) player.currentTime(d);
      }, 3000);

      var el = $('#theatre-player');
      if (el) ['mouseup', 'touchend'].forEach(function (evt) {
        el.addEventListener(evt, function () {
          var d = Math.floor(Date.now() / 1000 - showtime);
          if (d >= 0 && d < dur) player.currentTime(d);   // the showtime is the showtime
        });
      });

      controls(player);
    }

    function nudge(player) {
      var stage = $('#theatre-stage');
      if (!stage) return;
      var b = document.createElement('button');
      b.className = 'btn';
      b.textContent = 'Click to unmute';
      b.style.cssText = 'position:absolute;bottom:20px;right:20px;z-index:20;';
      stage.appendChild(b);
      b.addEventListener('click', function () { player.muted(false); b.remove(); });
    }

    function controls(player) {
      var mute = $('#ctrl-mute'), vol = $('#ctrl-vol'), full = $('#ctrl-full');

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
    initTheme();
    initBar();
    initLead();
    initComposer();
    initStatuses();
    initJoin();
    initTheatre();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();

})();
