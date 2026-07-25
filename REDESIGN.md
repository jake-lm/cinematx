# Cinema, TX — UI v2

Analysis of the existing interface, the direction chosen, and what has been
built so far.

**Status:** analysis complete; reference pages built at `/redesign/`.
The live site is untouched. Snapshot of v1 is the `v1-ui` tag and the
`legacy-ui` branch.

---

## Part 1 — What the site already is

Two years of accretion produced a real design philosophy. It's worth naming
before changing anything, because most of it is good and the redesign's job is
to serve it rather than replace it.

### The panel is the atom

Nearly every piece of the site is a box: `.content-box`, `.community-panel`,
`.post-panel`, `#signup-panel`, `.landing-screenings-panel`, `.feed-card`,
`.profile-card`, `.quick-post-box`, `.letterboxd-panel`, `.list-card`. The site
thinks modularly, and it always has. That is the identity.

### Panels perform

The signature move is the reveal. A collapsed 125px header expands on click via
a `max-height` transition; hover fades in a colour wash, wipes a background bar
in behind the title (`transform: scaleX(0)` → `1`), lifts a subtitle into view.
Nothing simply appears — it unveils.

For a cinema site this is exactly right, and it is the best idea in the
codebase. The deferred "curtain animation" note in `CLAUDE.md` is the same
instinct.

### The theatre is the spine

`.motw` pins to the right edge, shows a poster, reveals showtimes on hover, and
sweeps open into a full player. The marquee metaphor runs through the whole
thing — `marquee-blink`, `.stamp`, the overlay.

### Fixed chrome, content between

200px nav left, 50px bar top, content in the middle. Stable and legible.

---

## Part 2 — Where it came apart

The problems are almost entirely **consistency**, not judgement. The same good
idea got re-implemented instead of reused.

### 1. The panel exists four times

`.content-box`, `.community-panel`, `.post-panel`, and `#signup-panel` each
independently rebuild the same component — a 125px header, `.lower-bar .txt`
title, `.info-txt` metadata, `.bg-gradient` wash, expand-on-click, hard offset
shadow. Roughly 400 lines of near-duplicate CSS.

And they drifted. Title sizes 22px vs 16px. Metadata offsets `top: 25px` vs
`top: 10px`. Title colours `#444` vs `#666`. Nothing is *wrong* in isolation;
together they never quite line up. **This is the single largest source of the
"frankensteined" feeling.**

### 2. The fonts were never actually loading

The most consequential finding.

`font-family: 'Montserrat'` appears **92 times**. `font-family: 'Roboto'`
appears **19 times**. The `@import` block loads `Montserrat:300` — weight 300
only — and **never loads Roboto at all** (it loads *Roboto Condensed*, a
different family).

So every `font-weight: 600 / 700 / 900` on Montserrat was being **synthesised
by the browser** — smeared, not a real bold cut. And every element asking for
Roboto was silently falling back to the system sans.

The site as rendered was substantially not the site as designed. A large share
of the "inconsistent" feel traces back here.

Meanwhile six families were imported (Montserrat, Open Sans, Open Sans
Condensed, Oswald, Roboto Condensed, Bebas Neue, Signika, Courier Prime) and
only two were meaningfully used.

### 3. No tokens — 17 greys and 5 reds

Every value is a hardcoded literal. Greys in use: `#141414 #161616 #1a1a1a
#1c1c1c #2a2a2a #333 #3a3a3a #444 #4A4A4A #555 #666 #777 #888 #999 #aaa #bbb
#c0c0c0 #c2c2c2 #c8c8c8 #d8d8d8 #e2e2e2 #f2f2f2 #f4f4f4`.

Reds that are all trying to be the brand red: `#922E32`, `#e03030`, `#c94a50`,
`#a83338`, `#b03338`, `firebrick`. Accents drift between elements that should
match.

### 4. A z-index arms race

`999, 1000, 1001, 1002, 1003, 1010, 2000, 2998, 2999, 9999` — no scale.
`.main-content` itself sits at 1001, so children escalate to 1010 just to
escape their own parent.

### 5. Specificity fights

`.main-content .home-base .content-block-w .thelist .entry` is five levels deep,
which forces `#community-panel … !important` counter-rules to win. `CLAUDE.md`
documents this battle as if it were a feature of the codebase.

### 6. Dead weight

`.upper-bar`, `.since-txt`, `.bg-photo`, `.bg-video`, `.bg-video-still`,
`.bg-video-poster`, `.uBorder`, `.hover-infoAdd`, `.main-piece`, the `.red` /
`.blue` variant classes, `-moz-linear-gradient` + IE `filter:` fallbacks,
`zoom: 200%`. Plus `#motw { width: 640; height: 360; }` — missing units, so
both declarations are invalid and discarded.

---

## Part 3 — Where design has actually gone

"Modern" points in several directions, and one of them is a trap.

The dominant contemporary look — enormous whitespace, one sentence per screen —
is a **SaaS marketing** idiom. It exists to sell a product to a skimming
visitor. Applied to a site with actual reading on it, it just makes people
scroll more to get less.

The sites this project is genuinely adjacent to — Criterion, MUBI, Letterboxd,
Le Cinéma Club — are **not** especially airy. They are fairly dense. What makes
them read as modern is:

- **Typographic discipline.** Two families, a strict scale, real weights.
- **Consistent rhythm.** One spacing system, applied everywhere.
- **Depth as feedback, not decoration.** Surfaces respond to you rather than
  sitting permanently raised.
