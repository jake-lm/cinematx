// ═══════════════════════════════════════════════════════════════════════════
//  CINEMA, TX — v7 behaviour
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
  var store = {
    get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  };

  // ══ The welcome ══════════════════════════════════════════════════════════
  // From the original site. Ten languages, ten-second cadence — originally a
  // ten-deep pyramid of nested jQuery callbacks.

  var GREETINGS = [
    'Welcome to the Cinema', 'Bienvenue au Cinéma', 'Добро пожаловать в кино',
    '劇場へようこそ', '쇼에 오신 걸 환영합니다', 'Bienvenido al Cine',
    'Willkommen im Kino', 'Καλώς ήλθατε στο κίνημα', 'Välkommen till Biografen',
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
  // Paper is the default; the stylesheet only overrides on [data-theme=dark],
  // so this must agree and never consult prefers-color-scheme.

  function initTheme() {
    var btn = $('#theme');
    if (!btn) return;
    function isDark() { return document.documentElement.getAttribute('data-theme') === 'dark'; }
    function paint() {
      btn.innerHTML = '<i class="fa-solid fa-' + (isDark() ? 'sun' : 'moon') + '"></i>';
      btn.title = isDark() ? 'Light' : 'Dark';
    }
    paint();
    btn.addEventListener('click', function () {
      var next = isDark() ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      store.set('ctx-theme', next);
      paint();
    });
  }

  // ══ Rail ═════════════════════════════════════════════════════════════════

  function initRail() {
    var app = $('#app'), btn = $('#rail-toggle');
    if (!app || !btn) return;
    if (store.get('ctx-rail') === 'collapsed') app.classList.add('is-collapsed');
    btn.addEventListener('click', function () {
      store.set('ctx-rail', app.classList.toggle('is-collapsed') ? 'collapsed' : 'open');
    });
  }

  // ══ The List — view mode + venue filter ══════════════════════════════════
  // Every screening for the day is in the DOM. The panel scrolls internally,
  // so nothing is hidden behind a required navigation and the page still
  // never scrolls. Filtering hides rather than re-fetches.

  // Drives both the front page and /list/. The front page has no .day
  // wrappers and no time filter; the list page has both. Same code either way.
  //
  //   [data-view]    grid | rows                    — persisted
  //   [data-narrow]  all | venue:<slug> | source:user
  //   [data-when]    week | today | tmrw            — list page only
  //
  // Each screening is rendered once per view, so counts only tally .shot.

  function initList() {
    // Gate on the toggle, not on the views. The profile page reuses .rows-view
    // for its writing list with is-on set server-side; without this guard
    // applyView() stripped that class and silently emptied the section.
    if (!$$('[data-view]').length) return;

    var listing  = $('#listing');
    var todayKey = listing && listing.getAttribute('data-today');
    var tmrwKey  = listing && listing.getAttribute('data-tmrw');

    var state = {
      view:   store.get('ctx-view') === 'rows' ? 'rows' : 'grid',
      narrow: 'all',
      when:   'week'
    };

    function applyView() {
      var rows = state.view === 'rows';
      $$('.grid-view').forEach(function (el) { el.classList.toggle('is-off', rows); });
      $$('.rows-view').forEach(function (el) { el.classList.toggle('is-on',  rows); });
      $$('[data-view]').forEach(function (b) {
        b.classList.toggle('is-on', b.getAttribute('data-view') === state.view);
      });
      store.set('ctx-view', state.view);
    }

    function matches(el) {
      var n = state.narrow;
      if (n === 'all') return true;
      if (n.indexOf('venue:')  === 0) return el.getAttribute('data-venue')  === n.slice(6);
      if (n.indexOf('source:') === 0) return el.getAttribute('data-source') === n.slice(7);
      return true;
    }

    function apply() {
      var shown = 0;
      var days  = $$('.day');

      if (days.length) {
        days.forEach(function (day) {
          var key   = day.getAttribute('data-day');
          var inDay = state.when === 'week'
                   || (state.when === 'today' && key === todayKey)
                   || (state.when === 'tmrw'  && key === tmrwKey);
          var visible = 0;
          $$('.shot, .line', day).forEach(function (el) {
            var ok = inDay && matches(el);
            el.classList.toggle('is-hidden', !ok);
            if (ok && el.classList.contains('shot')) visible++;
          });
          // A day whose screenings are all filtered out loses its heading too.
          day.classList.toggle('is-hidden', visible === 0);
          shown += visible;
        });
      } else {
        $$('.shot, .line').forEach(function (el) {
          var ok = matches(el);
          el.classList.toggle('is-hidden', !ok);
          if (ok && el.classList.contains('shot')) shown++;
        });
      }

      $$('[data-narrow]').forEach(function (c) {
        c.classList.toggle('is-on', c.getAttribute('data-narrow') === state.narrow);
      });
      $$('[data-when]').forEach(function (c) {
        c.classList.toggle('is-on', c.getAttribute('data-when') === state.when);
      });

      var count = $('#list-count');
      if (count) count.textContent = shown;

      ['#grid-empty', '#rows-empty', '#list-empty'].forEach(function (sel) {
        var el = $(sel);
        if (el) el.style.display = shown ? 'none' : '';
      });
    }

    applyView();
    apply();

    $$('[data-view]').forEach(function (b) {
      b.addEventListener('click', function () { state.view = b.getAttribute('data-view'); applyView(); });
    });
    $$('[data-narrow]').forEach(function (b) {
      b.addEventListener('click', function () { state.narrow = b.getAttribute('data-narrow'); apply(); });
    });
    $$('[data-when]').forEach(function (b) {
      b.addEventListener('click', function () { state.when = b.getAttribute('data-when'); apply(); });
    });
  }

  // ══ Overlays ═════════════════════════════════════════════════════════════

  function initOverlays() {
    var scrim = $('#scrim');
    var open = null;

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
    var area = $('#compose-area'), send = $('#compose-send'),
        count = $('#compose-count'), feed = $('#notes');
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

      var card = btn.closest('.note'), pid = btn.getAttribute('data-post-id');

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
      var btn = form.querySelector('[type=submit]'), err = $('#join-error'), label = btn.value;
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

    var t = $('#theatre-card'); if (t) t.addEventListener('click', open);
    var c = $('#theatre-close'); if (c) c.addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && shell.classList.contains('is-on')) close();
    });

    function play() {
      var showtime = window.CTX_SHOWTIME || 0,
          dur      = window.CTX_DUR || 0,
          filename = window.CTX_FILE || '';
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

  // ══ Copy link ════════════════════════════════════════════════════════════
  // Replaces the old Instagram share button, which pointed at "#" — Instagram
  // has no web share endpoint, and copy-link is what people actually use.

  function initCopyLink() {
    var btn = $('#copy-link');
    if (!btn) return;

    btn.addEventListener('click', function () {
      var url  = btn.getAttribute('data-url') || location.href;
      var mark = function () {
        var was = btn.innerHTML;
        btn.classList.add('is-done');
        btn.innerHTML = '<i class="fa-solid fa-check"></i>';
        setTimeout(function () { btn.classList.remove('is-done'); btn.innerHTML = was; }, 1600);
      };

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(mark).catch(fallback);
      } else { fallback(); }

      function fallback() {
        var t = document.createElement('textarea');
        t.value = url;
        t.style.cssText = 'position:fixed;opacity:0;';
        document.body.appendChild(t);
        t.select();
        try { document.execCommand('copy'); mark(); } catch (e) {}
        t.remove();
      }
    });
  }

  // ══ Directory ════════════════════════════════════════════════════════════
  // Every member is rendered server-side, so search and role narrowing are
  // pure DOM work. The old page round-tripped to function.php for each of
  // these, which is where its SQL injection lived.

  function initDirectory() {
    var roster = $('#roster');
    if (!roster) return;

    var search = $('#dir-search');
    var count  = $('#dir-count');
    var empty  = $('#dir-empty');
    var role   = 'all';

    function apply() {
      var term  = (search && search.value || '').trim().toLowerCase();
      var shown = 0;

      $$('.member', roster).forEach(function (m) {
        var okRole = role === 'all'
                  || (role === 'saved' ? m.getAttribute('data-saved') === '1'
                                       : m.getAttribute('data-role') === role);
        var okTerm = !term || (m.getAttribute('data-search') || '').indexOf(term) !== -1;
        var ok = okRole && okTerm;
        m.classList.toggle('is-hidden', !ok);
        if (ok) shown++;
      });

      $$('[data-role]').forEach(function (c) {
        if (c.tagName === 'BUTTON') c.classList.toggle('is-on', c.getAttribute('data-role') === role);
      });

      if (count) count.textContent = shown;
      if (empty) empty.style.display = shown ? 'none' : '';
    }

    if (search) search.addEventListener('input', apply);
    $$('button[data-role]').forEach(function (b) {
      b.addEventListener('click', function () { role = b.getAttribute('data-role'); apply(); });
    });

    // Save / unsave. The endpoint derives whose list it is from the session,
    // so only the target id travels.
    $$('[data-save]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var uid  = btn.getAttribute('data-save');
        var on   = btn.classList.contains('is-on');
        var next = on ? 'removefrom' : 'addto';
        btn.disabled = true;

        post('/function.php?action=' + next, { uid: uid })
          .then(function (res) {
            if (!res || !res.ok) throw new Error('rejected');
            btn.classList.toggle('is-on', !on);
            btn.title = !on ? 'Saved to your list' : 'Save to your list';
            btn.innerHTML = '<i class="fa-' + (!on ? 'solid' : 'regular') + ' fa-bookmark"></i>';
            var card = btn.closest('.member');
            if (card) card.setAttribute('data-saved', !on ? '1' : '0');
          })
          .catch(function () {})
          .then(function () { btn.disabled = false; });
      });
    });
  }

  function boot() {
    initWelcome(); initTheme(); initRail(); initList();
    initOverlays(); initComposer(); initNotes(); initJoin();
    initCopyLink(); initDirectory(); initTheatre();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();

})();
