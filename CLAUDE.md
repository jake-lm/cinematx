# CinemaTX — Project Notes

## What This Is
A PHP/MySQL website for a pseudo-cinema streaming experience. Users visit theatre pages (`/th1`, etc.) and watch films synced to a universal showtime schedule. Includes a member directory, admin panel, and header with sign-in.

---

## Compile Pipeline
**Always run after editing `v7.scss`:**
```
sass css/v7.scss css/v7.css --no-source-map
```
No cache buster to bump — `_head.php` appends `?v=<filemtime>` automatically.

`css/sass.scss` is the **old** stylesheet. Nothing loads it any more; the v7
conversion is complete. Don't edit it.

---

## Key Files

| File | Purpose |
|------|---------|
| `css/v7.scss` | Master stylesheet — edit this, never `v7.css` |
| `js/v7.js` | All front-end behaviour, vanilla, no jQuery |
| `v7/_lib.php` | Bootstrap: `ctx_e()`, `ctx_me()`, `ctx_state()`, `ctx_theatre()`, `ctx_journal()`, `ctx_members()` |
| `v7/_head.php` `_chrome.php` `_foot.php` | The shell — set `$ctx_title`, `$ctx_scroll`, `$ctx_video`, `$ctx_overlay`, `$ctx_shell`, `$ctx_meta`, `$ctx_scripts` before including |
| `v7/_screening.php` | Shared body of `/th1/` and `/th2/`; they set `$ctx_screen` and include it |
| `v7/screenings.php` | Title cleaning, series parsing, `ctx_bits()`, `ctx_hover()` |
| `list/tmdb.php` | TMDB lookup — poster, year, runtime, plot, genres, director, Wikipedia link. Versioned cache (`TMDB_CACHE_V`) |
| `list/cache.php` | `ctx_cached_scrape()` — TTL, locking, stale-beats-empty |
| `bin/warm-cache.php` | Cron entry point. CLI only |
| `_admin/_guard.php` | Admin auth + CSRF. Included first by every `_admin/` file |
| `motw/stream.php` | Video streaming with HTTP range support (206) for seeking |
| `database.php` | PDO connection, timezone, and the central error policy |

`index.php` is a three-line shim to `v7/index.php`. The `v7/` partials will be
flattened into the project root eventually.

---

## Design System (v7)
Tokens live at the top of `v7.scss`. Paper is the default; dark is an override
under `:root[data-theme="dark"]`.

- **Paper:** `--bg #F4F1EB`, `--surface #FBF9F5`
- **Accent red:** `--red #922E32` (unchanged from v1 — it is sacred)
- **Theatre:** `--dark #14120F`, `--dark-ink #F2EEE5`, `--dark-red #C4494E`.
  These are **fixed and never flip with the theme** — a screening room is dark
  in both. `.app--screening` remaps the whole shell onto them.
- **Fonts:** Fraunces (display), Newsreader (prose), Instrument Sans (UI)

The design brief, and what each of v1–v7 taught, is in **DESIGN.md**. The core
finding: claustrophobia came from simultaneous competing claims on attention,
not from tight spacing. Hierarchy is the fix — one loud element, everything
else compact and muted.

---

## Theatre Sync (`startSync()` in `js/v7.js`)
Reads `window.CTX_SHOWTIME`, `CTX_DUR`, `CTX_FILE`, injected by `_head.php`
when `$ctx_video` is set. One implementation drives both the front-page overlay
and the standalone `/th1/` `/th2/` pages.

1. `player.preload('auto')` — **required.** The markup says `preload="none"` so
   the homepage doesn't pull a 250MB film just for having the card on screen,
   but then the browser fetches nothing and `loadedmetadata` never fires.
2. Set source to `/motw/stream.php?f=<filename>` (range handler, needed to seek)
3. `diff = now - showtime`; return if pre-show or past the end
4. `muted(true)` → `currentTime(diff)` → wait for `seeked` → `play()`
5. On muted autoplay, inject an unmute nudge
6. Every 3s, correct drift over 2s; snap back on any scrub

**Why `stream.php` exists:** PHP's dev server returns `200 OK` for range
requests instead of `206 Partial Content`, which blocks seeking.

---

## Database Tables (relevant)
- `showtimes` — `id`, `f_id`, `showtime` (unix), `endtime`, `theatre`
- `films` — `id`, `title`, `director`, `dur` (seconds), `filename`, `poster`, `wiki`, `program`, `active`, `motw` (dead)
- `notes` — `f_id`, `note`, `stamp`
- `events` — member-submitted screenings: `title`, `poster`, `screentime`, `location`, `address`, `active`. No description column.
- `users` — `admin` gates `/_admin/`; `active` gates sign-in

Showtimes are Unix time, `America/Chicago`.

---

## Deferred Ideas
- **Theatre curtain animation** — on hover over `.motw .banner`, two curtain divs (`.curtain-l`, `.curtain-r`) drop from `top: -100%` to `top: 0` in deep red gradient, followed by the overlay fading in. Pure CSS. Was prototyped and reverted — revisit when ready.

---

## Security posture (as of 2026-07-27)
Done, don't re-open:
- `/_admin/` is gated by `_admin/_guard.php` — session + `users.admin` flag, 404 on denial, CSRF token on every write.
- Error display is centralised in `database.php` behind `CTX_DEBUG` (config.php). **Never set it true on a public host.** Errors always log.
- `dashboard/signup.php` (`activateacct`, `updateprof`, `login`) is parameterised; uid always comes from the session, never POST.
- `function.php` reduced to `addto`/`removefrom`; the injectable search/paging branches and `entries.php` are gone.
- `motw/stream.php` and `_admin/delete.php` use `basename()` before touching the filesystem.

## Deploying
See **DEPLOY.md**. The two steps that matter most and are easiest to skip:
`AllowOverride All` (or `.htaccess` is ignored silently, including the deny
rules) and running `php bin/warm-cache.php` once before announcing the site.

## Active TODOs
- [ ] **Social icons (Discord/Instagram)** — added to `header.php` as FA icons in `.menu .social`, visible fix attempted (color added to `.social a`) but user still couldn't see them — may need further investigation
- [ ] **Firefox admin scroll fix** — deferred
- [ ] **Mobile `/th1` optimization** — deferred
- [ ] **Email verification on signup** — deferred
- [ ] **Discord/Instagram real URLs** — social icon hrefs are currently `#`
- [ ] **TMDB match override map** — "MIRROR" resolves to *Mirror Mirror* (2012) across poster, runtime and Wikipedia link. A one-word title with nothing to disambiguate on; needs a small manual override table.
- [ ] **Retire `script.js` / `script-jlm.js`** — nothing loads them any more; the v7 conversion is complete and `startSync()` in `v7.js` supersedes `theatre_1()`. Safe to delete once you're happy.
- [ ] **Rewrite `dashboard.js` / `quick-create.js`** in vanilla — ~1,200 lines of jQuery, the last of the old front-end.
- [ ] **Drop `films.motw`** — dead column, still written as 0 on upload.

---

## Conventions
- PHP sessions for auth: `$_SESSION['username']`
- Error param: `$_GET['error'] ?? null` pattern used in `index.php`
- Video files live in `/motw/` directory alongside `stream.php`
- Poster images: `/motw/<poster>.png`
