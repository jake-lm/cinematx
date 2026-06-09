# CinemaTX — Project Notes

## What This Is
A PHP/MySQL website for a pseudo-cinema streaming experience. Users visit theatre pages (`/th1`, etc.) and watch films synced to a universal showtime schedule. Includes a member directory, admin panel, and header with sign-in.

---

## Compile Pipeline
**Always run after editing `sass.scss`:**
```
sass css/sass.scss css/sass.css --no-source-map
```
Then bump the CSS cache buster (`?v=N`) in the relevant page's `<link>` tag.

---

## Key Files

| File | Purpose |
|------|---------|
| `css/sass.scss` | Master stylesheet — edit this, never `sass.css` directly |
| `css/sass.css` | Compiled output |
| `header.php` | Shared nav menu (included on all pages) |
| `index.php` | Homepage — welcome message, directory panel, sign-in logic |
| `th1/index.php` | Theatre 1 page — queries DB for current/next showtime, renders video player |
| `js/script-jlm.js` | Main JS — `theatre_1()` sync function, menu toggle, quote rotator |
| `js/script.js` | Legacy JS (mostly untouched) |
| `motw/stream.php` | PHP video streaming handler — serves `.mp4` with HTTP range support (206 Partial Content) for seeking |
| `_admin/showtime.php` | Admin: add showtimes — uses `America/Chicago` timezone explicitly |
| `database.php` | PDO connection + `date_default_timezone_set('America/Chicago')` |

---

## Cache Busters
Removed for development. When deploying to production, add `?v=<?php echo filemtime(...); ?>` to CSS/JS links — it auto-updates on recompile with no manual work.

---

## Design System
- **Background:** `#2a2a2a` (body), `#4A4A4A` (left menu), `#141414` (video info panel)
- **Accent red:** `#922E32`
- **Top bar:** `#922e32` with logo image
- **Text:** `#e2e2e2` primary, `#999` secondary, `#555` tertiary
- **Fonts:** Bebas Neue (menu), Montserrat (headings/labels), Roboto (body)
- **Left menu width:** 200px fixed; content offset `left: 200px`
- **Top bar height:** 50px fixed; content offset `top: 50px`

---

## Theatre Sync (`theatre_1()` in script-jlm.js)
The function takes `(showtime, dur, filename)` from PHP-injected `onload` params.

Flow:
1. Set video source to `/motw/stream.php?f=<filename>` (range-request handler, required for seeking)
2. Wait for `loadedmetadata`
3. Calculate `diff = now - showtime` (seconds elapsed)
4. If pre-show: park at 0, poll every 5s to reload when showtime arrives
5. If playing: `muted(true)` → `currentTime(diff)` → wait for `seeked` → `play()`
6. On muted autoplay success: inject unmute nudge button into `.video-hold`
7. Every 3s: re-sync if drift > 2s; snap back on any scrub attempt

**Why `stream.php` exists:** PHP's built-in dev server returns `200 OK` for range requests instead of `206 Partial Content`, which blocks video seeking. `stream.php` handles `Range:` headers properly.

---

## Database Tables (relevant)
- `showtimes` — `id`, `f_id`, `showtime` (unix timestamp), `endtime`, `theatre`
- `films` — `id`, `title`, `director`, `dur` (seconds), `filename`, `poster`, `wiki`, `program`
- `notes` — `f_id`, `note`

Showtimes stored in Unix time, `America/Chicago` timezone. `_admin/showtime.php` uses `DateTime::createFromFormat(..., new DateTimeZone('America/Chicago'))`.

---

## CSS Specificity Notes
- General `.home-base .content-block-w .thelist .entry` styles live late in the file and will override community panel dark styles unless countered.
- Community/Directory panel inner styles use `#community-panel` ID selector (+ `!important` on bg/color/shadow) to win specificity fights.

---

## Deferred Ideas
- **Theatre curtain animation** — on hover over `.motw .banner`, two curtain divs (`.curtain-l`, `.curtain-r`) drop from `top: -100%` to `top: 0` in deep red gradient, followed by the overlay fading in. Pure CSS. Was prototyped and reverted — revisit when ready.

---

## Active TODOs
- [ ] **Social icons (Discord/Instagram)** — added to `header.php` as FA icons in `.menu .social`, visible fix attempted (color added to `.social a`) but user still couldn't see them — may need further investigation
- [ ] **Sign-in SQL injection** — `dashboard/signup.php` login uses string interpolation; needs parameterized queries. Deferred by user.
- [ ] **Console.log cleanup** — `[th1]`-prefixed debug logs in `script-jlm.js` should be removed before production
- [ ] **Firefox admin scroll fix** — deferred
- [ ] **Mobile `/th1` optimization** — deferred
- [ ] **Email verification on signup** — deferred
- [ ] **Discord/Instagram real URLs** — social icon hrefs are currently `#`

---

## Conventions
- PHP sessions for auth: `$_SESSION['username']`
- Error param: `$_GET['error'] ?? null` pattern used in `index.php`
- Video files live in `/motw/` directory alongside `stream.php`
- Poster images: `/motw/<poster>.png`