- **Content-forward hierarchy.** The work is the largest thing on screen.
- **Restraint with accent colour.** One accent, used sparingly, so it means
  something when it appears.

None of that requires more empty space. It requires *deliberate* space.

**The happy medium: keep the site's density and modularity, replace the
accumulation with a system.**

---

## Part 4 — The v2 system

Built in `css/redesign.scss`. Every value comes from a token; nothing is
hardcoded twice.

### Colour

Anchored on the two colours that were declared non-negotiable — `#2a2a2a` and
`#141414` — with even steps between them rather than accumulated ones.

| Token | Value | Role |
|---|---|---|
| `--ink-950` | `#101011` | video chrome, deepest wells |
| `--ink-900` | `#141414` | theatre panel *(retained)* |
| `--ink-850` | `#191919` | raised card |
| `--ink-800` | `#1e1e1f` | panel body |
| `--ink-700` | `#2a2a2a` | page *(retained)* |
| `--ink-400` | `#4a4a4a` | left nav *(retained)* |

Text collapses from ~17 greys to five: `--text-hi`, `--text`, `--text-mid`,
`--text-low`, `--text-faint`.

The five stray reds collapse into one ramp around the unchanged brand red:
`--accent: #922E32`, plus `--accent-hi`, `--accent-lo`, `--accent-wash`,
`--accent-line`. The existing `#3c5a77` blue is promoted to `--cool` for
review-typed content.

### Type

Two families, both **actually loaded, at the weights the CSS asks for**.

- **Archivo** — UI, nav, labels, metadata, body. A grotesque with slightly
  condensed proportions; reads as marquee lettering at heavy weights without
  being a costume face. Loaded 400/500/600/700.
- **Newsreader** — headlines, display, and long-form prose. An editorial serif
  drawn for screens with real optical sizing. Loaded 300/400/500/600 + italics.

Bebas Neue is retired. It carries no lowercase glyphs at all, which is what
forced the nav off it earlier — the constraint isn't worth keeping.

Sizes come from one ~1.22 modular scale, `--fs-2xs` (10px) through `--fs-3xl`
(42px).

### Space, depth, motion

4px base scale, `--s-1` … `--s-9`. Editorial breathing room without the
acreage.

**Depth is inverted.** The hard offset shadow was the site's signature but sat
on every surface at rest, which read as heavy — and was rejected outright on
the landing panel. Now panels are **flat at rest** (surface + hairline border)
and **lift toward you on interaction** (`--lift`, `--lift-lg`). The signature
survives as a response rather than permanent weight.

Motion: four durations (`--t-fast` … `--t-reveal`) and one easing curve, plus a
`prefers-reduced-motion` guard.

**Z-index becomes six named tiers** — `--z-base` (1) through `--z-modal` (500),
replacing the 999→9999 scramble.

### One panel component

`@mixin panel`, `@mixin panel-interactive`, `@mixin panel-head($h)`. The four
duplicate implementations collapse into one definition that the signup panel,
screenings panel, and post panels all draw from.

---

## Part 5 — What was built

Reference pages at **`/redesign/`** — the homepage in **both states**, plus the
nav and the theatre rail.

| File | Purpose |
|---|---|
| `css/redesign.scss` → `.css` | The v2 system. Parallel to `sass.scss`. |
| `redesign/index.php` | Reference homepage, logged-in and logged-out |
| `redesign/header.php` | v2 nav + top bar + quick-create sidebar |
| `js/redesign.js` | Small shim; mobile nav only |

### Behaviour is untouched

The new stylesheet targets **the same class vocabulary the existing JS binds
to**. `script-jlm.js` is loaded unmodified and drives everything as before —
theatre expand, signup reveal, poster/list toggle, composer, feed, featured
expand. The redesign is a visual layer, not a rewrite of proven behaviour.

`js/redesign.js` exists only because the legacy mobile nav wrote inline `right`
values onto `.menu`, which fights a nav that now slides from the left.

### Verified

- Both states render against live data, no PHP notices or warnings
- Archivo and Newsreader confirmed loading at real weights in-browser
- Signup panel expand: header collapses 132→56px, body reveals, lift applies
- Mobile: nav slides correctly, content stacks, rail unpins to the page end
- Post panels navigate rather than expand — matching live behaviour, where
  `data-url` short-circuits the handler at `script-jlm.js:257`

### Fixed in passing

- **Social icons.** A standing complaint in `CLAUDE.md` — they were set at a
  grey barely distinguishable from the nav behind them. Contrast raised.
- **Three "SHARE" labels in a row** on the featured hero, now icon-only.
- **Mobile welcome text** collided with the top-bar buttons; the quote rotator
  writes `display:inline` inline, so it needed `!important` to hide.

---

## Part 6 — Open questions

1. **Rollout order.** The remaining pages — The List, dashboard, directory,
   profiles, post pages, `/th1` — are unconverted and still on `sass.scss`.
   Suggest The List next: it is the most-visited page and the most poster-heavy,
   so it exercises the system hardest.

2. **Data duplication.** `redesign/index.php` copies the theatre query block
   from `index.php`. When this lands for real, that block should move to a
   shared include rather than living in two places.

3. **The `.motw` rail on interior pages.** It is currently homepage-only. Worth
   deciding whether the marquee follows the reader everywhere.

4. **Font hosting.** Archivo and Newsreader load from Google Fonts, matching
   how the site already loads type. Self-hosting would remove the third-party
   request if that matters later.
