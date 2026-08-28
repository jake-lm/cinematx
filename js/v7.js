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

  // ══ Theme switcher ═══════════════════════════════════════════════════════
  // Public-site-only replacement for the plain sun/moon button above — the
  // admin panel keeps #theme and initTheme() completely unchanged, since a
  // page only ever renders one of #theme or #theme-trigger and each init
  // no-ops when its markup is absent. Adds a family (Paper/Marquee) on top
  // of light/dark: a swatch pair mirrors the picker already built for the
  // Instagram admin page, and the mode button applies to whichever family
  // is active. Each family remembers its own last mode (ctx-theme-mode-<fam>)
  // rather than one shared flag — that's what makes Marquee "default dark,
  // still toggle to light" work with no special case: the first time
  // there's simply no stored mode for it yet.
  function initThemeSwitcher() {
    var trigger = $('#theme-trigger'), menu = $('#theme-menu');
    if (!trigger || !menu) return;

    // Escapes the bar's own stacking context, same reason the You menu and
    // the custom-select menu both live at the body level.
    document.body.appendChild(menu);

    var modeBtn  = $('.theme-mode', menu);
    var swatches = $$('.theme-swatch', menu);

    function family() { return store.get('ctx-theme-family') || 'paper'; }
    function mode(fam) {
      // The legacy fallback matches the same one-time bridge in _head.php's
      // inline FOUC script, so a returning dark-mode visitor's preference
      // survives the switch to per-family storage instead of silently
      // resetting to light the first time this runs.
      var stored = store.get('ctx-theme-mode-' + fam) || (fam === 'paper' ? store.get('ctx-theme') : null);
      return stored || (fam === 'marquee' ? 'dark' : 'light');
    }

    function paint() {
      var fam = family(), m = mode(fam), dark = m === 'dark';
      var theme = fam === 'marquee' ? (dark ? 'marquee-dark' : 'marquee') : (dark ? 'dark' : 'light');
      document.documentElement.setAttribute('data-theme', theme);

      swatches.forEach(function (s) { s.classList.toggle('is-on', s.getAttribute('data-family') === fam); });
      modeBtn.innerHTML = '<i class="fa-solid fa-' + (dark ? 'sun' : 'moon') + '"></i><span>' + (dark ? 'Light' : 'Dark') + '</span>';
      trigger.innerHTML = '<i class="fa-solid fa-' + (dark ? 'moon' : 'sun') + '"></i>';
      trigger.title = fam.charAt(0).toUpperCase() + fam.slice(1) + ', ' + m;
    }
    paint();

    swatches.forEach(function (s) {
      s.addEventListener('click', function () {
        store.set('ctx-theme-family', s.getAttribute('data-family'));
        paint();
      });
    });
    modeBtn.addEventListener('click', function () {
      var fam = family();
      store.set('ctx-theme-mode-' + fam, mode(fam) === 'dark' ? 'light' : 'dark');
      paint();
    });

    function place() {
      var r = trigger.getBoundingClientRect();
      var w = menu.offsetWidth;
      var left = Math.min(Math.max(8, r.right - w), window.innerWidth - w - 8);
      menu.style.left = Math.round(left) + 'px';
      menu.style.top  = Math.round(r.bottom + 8) + 'px';
    }
    function open()  { menu.classList.add('is-on'); place(); trigger.setAttribute('aria-expanded', 'true'); }
    function close() { menu.classList.remove('is-on'); trigger.setAttribute('aria-expanded', 'false'); }

    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      if (menu.classList.contains('is-on')) close(); else open();
    });
    // Mirrors the You menu: closing on scroll rather than repositioning,
    // and any click outside either the trigger or the open menu closes it.
    document.addEventListener('click', function (e) {
      if (!menu.classList.contains('is-on')) return;
      if (e.target.closest && (e.target.closest('.theme-menu') || e.target === trigger || trigger.contains(e.target))) return;
      close();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    window.addEventListener('scroll', close, true);
    window.addEventListener('resize', close);
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

  // ══ Mobile "You" menu ════════════════════════════════════════════════════
  // The compact trigger's only job on a wide screen is to not be there — see
  // .rail__link--compact in the stylesheet. On the mobile bottom bar it opens
  // this instead of a sheet, on purpose: four links don't need a scrim and a
  // page-covering panel, just a small menu above the tab that opened it.

  function initYouMenu() {
    var btn = $('#you-trigger'), menu = $('#you-menu');
    if (!btn || !menu) return;

    // Escapes the rail nav's own overflow (it scrolls horizontally on
    // mobile), the same reason the custom-select menu and the hovercard
    // both live at the body level rather than nested where they open.
    document.body.appendChild(menu);

    function place() {
      var r = btn.getBoundingClientRect();
      var w = menu.offsetWidth;
      var left = r.left + r.width / 2 - w / 2;
      left = Math.min(Math.max(8, left), window.innerWidth - w - 8);
      menu.style.left = Math.round(left) + 'px';
      menu.style.top  = Math.round(r.top - menu.offsetHeight - 8) + 'px';
    }

    function open() {
      menu.classList.add('is-on');
      place();
      btn.setAttribute('aria-expanded', 'true');
    }
    function close() {
      menu.classList.remove('is-on');
      btn.setAttribute('aria-expanded', 'false');
    }

    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      if (menu.classList.contains('is-on')) close(); else open();
    });
    document.addEventListener('click', function (e) {
      if (menu.classList.contains('is-on') && !menu.contains(e.target) && e.target !== btn) close();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    window.addEventListener('scroll', close, true);
    window.addEventListener('resize', close);
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

  // ══ Role picker ══════════════════════════════════════════════════════════
  // The Role field is a single <select> (Filmmaker, Actor, Critic, …) — same
  // as before this taxonomy grew sub-roles. Picking the one option that has
  // children (currently Filmmaker) reveals its sub-role checkboxes; picking
  // anything else hides them and clears any that were checked, so switching
  // away doesn't silently submit a sub-role with no parent selected. Shared
  // by the onboarding gate and the dashboard's Account tab, since both render
  // the same select + [data-expands] markup from roles.php's taxonomy.

  function initRoleGroup() {
    $$('[data-role-select]').forEach(function (select) {
      // A generous fixed cap rather than a measured scrollHeight — the
      // dashboard's copy of this sits inside a tab panel that starts
      // display:none, where scrollHeight reads 0 regardless of content.
      function sync() {
        var opt = select.options[select.selectedIndex];
        var activeId = opt ? opt.getAttribute('data-expands') : null;
        $$('.role-sub').forEach(function (sub) {
          var on = sub.id === activeId;
          sub.classList.toggle('is-on', on);
          if (!on) $$('input[type="checkbox"]', sub).forEach(function (cb) { cb.checked = false; });
        });
      }
      sync();
      select.addEventListener('change', sync);
    });
  }

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
            // Signed in now, but with no name or role yet — ctx_state() calls
            // that 'onboard' and / shows the dedicated gate for it, the same
            // form this used to duplicate inline.
            location.href = '/';
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

  // A muted, controls-free mirror of the real playback in the card's own
  // thumbnail slot — same diff-from-showtime math startSync() uses, reimplemented
  // against a plain <video> rather than shared with it. The two players have
  // nothing else in common: this one has no controls to wire up, never
  // unmutes, and never waits for a pre-show start, so folding it into
  // startSync() would mean threading those differences through a function
  // that already works for the two players that do need them.
  function startCardPreview(el) {
    var showtime = window.CTX_SHOWTIME || 0,
        dur      = window.CTX_DUR || 0,
        filename = window.CTX_FILE || '';
    if (!el || !showtime || !filename) return;

    var diff = Math.floor(Date.now() / 1000 - showtime);
    if (diff < 0 || diff >= dur) return;   // not actually live

    el.preload = 'auto';
    el.src = '/motw/stream.php?f=' + encodeURIComponent(filename);

    function seek() {
      el.muted = true;
      el.currentTime = Math.floor(Date.now() / 1000 - showtime);
      var p = el.play();
      if (p && p.catch) p.catch(function () {});
    }
    if (el.readyState >= 1) seek();
    else el.addEventListener('loadedmetadata', seek, { once: true });

    setInterval(function () {
      var d = Math.floor(Date.now() / 1000 - showtime);
      if (d < 0 || d >= dur) return;
      if (Math.abs(Math.floor(el.currentTime) - d) > 2) el.currentTime = d;
    }, 3000);
  }

  function initTheatre() {
    var shell = $('#theatre');
    if (!shell) return;
    var started = false;

    var preview = $('#theatre-card-preview');
    if (preview) startCardPreview(preview);

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

  // ══ Profile — banner upload ══════════════════════════════════════════════
  // Same shape as the face uploader above, but the target is a background
  // image on the header itself rather than an <img>, so a fresh upload has
  // to also add .who--banner and the scrim — someone with no banner yet has
  // neither in the DOM at all until their first upload succeeds.

  function initProfileBannerUpload() {
    var input = $('#who-banner-input');
    var header = $('.who');
    if (!input || !header) return;

    input.addEventListener('change', function () {
      var file = input.files[0];
      if (!file) return;

      var reader = new FileReader();
      reader.onload = function (e) {
        header.style.backgroundImage = 'url(' + e.target.result + ')';
        header.classList.add('who--banner');
        if (!header.querySelector('.who__scrim')) {
          var scrim = document.createElement('div');
          scrim.className = 'who__scrim';
          header.insertBefore(scrim, header.firstChild);
        }
      };
      reader.readAsDataURL(file);

      var fd = new FormData();
      fd.append('image', file);
      fetch('/dashboard/profile.php?action=upload_banner', {
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

  // ══ Profile — role pill dropdown ═════════════════════════════════════════
  // Only Filmmaker ever renders one of these — the one tag with sub-roles to
  // reveal — but written to handle more than one without assuming that stays
  // true. No positioning math needed here: unlike the You menu, nothing on
  // this page clips or scrolls, so the panel is just normal flow below the
  // pill row rather than something reparented to <body>.

  // Reparented to <body> like the custom-select menu and the you-menu — left
  // in place, .pill-drop's own display:flex on .is-on sat in normal flow and
  // pushed .who__links down whenever it opened. Fixed position + closing on
  // scroll/resize (rather than repositioning) matches that same pattern.
  function initRoleDrop() {
    var triggers = $$('[data-role-toggle]');
    if (!triggers.length) return;

    var entries = triggers.map(function (btn) {
      var drop = document.getElementById(btn.getAttribute('data-role-toggle'));
      if (!drop) return null;
      document.body.appendChild(drop);
      return { btn: btn, drop: drop };
    }).filter(Boolean);

    function place(entry) {
      var r = entry.btn.getBoundingClientRect();
      entry.drop.style.left = Math.round(r.left) + 'px';
      entry.drop.style.top  = Math.round(r.bottom + 6) + 'px';
    }

    function closeAll() {
      entries.forEach(function (entry) {
        entry.drop.classList.remove('is-on');
        entry.btn.setAttribute('aria-expanded', 'false');
      });
    }

    entries.forEach(function (entry) {
      entry.btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var wasOpen = entry.drop.classList.contains('is-on');
        closeAll();
        if (!wasOpen) {
          place(entry);
          entry.drop.classList.add('is-on');
          entry.btn.setAttribute('aria-expanded', 'true');
        }
      });
    });

    document.addEventListener('click', closeAll);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAll(); });
    window.addEventListener('scroll', closeAll, true);
    window.addEventListener('resize', closeAll);
  }

  // ══ Instagram admin — poster crop ═══════════════════════════════════════
  // Adjust crop toggle/slider/save live outside the row's own <label> on
  // purpose (see css/v7.scss's .adm-crop-row comment) — nothing here can
  // accidentally flip the On the Carousel checkbox. object-position and
  // $vBias in
  // list/instagram.php share the same 0–100% top/bottom meaning, so the
  // live preview here matches what the saved PNG will actually crop to.

  function initPosterCrop() {
    $$('[data-crop-toggle]').forEach(function (btn) {
      var panel = btn.nextElementSibling;
      if (!panel) return;
      btn.addEventListener('click', function () {
        panel.classList.toggle('is-open');
      });
    });

    $$('[data-crop-slider]').forEach(function (slider) {
      var preview = slider.closest('[data-crop-panel]').querySelector('[data-crop-preview]');
      slider.addEventListener('input', function () {
        preview.style.backgroundPosition = '50% ' + slider.value + '%';
      });
    });

    $$('[data-crop-save]').forEach(function (btn) {
      var panel  = btn.closest('[data-crop-panel]');
      var slider = panel.querySelector('[data-crop-slider]');
      var status = panel.querySelector('[data-crop-status]');
      var form   = btn.closest('form');
      var csrf   = form ? form.querySelector('input[name="csrf"]') : null;

      btn.addEventListener('click', function () {
        btn.disabled = true;
        status.textContent = 'Saving…';
        status.classList.add('is-visible');

        post('/_admin/instagram_poster_crop.php', {
          poster_url: btn.getAttribute('data-poster-url'),
          bias: (parseInt(slider.value, 10) / 100).toFixed(2),
          csrf: csrf ? csrf.value : ''
        }).then(function (res) {
          btn.disabled = false;
          status.textContent = (res && res.ok) ? 'Saved' : 'Failed to save';
          setTimeout(function () { status.classList.remove('is-visible'); }, 1800);
        }).catch(function () {
          btn.disabled = false;
          status.textContent = 'Failed to save';
        });
      });
    });
  }

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
    // A profile's role tag links here as ?role=director — a sub-role with no
    // chip of its own still narrows the roster, it just won't light one up.
    var role   = new URLSearchParams(window.location.search).get('role') || 'all';

    function apply() {
      var term  = (search && search.value || '').trim().toLowerCase();
      var shown = 0;

      $$('.member', roster).forEach(function (m) {
        // A member can carry more than one tag now — data-role is a
        // space-separated list, so a filter matches if it's any one of them.
        var memberRoles = (m.getAttribute('data-role') || '').split(' ');
        var okRole = role === 'all'
                  || (role === 'saved' ? m.getAttribute('data-saved') === '1'
                                       : memberRoles.indexOf(role) !== -1);
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

    // Only matters when role didn't default to 'all' — a profile's role tag
    // deep-links here with ?role=, and the server-rendered roster doesn't
    // know to pre-hide anything for that.
    if (role !== 'all') apply();

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

  // ══ Film Forecast — generation progress ═════════════════════════════════
  // Polls while a background ffmpeg run is in flight (see
  // _admin/forecast_generate.php / bin/forecast-generate.php). A full page
  // reload once status leaves "running" is deliberate — this admin has no
  // client framework, every other action here already re-renders server-
  // side, and the finished view (video player, or the error banner) is
  // already what a fresh load of forecast_episode.php produces.
  function initForecastProgress() {
    var bar = $('[data-forecast-progress]');
    if (!bar) return;
    var label = $('[data-forecast-progress-label]');
    var episodeId = bar.getAttribute('data-episode-id');

    var timer = setInterval(function () {
      fetch('/_admin/forecast_progress.php?id=' + encodeURIComponent(episodeId), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.status === 'running') {
            var pct = data.percent || 0;
            bar.value = pct;
            if (label) label.textContent = 'Generating… ' + pct + '%';
          } else {
            clearInterval(timer);
            location.reload();
          }
        })
        .catch(function () {});
    }, 2000);
  }

  // ══ Film Forecast — chapter timeline ════════════════════════════════════
  // A waveform (decoded client-side from the episode's own audio — no
  // server rendering involved) with one draggable marker per selected
  // film. Dragging is pure client-side state until "Save chapters" posts
  // it; the storyboard preview above follows whichever marker is being
  // dragged so a placement can be sanity-checked without a real ~15-20
  // minute video render.
  function initForecastTimeline() {
    var section = $('[data-forecast-timeline]');
    if (!section) return;

    var audioUrl   = section.getAttribute('data-audio-url');
    var duration   = parseFloat(section.getAttribute('data-duration')) || 0;
    var episodeId  = section.getAttribute('data-episode-id');
    var csrf       = section.getAttribute('data-csrf');
    var introImage = section.getAttribute('data-intro-image');

    var dataEl = $('[data-forecast-chapters-data]');
    var initial = { chapters: [], chapterImages: {}, wrapupStart: null };
    try { initial = JSON.parse(dataEl.textContent); } catch (e) {}
    var chapterImages = initial.chapterImages || {};
    var wrapupStart = initial.wrapupStart;
    var chapters = (initial.chapters || []).map(function (c) {
      return { film: c.film, start: c.start, title: c.title };
    });

    var canvas       = section.querySelector('[data-forecast-waveform-canvas]');
    var waveformWrap = section.querySelector('[data-forecast-waveform-wrap]');
    var markersWrap  = section.querySelector('[data-forecast-markers]');
    var audioEl      = section.querySelector('[data-forecast-audio-player]');
    var storyboardImg = $('[data-forecast-storyboard-img]');
    var saveBtn      = section.querySelector('[data-forecast-chapters-save]');
    var dirtyChip    = section.querySelector('[data-forecast-timeline-dirty]');
    var statusEl     = section.querySelector('[data-forecast-chapters-status]');
    var generateForm = $('[data-forecast-generate-form]');

    var snapshot = function () {
      return JSON.stringify(chapters.map(function (c) { return [c.film, c.start]; }));
    };
    var savedSnapshot = snapshot();

    function markDirty() {
      var dirty = snapshot() !== savedSnapshot;
      if (saveBtn) saveBtn.disabled = !dirty;
      if (dirtyChip) dirtyChip.hidden = !dirty;
    }

    function formatTime(s) {
      s = Math.max(0, Math.round(s));
      var m = Math.floor(s / 60), sec = s % 60;
      return m + ':' + (sec < 10 ? '0' : '') + sec;
    }

    // The chapter that's "active" at a given moment — the last one whose
    // start is at or before it — same rule the video itself will use to
    // decide which card is on screen at any given time.
    function activeChapterAt(time) {
      var active = null;
      chapters.forEach(function (c) {
        if (c.start <= time && (!active || c.start > active.start)) active = c;
      });
      return active;
    }

    function updateStoryboard(time) {
      if (!storyboardImg) return;
      var active = activeChapterAt(time);
      var url = active ? chapterImages[active.film] : introImage;
      if (url && storyboardImg.getAttribute('src') !== url) storyboardImg.setAttribute('src', url);
    }

    function bindDrag(el, chapter, timeEl) {
      function move(e) {
        var rect = markersWrap.getBoundingClientRect();
        var x = Math.max(0, Math.min(rect.width, e.clientX - rect.left));
        var pct = rect.width > 0 ? x / rect.width : 0;
        chapter.start = Math.round(pct * duration);
        el.style.left = (pct * 100) + '%';
        timeEl.textContent = formatTime(chapter.start);
        updateStoryboard(chapter.start);
      }
      el.addEventListener('pointerdown', function (e) {
        el.setPointerCapture(e.pointerId);
        el.classList.add('is-dragging');
        move(e);
      });
      el.addEventListener('pointermove', function (e) {
        if (el.classList.contains('is-dragging')) move(e);
      });
      ['pointerup', 'pointercancel'].forEach(function (evt) {
        el.addEventListener(evt, function () {
          el.classList.remove('is-dragging');
          markDirty();
        });
      });
    }

    // One marker element, shared by the draggable per-film markers and
    // the fixed intro/wrap-up bookends — same visual pieces (label,
    // handle, time) either way, only whether bindDrag() gets called
    // differs.
    function buildMarker(start, title, extraClass) {
      var pct = duration > 0 ? Math.max(0, Math.min(100, (start / duration) * 100)) : 0;

      var el = document.createElement('div');
      el.className = 'fc-marker' + (extraClass ? ' ' + extraClass : '');
      el.style.left = pct + '%';

      var label = document.createElement('div');
      label.className = 'fc-marker__label';
      label.textContent = title;
      el.appendChild(label);

      var handle = document.createElement('div');
      handle.className = 'fc-marker__handle';
      el.appendChild(handle);

      var timeEl = document.createElement('div');
      timeEl.className = 'fc-marker__time';
      timeEl.textContent = formatTime(start);
      el.appendChild(timeEl);

      return { el: el, timeEl: timeEl };
    }

    function layoutMarkers() {
      markersWrap.innerHTML = '';

      // The intro is always chapter zero — fixed at the very start, never
      // draggable and never saved (forecast_resolve_chapters() only ever
      // produces one entry per selected film, nothing for the bookends).
      markersWrap.appendChild(buildMarker(0, 'Intro', 'fc-marker--fixed').el);

      chapters.forEach(function (c) {
        var m = buildMarker(c.start, c.title);
        bindDrag(m.el, c, m.timeEl);
        markersWrap.appendChild(m.el);
      });

      if (wrapupStart !== null && wrapupStart !== undefined) {
        markersWrap.appendChild(buildMarker(wrapupStart, 'Wrap-up', 'fc-marker--fixed').el);
      }
    }

    // A sibling of markersWrap, not a child of it — layoutMarkers()
    // rebuilds markersWrap's contents from scratch (innerHTML = ''), and
    // this needs to survive that.
    var playhead = null;
    if (waveformWrap && audioEl) {
      playhead = document.createElement('div');
      playhead.className = 'fc-playhead';
      var dot = document.createElement('div');
      dot.className = 'fc-playhead__dot';
      playhead.appendChild(dot);
      waveformWrap.appendChild(playhead);
    }

    function updatePlayhead(time) {
      if (!playhead || duration <= 0) return;
      var pct = Math.max(0, Math.min(100, (time / duration) * 100));
      playhead.style.left = pct + '%';
      playhead.classList.add('is-active');
      updateStoryboard(time);
    }

    if (audioEl) {
      var playheadRAF = null;
      function tickPlayhead() {
        updatePlayhead(audioEl.currentTime);
        playheadRAF = requestAnimationFrame(tickPlayhead);
      }
      audioEl.addEventListener('play', function () {
        if (playheadRAF === null) playheadRAF = requestAnimationFrame(tickPlayhead);
      });
      ['pause', 'ended'].forEach(function (evt) {
        audioEl.addEventListener(evt, function () {
          if (playheadRAF !== null) { cancelAnimationFrame(playheadRAF); playheadRAF = null; }
          updatePlayhead(audioEl.currentTime);
        });
      });
      // Covers a seek made while paused (dragging the native scrubber) —
      // the RAF loop above only runs during actual playback.
      audioEl.addEventListener('seeked', function () { updatePlayhead(audioEl.currentTime); });

      // Click the waveform itself to jump playback there — markers sit
      // in their own layer (markersWrap) above the canvas and handle
      // their own pointer events, so a click that lands on one never
      // reaches this listener.
      canvas.addEventListener('click', function (e) {
        var rect = canvas.getBoundingClientRect();
        var pct = rect.width > 0 ? Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width)) : 0;
        audioEl.currentTime = pct * duration;
        updatePlayhead(audioEl.currentTime);
      });
    }

    function drawWaveform(peaks) {
      var dpr = window.devicePixelRatio || 1;
      var rect = canvas.getBoundingClientRect();
      canvas.width = Math.max(1, Math.round(rect.width * dpr));
      canvas.height = Math.max(1, Math.round(rect.height * dpr));
      var ctx = canvas.getContext('2d');
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, rect.width, rect.height);
      var barW = rect.width / peaks.length;
      var midY = rect.height / 2;
      ctx.fillStyle = '#C9C2B4';
      peaks.forEach(function (p, i) {
        var h = Math.max(2, p * rect.height * 0.85);
        ctx.fillRect(i * barW, midY - h / 2, Math.max(1, barW - 1), h);
      });
    }

    var cachedPeaks = null;

    function loadWaveform() {
      if (cachedPeaks) { drawWaveform(cachedPeaks); return; }
      if (!audioUrl) return;
      var AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) return;
      fetch(audioUrl, { credentials: 'same-origin' })
        .then(function (r) { return r.arrayBuffer(); })
        .then(function (buf) { return new AudioCtx().decodeAudioData(buf); })
        .then(function (audioBuffer) {
          var raw = audioBuffer.getChannelData(0);
          var samples = 260;
          var blockSize = Math.max(1, Math.floor(raw.length / samples));
          var peaks = [];
          for (var i = 0; i < samples; i++) {
            var start = i * blockSize, sum = 0;
            for (var j = 0; j < blockSize; j++) sum += Math.abs(raw[start + j] || 0);
            peaks.push(sum / blockSize);
          }
          var max = Math.max.apply(null, peaks) || 1;
          cachedPeaks = peaks.map(function (p) { return p / max; });
          drawWaveform(cachedPeaks);
        })
        // Fetched and decoded once, cached for any later redraw (a
        // window resize shouldn't re-download and re-decode the whole
        // audio file just to redraw the same bars at a new canvas size).
        // Decoding can also fail outright (unsupported codec, browser
        // quirk) — markers and the storyboard preview work off duration
        // alone, so this is
        // a degraded-but-functional state, not something to surface as
        // an error.
        .catch(function () {});
    }

    if (saveBtn) {
      saveBtn.addEventListener('click', function () {
        saveBtn.disabled = true;
        if (statusEl) statusEl.textContent = 'Saving…';
        post('/_admin/forecast_chapters_save.php', {
          episode_id: episodeId,
          csrf: csrf,
          chapters: JSON.stringify(chapters.map(function (c) { return { film: c.film, start: c.start }; }))
        }).then(function (res) {
          if (res && res.ok) {
            savedSnapshot = snapshot();
            if (statusEl) statusEl.textContent = 'Saved';
          } else if (statusEl) {
            statusEl.textContent = 'Failed to save';
          }
          markDirty();
          setTimeout(function () { if (statusEl) statusEl.textContent = ''; }, 1800);
        }).catch(function () {
          if (statusEl) statusEl.textContent = 'Failed to save';
          saveBtn.disabled = false;
        });
      });
    }

    // Generate always uses exactly what the timeline is currently
    // showing, saving it as part of launching — same reasoning the film
    // checklist's own Generate wiring already follows.
    if (generateForm) {
      generateForm.addEventListener('submit', function () {
        var input = generateForm.querySelector('[data-forecast-chapters-input]');
        if (input) {
          input.value = JSON.stringify(chapters.map(function (c) { return { film: c.film, start: c.start }; }));
        }
      });
    }

    layoutMarkers();
    updateStoryboard(0);
    updatePlayhead(0);
    loadWaveform();
    window.addEventListener('resize', function () { loadWaveform(); });
  }

  function boot() {
    initWelcome(); initTheme(); initThemeSwitcher(); initRail(); initList();
    initOverlays(); initComposer(); initNotes(); initJoin();
    initCopyLink(); initDirectory(); initTheatre(); initScreening();
    initHovercard(); initImageCycle(); initCustomSelect();
    initProfileFaceUpload(); initProfileBannerUpload(); initLetterboxdConnect(); initYouMenu();
    initRoleGroup(); initRoleDrop(); initPosterCrop(); initForecastProgress(); initForecastTimeline();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();

})();
