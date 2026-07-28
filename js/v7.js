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

    // Rendered server-side too, so the greeting is already correct before this
    // runs and does not flash a nameless version first.
    var name = el.getAttribute('data-name') || '';
    var greet = function (s) { return name ? s + ', ' + name : s; };

    var i = 0;
    el.textContent = greet(GREETINGS[0]);
    setInterval(function () {
      el.classList.add('is-out');
      setTimeout(function () {
        i = (i + 1) % GREETINGS.length;
        el.textContent = greet(GREETINGS[i]);
        el.classList.remove('is-out');
      }, 500);
    }, 10000);
  }

  // ══ Image cycle ══════════════════════════════════════════════════════════
  // The Journal lead card and an article's own hero share this — both are a
  // box of stacked <img> (and, on the article page, <figcaption>) elements
  // with the current one carrying .is-on. Only ever rendered server-side when
  // there is a second image to fade to, so this never runs for a lone photo.

  function initImageCycle() {
    $$('.lead__art--cycle, .reading__figure--cycle').forEach(function (box) {
      var imgs = $$('img', box);
      if (imgs.length < 2) return;
      var caps = $$('figcaption', box);
      var i = 0;
      setInterval(function () {
        imgs[i].classList.remove('is-on');
        if (caps[i]) caps[i].classList.remove('is-on');
        i = (i + 1) % imgs.length;
        imgs[i].classList.add('is-on');
        if (caps[i]) caps[i].classList.add('is-on');
      }, 10000);
    });
  }

  // ══ Custom select ════════════════════════════════════════════════════════
  // Progressive enhancement over every real <select> on the site. The select
  // stays in the DOM — hidden, not removed, not display:none — so it is still
  // the thing that holds the value: form submission, FormData and any
  // existing $(...).val() all keep working untouched. Picking an option sets
  // select.value and fires a real 'change' event, so anything already
  // listening for that (autosave triggers, etc.) still runs exactly as
  // before. The open menu is one shared element appended to <body> and
  // positioned like the hovercard, rather than nested where the select lives —
  // nested would get clipped by the first scrolling ancestor (a sheet body, a
  // card), which a menu that can open near the bottom of one very much does.

  function initCustomSelect() {
    var selects = $$('select');
    if (!selects.length) return;

    var menu = document.createElement('ul');
    menu.className = 'xselect__menu';
    menu.setAttribute('role', 'listbox');
    document.body.appendChild(menu);

    var openEntry = null, opts = [], activeIndex = -1;

    function place(btn) {
      var r = btn.getBoundingClientRect();
      menu.style.width = r.width + 'px';
      var top = r.bottom + 4;
      // Flips upward only when there is genuinely more room there — a menu
      // that flips just because it is tall reads as broken, not smart.
      if (top + 260 > window.innerHeight - 8 && r.top > 260) top = r.top - 264;
      menu.style.left = Math.round(r.left + window.scrollX) + 'px';
      menu.style.top  = Math.round(top + window.scrollY) + 'px';
    }

    function closeMenu() {
      if (!openEntry) return;
      menu.classList.remove('is-on');
      openEntry.btn.setAttribute('aria-expanded', 'false');
      openEntry.wrap.classList.remove('is-open');
      openEntry = null;
      opts = [];
      activeIndex = -1;
    }

    function setActive(i) {
      if (opts[activeIndex]) opts[activeIndex].classList.remove('is-active');
      if (!opts.length) return;
      activeIndex = ((i % opts.length) + opts.length) % opts.length;
      opts[activeIndex].classList.add('is-active');
      opts[activeIndex].scrollIntoView({ block: 'nearest' });
    }

    function openMenu(entry) {
      if (entry.btn.disabled) return;
      if (openEntry === entry) { closeMenu(); return; }
      closeMenu();
      openEntry = entry;

      menu.innerHTML = '';
      opts = $$('option', entry.select).map(function (o) {
        var on = o.value === entry.select.value;
        var li = document.createElement('li');
        li.className = 'xselect__opt' + (on ? ' is-on' : '');
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', on ? 'true' : 'false');
        li.textContent = o.textContent;
        li.dataset.value = o.value;
        menu.appendChild(li);
        return li;
      });

      place(entry.btn);
      menu.classList.add('is-on');
      entry.btn.setAttribute('aria-expanded', 'true');
      entry.wrap.classList.add('is-open');
      var idx = opts.findIndex(function (li) { return li.dataset.value === entry.select.value; });
      setActive(idx >= 0 ? idx : 0);
    }

    function choose(i) {
      if (!openEntry) return;
      var entry = openEntry, li = opts[i];
      if (!li) return;
      entry.select.value = li.dataset.value;
      entry.select.dispatchEvent(new Event('change', { bubbles: true }));
      closeMenu();
      entry.btn.focus();
    }

    function syncLabel(entry) {
      var current = entry.select.options[entry.select.selectedIndex];
      entry.label.textContent = current ? current.textContent : '';
    }

    selects.forEach(function (select) {
      if (select.dataset.xselect) return;
      select.dataset.xselect = '1';

      var wrap = document.createElement('div');
      wrap.className = 'xselect';
      select.parentNode.insertBefore(wrap, select);

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'xselect__btn';
      btn.setAttribute('aria-haspopup', 'listbox');
      btn.setAttribute('aria-expanded', 'false');
      btn.disabled = select.disabled;

      var label = document.createElement('span');
      label.className = 'xselect__label';
      btn.appendChild(label);

      var caret = document.createElement('i');
      caret.className = 'fa-solid fa-chevron-down xselect__caret';
      btn.appendChild(caret);

      // Programmatically reachable still (a validation failure can focus it),
      // just no longer a stop on the way there — the button is that now.
      select.tabIndex = -1;
      select.setAttribute('aria-hidden', 'true');

      wrap.appendChild(select);
      wrap.appendChild(btn);

      var entry = { select: select, wrap: wrap, btn: btn, label: label };
      syncLabel(entry);

      // The one thing this never has to special-case: whatever else changes
      // the select's value — us, or a script setting .val() and triggering
      // change — this is the single place the label redraws from.
      select.addEventListener('change', function () { syncLabel(entry); });

      btn.addEventListener('click', function () {
        if (openEntry === entry) closeMenu(); else openMenu(entry);
      });

      btn.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
          e.preventDefault();
          if (openEntry !== entry) { openMenu(entry); return; }
          setActive(activeIndex + (e.key === 'ArrowDown' ? 1 : -1));
        } else if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          if (openEntry === entry) choose(activeIndex); else openMenu(entry);
        } else if (e.key === 'Escape' && openEntry === entry) {
          closeMenu();
        }
      });
    });

    menu.addEventListener('click', function (e) {
      var li = e.target.closest ? e.target.closest('.xselect__opt') : null;
      if (li) choose(opts.indexOf(li));
    });

    // Mouse and keyboard share one highlight rather than two competing
    // states — hovering an option moves the same .is-active setActive()
    // already draws for the arrow keys, so whichever you used last is
    // consistently what Enter would pick.
    menu.addEventListener('mouseover', function (e) {
      var li = e.target.closest ? e.target.closest('.xselect__opt') : null;
      if (li) setActive(opts.indexOf(li));
    });

    // Mirrors the hovercard: closing on scroll rather than repositioning is
    // the simpler correct behaviour, and outside clicks close it same as any
    // other open panel on the page. Scrolling the menu's own option list is
    // not "scroll" in that sense, though — a long list (every film, in the
    // admin panel) is exactly why it scrolls internally in the first place.
    document.addEventListener('click', function (e) {
      if (!openEntry) return;
      if (e.target.closest && (e.target.closest('.xselect__menu') || e.target.closest('.xselect'))) return;
      closeMenu();
    });
    window.addEventListener('scroll', function (e) {
      if (openEntry && menu.contains(e.target)) return;
      closeMenu();
    }, true);
    window.addEventListener('resize', closeMenu);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && openEntry) closeMenu(); });
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
  // Each screening is rendered once per view, so counts only tally .shot —
  // and by data-count rather than by element, because a folded venue card
  // stands for all of its showings.

  function initList() {
    // Gate on the toggle, not on the views. The profile page reuses .rows-view
    // for its writing list with is-on set server-side; without this guard
    // applyView() stripped that class and silently emptied the section.
    if (!$$('[data-view]').length) return;

    var listing  = $('#listing');
    var todayKey = listing && listing.getAttribute('data-today');
    var tmrwKey  = listing && listing.getAttribute('data-tmrw');

    // Depth only exists on the front page. A value stored there must not be
    // restored on /list/, which has no .depth-view to show — it would just
    // turn grid off with nothing to replace it.
    var hasDepth = !!$('.depth-view');
    var stored   = store.get('ctx-view');
    var state = {
      view: stored === 'rows' ? 'rows' : (stored === 'depth' && hasDepth ? 'depth' : 'grid'),
      narrow: 'all',
      when:   'week'
    };

    function applyView() {
      $$('.grid-view').forEach(function (el) { el.classList.toggle('is-off', state.view !== 'grid'); });
      $$('.rows-view').forEach(function (el) { el.classList.toggle('is-on', state.view === 'rows'); });
      $$('.depth-view').forEach(function (el) { el.classList.toggle('is-on', state.view === 'depth'); });
      $$('[data-view]').forEach(function (b) {
        b.classList.toggle('is-on', b.getAttribute('data-view') === state.view);
      });
      store.set('ctx-view', state.view);
    }

    // How many screenings an element stands for: one, unless it is a folded
    // venue card. Keeps the header agreeing with the venue chips, which count
    // screenings and are rendered before any folding happens.
    function weight(el) {
      return parseInt(el.getAttribute('data-count'), 10) || 1;
    }

    function matches(el) {
      var n = state.narrow;

      // A venue that folds renders twice: the collapsed card, and the films it
      // contains. Narrowing to that venue shows the films and hides the card —
      // once you have asked for Alamo, a card headed "Alamo" says nothing.
      var fold   = el.getAttribute('data-fold');
      var unfold = el.getAttribute('data-unfold');
      var asked  = n.indexOf('venue:') === 0 ? n.slice(6) : null;
      if (fold   && fold   === asked) return false;
      if (unfold && unfold !== asked) return false;

      if (n === 'all') return true;
      if (n.indexOf('venue:')  === 0) return el.getAttribute('data-venue')  === asked;
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
          // .deep only exists on the front page, which has no .day wrappers,
          // so this branch never actually sees one — listed for symmetry with
          // the flat branch below.
          $$('.shot, .line, .deep', day).forEach(function (el) {
            var ok = inDay && matches(el);
            el.classList.toggle('is-hidden', !ok);
            if (ok && el.classList.contains('shot')) visible += weight(el);
          });
          // A day whose screenings are all filtered out loses its heading too.
          day.classList.toggle('is-hidden', visible === 0);
          shown += visible;
        });
      } else {
        // .shot alone still drives the count — it is rendered once per
        // screening regardless of which view is on screen, so .deep (the
        // depth view's rendering of the same data) must stay hidden-only
        // here or every screening would be counted twice.
        $$('.shot, .line, .deep').forEach(function (el) {
          var ok = matches(el);
          el.classList.toggle('is-hidden', !ok);
          if (ok && el.classList.contains('shot')) shown += weight(el);
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

      var noun = $('#list-noun');
      if (noun) noun.textContent = shown === 1 ? 'screening' : 'screenings';

      // Both surfaces share this function; only /list/ has the time control,
      // and only /list/ has a scope word to keep honest.
      var scope = $('#list-scope');
      if (scope) {
        scope.textContent = { week: 'next seven days', today: 'today', tmrw: 'tomorrow' }[state.when]
                          || 'next seven days';
      }

      ['#grid-empty', '#rows-empty', '#depth-empty', '#list-empty'].forEach(function (sel) {
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

    // Live email availability — debounced so typing doesn't hit the server on
    // every keystroke, only once it pauses on something that looks complete.
    // The border is the whole signal, no text underneath.
    var emailInput = $('#j-email', form);
    var emailTimer = null;
    if (emailInput) {
      emailInput.addEventListener('input', function () {
        clearTimeout(emailTimer);
        var value = emailInput.value.trim();
        emailInput.classList.remove('field__input--ok', 'field__input--bad');
        if (!value) return;
        emailTimer = setTimeout(function () {
          post('/dashboard/signup.php?action=check_email', { email: value })
            .then(function (res) {
              var ok = !!(res && res.valid && res.available);
              emailInput.classList.toggle('field__input--ok', ok);
              emailInput.classList.toggle('field__input--bad', !ok);
            })
            .catch(function () {});
        }, 400);
      });
    }

    // Live password match — no round trip needed, so every keystroke checks.
    var pw = $('#j-pw', form), pw2 = $('#j-pw2', form);
    if (pw && pw2) {
      var checkMatch = function () {
        pw2.classList.remove('field__input--ok', 'field__input--bad');
        if (!pw2.value) return;
        var ok = pw.value === pw2.value;
        pw2.classList.toggle('field__input--ok', ok);
        pw2.classList.toggle('field__input--bad', !ok);
      };
      pw.addEventListener('input', checkMatch);
      pw2.addEventListener('input', checkMatch);
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = form.querySelector('[type=submit]'), err = $('#join-error'), label = btn.value;
      btn.value = '…'; btn.disabled = true;

      post('/dashboard/signup.php?action=signup', new FormData(form))
        .then(function (res) {
          if (res && res.success) {
            var s1 = $('#join-step-1'), s2 = $('#join-step-2');
            if (s1) s1.style.display = 'none';
            if (s2) s2.style.display = 'block';
            return;
          }
          var msgs = { '102': 'Fill every field and make sure the passwords match.',
                       '104': 'That email is already registered.',
                       '108': 'Invalid access code.',
                       '110': 'Passwords must be at least 8 characters.' };
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

  // The sync itself, shared by the overlay on the front page and the standalone
  // theatre pages. Carried over from theatre_1(): park pre-show, seek to
  // elapsed, muted autoplay, correct drift beyond 2s every 3s, snap back on
  // scrub. Written once so the two surfaces can never drift apart.

  function startSync(playerId, stageId) {
    var showtime = window.CTX_SHOWTIME || 0,
        dur      = window.CTX_DUR || 0,
        filename = window.CTX_FILE || '';
    if (!showtime || !filename || typeof videojs === 'undefined') return;

    var player = videojs(playerId);

    player.ready(function () {
      var diff = Math.floor(Date.now() / 1000 - showtime);
      if (diff < 0 || diff >= dur) return;   // pre-show, or over

      // The markup says preload="none" so the front page never pulls a 250MB
      // film just because the theatre card is on screen. The cost is that the
      // browser then fetches nothing on its own — loadedmetadata never fires
      // and everything below waits on an event that will not come. Now that
      // there is something to play, ask for the metadata.
      player.preload('auto');

      // stream.php serves HTTP range requests — required for seeking.
      player.src({ type: 'video/mp4', src: '/motw/stream.php?f=' + encodeURIComponent(filename) });

      function seek() {
        player.muted(true);
        player.currentTime(Math.floor(Date.now() / 1000 - showtime));
        player.one('seeked', function () {
          var done = function () {
            setTimeout(function () { if (player.muted()) nudge(player, stageId); }, 500);
          };
          // Only some techs return a promise from play().
          var p = player.play();
          if (p && p.then) p.then(done).catch(function () { player.muted(false); });
          else done();
        });
      }

      // On a warm cache the metadata can already be in hand, and one() would
      // then wait for an event that has been and gone.
      if (player.readyState() >= 1) seek();
      else player.one('loadedmetadata', seek);
    });

    // Pre-show: reload when the showtime arrives.
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

    var el = document.getElementById(playerId);
    if (el) ['mouseup', 'touchend'].forEach(function (evt) {
      el.addEventListener(evt, function () {
        var d = Math.floor(Date.now() / 1000 - showtime);
        if (d >= 0 && d < dur) player.currentTime(d);   // the showtime is the showtime
      });
    });

    controls(player);
    return player;
  }

  function nudge(player, stageId) {
    var stage = document.getElementById(stageId || 'theatre-stage');
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

  // ══ Screening room (/th1/, /th2/) ════════════════════════════════════════
  // A page rather than an overlay, so playback starts on load.

  function initScreening() {
    if (!$('#screening-player')) return;
    startSync('screening-player', 'screening-stage');
  }

  // ══ Theatre overlay (front page) ═════════════════════════════════════════

  function initTheatre() {
    var shell = $('#theatre');
    if (!shell) return;
    var started = false;

    function open() {
      shell.classList.add('is-on');
      if (!started) { started = true; startSync('theatre-player', 'theatre-stage'); }
    }
    function close() { shell.classList.remove('is-on'); }

    var t = $('#theatre-card'); if (t) t.addEventListener('click', open);
    var c = $('#theatre-close'); if (c) c.addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && shell.classList.contains('is-on')) close();
    });
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

  // ══ Profile — face upload ════════════════════════════════════════════════
  // Only markup on your own profile page carries #who-face-input, so this is
  // a no-op everywhere else. Reuses the same endpoint the dashboard's Account
  // tab already uploads through — this is just a second door to it, not a
  // second implementation.

  function initProfileFaceUpload() {
    var input = $('#who-face-input');
    var face  = $('#who-face');
    if (!input || !face) return;

    input.addEventListener('change', function () {
      var file = input.files[0];
      if (!file) return;

      var reader = new FileReader();
      reader.onload = function (e) {
        var img = face.querySelector('img');
        if (!img) {
          img = document.createElement('img');
          face.insertBefore(img, face.firstChild);
          var letter = face.querySelector('.who__face-letter');
          if (letter) letter.remove();
        }
        img.src = e.target.result;
      };
      reader.readAsDataURL(file);

      var fd = new FormData();
      fd.append('photo', file);
      fetch('/dashboard/profile.php?action=upload_photo', {
        method: 'POST', body: fd, credentials: 'same-origin'
      })
        .then(function (r) { return r.json(); })
        .then(function (res) { if (!res || !res.success) location.reload(); })
        .catch(function () { location.reload(); });
    });
  }

  // ══ Profile — Letterboxd connect ════════════════════════════════════════
  // Favourites and recent watches need a fresh scrape to show up, which only
  // happens on page load — so success here reloads rather than trying to
  // splice the Letterboxd card together client-side.

  function initLetterboxdConnect() {
    var input = $('#lb-input'), save = $('#lb-save');
    if (!input || !save) return;

    save.addEventListener('click', function () {
      var value = input.value.trim();
      if (!value) return;
      save.disabled = true;
      var label = save.textContent;
      save.textContent = '…';

      post('/dashboard/signup.php?action=update_lb', { lb: value })
        .then(function (res) {
          if (res && res.success) location.reload();
          else { save.disabled = false; save.textContent = label; }
        })
        .catch(function () { save.disabled = false; save.textContent = label; });
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

  // ══ Hovercard ════════════════════════════════════════════════════════════
  // Wikipedia's page previews, generalised. Any element carrying data-hover
  // gets one; the JSON inside names six slots and the card lays out around
  // whichever are present, so it has no idea what a film is and the next
  // thing that wants a preview only has to emit the attribute.
  //
  // Desktop only, and not by choice of breakpoint — a preview that needs a
  // hover cannot be reached without a pointer, and on touch the same gesture
  // is a tap, which should follow the link.

  var HOVER_IN  = 420;   // dwell before showing — a glance passing over is not a request
  var HOVER_OUT = 160;   // grace on the way out, so the gap to the card is crossable

  function initHovercard() {
    if (!window.matchMedia || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;

    var card = null, inT = null, outT = null, anchor = null;

    function build() {
      if (card) return card;
      card = document.createElement('div');
      card.className = 'hovercard';
      card.addEventListener('mouseenter', function () { clearTimeout(outT); });
      card.addEventListener('mouseleave', leave);
      document.body.appendChild(card);
      return card;
    }

    function paint(d) {
      var h = '';
      if (d.img) h += '<span class="hovercard__art"><img src="' + esc(d.img) + '" alt="" /></span>';
      h += '<span class="hovercard__in">';
      if (d.title) h += '<span class="hovercard__title">' + esc(d.title) + '</span>';
      if (d.meta)  h += '<span class="hovercard__meta">'  + esc(d.meta)  + '</span>';
      if (d.sub)   h += '<span class="hovercard__sub">'   + esc(d.sub)   + '</span>';
      if (d.body)  h += '<span class="hovercard__body">'  + esc(d.body)  + '</span>';

      var href = safeHref(d.link && d.link.href);
      if (d.foot || href) {
        h += '<span class="hovercard__foot">';
        h += '<span>' + esc(d.foot || '') + '</span>';
        if (href) {
          h += '<a class="hovercard__link" href="' + esc(href) + '" target="_blank" rel="noopener">'
             + esc((d.link.label || 'More')) + ' <i class="fa-solid fa-arrow-up-right-from-square"></i></a>';
        }
        h += '</span>';
      }
      h += '</span>';
      card.innerHTML = h;
    }

    // This slot becomes an href in markup we build ourselves, so it is the one
    // field that has to be more than escaped — anything that is not plainly
    // http(s) is dropped rather than rendered.
    function safeHref(u) {
      if (typeof u !== 'string') return null;
      return /^https?:\/\//i.test(u.trim()) ? u.trim() : null;
    }

    // Below the anchor and left-aligned by default, flipping on whichever axis
    // runs out of room. Measured after paint, since the height depends on how
    // much plot there is.
    function place(el) {
      var r  = el.getBoundingClientRect();
      var cw = card.offsetWidth, ch = card.offsetHeight;
      var pad = 10;

      var left = r.left;
      if (left + cw > window.innerWidth - pad) left = r.right - cw;
      if (left < pad) left = pad;

      var top = r.bottom + 8;
      if (top + ch > window.innerHeight - pad) {
        var above = r.top - ch - 8;
        top = above >= pad ? above : Math.max(pad, window.innerHeight - ch - pad);
      }

      card.style.left = Math.round(left + window.scrollX) + 'px';
      card.style.top  = Math.round(top  + window.scrollY) + 'px';
    }

    function show(el) {
      var raw = el.getAttribute('data-hover');
      if (!raw) return;
      var d;
      try { d = JSON.parse(raw); } catch (e) { return; }

      anchor = el;
      build();
      paint(d);
      card.classList.add('is-measuring');   // laid out but not yet visible
      place(el);
      card.classList.remove('is-measuring');
      card.classList.add('is-on');
    }

    function hide() {
      if (card) { card.classList.remove('is-on'); card.innerHTML = ''; }
      anchor = null;
    }

    function leave() {
      clearTimeout(inT);
      outT = setTimeout(hide, HOVER_OUT);
    }

    document.addEventListener('mouseover', function (e) {
      var el = e.target.closest ? e.target.closest('[data-hover]') : null;
      if (!el || el === anchor) return;
      clearTimeout(inT); clearTimeout(outT);
      inT = setTimeout(function () { show(el); }, HOVER_IN);
    });

    document.addEventListener('mouseout', function (e) {
      var el = e.target.closest ? e.target.closest('[data-hover]') : null;
      if (!el) return;
      // Moving between children of the same anchor is not leaving it.
      if (e.relatedTarget && el.contains(e.relatedTarget)) return;
      if (e.relatedTarget && card && card.contains(e.relatedTarget)) return;
      leave();
    });

    // A card pinned to an element that has moved is worse than no card.
    window.addEventListener('scroll', function () { clearTimeout(inT); hide(); }, true);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { clearTimeout(inT); hide(); } });
    // A click anywhere dismisses — except inside the card, which now holds a
    // link. Tearing that out from under the cursor mid-click is both hostile
    // and a race with the navigation it was supposed to start.
    document.addEventListener('click', function (e) {
      if (card && card.contains(e.target)) return;
      clearTimeout(inT); hide();
    });
  }

  function boot() {
    initWelcome(); initTheme(); initRail(); initList();
    initOverlays(); initComposer(); initNotes(); initJoin();
    initCopyLink(); initDirectory(); initTheatre(); initScreening();
    initHovercard(); initImageCycle(); initCustomSelect();
    initProfileFaceUpload(); initLetterboxdConnect();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();

})();
