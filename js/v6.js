// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — v6 behaviour
//  Vanilla. Unchanged PHP endpoints. theatre_1()'s sync algorithm carried over.
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

  // ══ The welcome ══════════════════════════════════════════════════════════
  // Carried over from the original site, where it was a ten-deep pyramid of
  // nested jQuery callbacks. Same languages, same ten-second cadence, flat.

  var GREETINGS = [
    'Welcome to the Cinema',
    'Bienvenue au Cinéma',
    'Добро пожаловать в кино',
    '劇場へようこそ',
    '쇼에 오신 걸 환영합니다',
    'Bienvenido al Cine',
    'Willkommen im Kino',
    'Καλώς ήλθατε στο κίνημα',
    'Välkommen till Biografen',
    'सिनेमा में आपका स्वागत है'
  ];

  function initWelcome() {
    var el = $('#welcome');
    if (!el) return;

    var i = 0;
    el.textContent = GREETINGS[0];

    setInterval(function () {
      el.classList.add('is-out');
      setTimeout(function () {
        i = (i + 1) % GREETINGS.length;
        el.textContent = GREETINGS[i];
        el.classList.remove('is-out');
      }, 500);
    }, 10000);
  }

  // ══ Theme ════════════════════════════════════════════════════════════════
  // Dark is the default. The stylesheet only overrides on [data-theme=light],
  // so this must agree and never consult prefers-color-scheme.

  function initTheme() {
    var btn = $('#theme');
    if (!btn) return;

    function isLight() { return document.documentElement.getAttribute('data-theme') === 'light'; }

    function paint() {
      btn.innerHTML = '<i class="fa-solid fa-' + (isLight() ? 'moon' : 'sun') + '"></i>';
      btn.title = isLight() ? 'Dark' : 'Light';
    }
    paint();

    btn.addEventListener('click', function () {
      var next = isLight() ? 'dark' : 'light';
      document.documentElement.setAttribute('data-theme', next);
      try { localStorage.setItem('ctx-theme', next); } catch (e) {}
      paint();
    });
  }

  // ══ Rail ═════════════════════════════════════════════════════════════════

  function initRail() {
    var app = $('#app');
    var btn = $('#rail-toggle');
    if (!app || !btn) return;

    try { if (localStorage.getItem('ctx-rail') === 'collapsed') app.classList.add('is-collapsed'); } catch (e) {}

    btn.addEventListener('click', function () {
      var collapsed = app.classList.toggle('is-collapsed');
      try { localStorage.setItem('ctx-rail', collapsed ? 'collapsed' : 'open'); } catch (e) {}
    });
  }

  // ══ Overlays ═════════════════════════════════════════════════════════════

  function initOverlays() {
    var scrim = $('#scrim');
    var open  = null;

    function close() {
      if (open) open.classList.remove('is-on');
      if (scrim) scrim.classList.remove('is-on');
      open = null;
    }

    function show(name) {
      var el = $('#sheet-' + name);
      if (!el) return;
      if (open && open !== el) open.classList.remove('is-on');
      el.classList.add('is-on');
      if (scrim) scrim.classList.add('is-on');
      open = el;
    }

    $$('[data-open]').forEach(function (b) {
      b.addEventListener('click', function (e) { e.preventDefault(); show(b.getAttribute('data-open')); });
    });
    $$('[data-close]').forEach(function (b) { b.addEventListener('click', close); });
    if (scrim) scrim.addEventListener('click', close);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && open) close(); });

    if (location.hash === '#join') show('account');
  }

  // ══ Composer ═════════════════════════════════════════════════════════════

  function initComposer() {
    var area  = $('#compose-area');
    var send  = $('#compose-send');
    var count = $('#compose-count');
    var feed  = $('#notes');
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
            el.className = 'note';
            el.setAttribute('data-post-id', p.id);
            el.innerHTML =
              '<button class="note__del" data-post-id="' + p.id + '" title="Delete">&times;</button>' +
              '<div class="note__text">' + esc(p.content).replace(/\n/g, '<br>') + '</div>' +
              '<div class="note__meta"><span>' + esc(p.author_name || 'You') + '</span><span>just now</span></div>';
            feed.insertBefore(el, feed.firstChild);
          }
        })
        .catch(function () { area.placeholder = 'Could not post — try again.'; })
        .then(function () { send.textContent = label; send.disabled = !area.value.trim(); });
    });
  }

  // ══ Notes — two-step delete ══════════════════════════════════════════════

  function initNotes() {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.note__del') : null;
      if (!btn) return;
      e.stopPropagation();

      var card = btn.closest('.note');
      var pid  = btn.getAttribute('data-post-id');

      if (!btn.classList.contains('is-confirming')) {
        btn.textContent = 'delete?';
        btn.classList.add('is-confirming');
        setTimeout(function () {
          if (btn.classList.contains('is-confirming')) {
            btn.innerHTML = '&times;'; btn.classList.remove('is-confirming');
          }
        }, 2500);
        return;
      }

      btn.textContent = '…'; btn.disabled = true;

      post('/posts/quick.php', { type: 'delete', post_id: pid })
        .then(function (res) {
          if (!res || !res.success) throw new Error('rejected');
          card.style.transition = 'opacity 180ms linear';
          card.style.opacity = '0';
          setTimeout(function () { card.remove(); }, 190);
        })
        .catch(function () {
          btn.innerHTML = '&times;'; btn.disabled = false; btn.classList.remove('is-confirming');
        });
    });
  }

  // ══ Join ═════════════════════════════════════════════════════════════════

  function initJoin() {
    var form = $('#join-form');
    if (!form) return;

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = form.querySelector('[type=submit]');
      var err = $('#join-error');
      var label = btn.value;
      btn.value = '…'; btn.disabled = true;

      post('/dashboard/signup.php?action=signup', new FormData(form))
        .then(function (res) {
          if (res && res.success) {
            var uid = $('#join-uid'); if (uid) uid.value = res.uid;
            var s1 = $('#join-step-1'), s2 = $('#join-step-2');
            if (s1) s1.style.display = 'none';
            if (s2) s2.style.display = 'block';
            return;
          }
          var msgs = { '102': 'Fill every field and make sure the passwords match.',
                       '104': 'That email is already registered.',
                       '108': 'Invalid access code.' };
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
      if (!started) { started = true; play(); }
    }
    function close() { shell.classList.remove('is-on'); }

    var t = $('#screen'); if (t) t.addEventListener('click', open);
    var c = $('#theatre-close'); if (c) c.addEventListener('click', close);
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
        if (diff < 0 || diff >= dur) return;   // pre-show, or over

        // stream.php serves HTTP range requests — required for seeking.
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
      var stage = $('#theatre-stage'); if (!stage) return;
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
        var m = !player.muted(); player.muted(m);
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

  function boot() {
    initWelcome(); initTheme(); initRail(); initOverlays();
    initComposer(); initNotes(); initJoin(); initTheatre();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();

})();
