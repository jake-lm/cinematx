# Cinema, TX — Design Brief & Accumulated Learnings

Living document. Current as of **v7**.
(`REDESIGN.md` is the v2-era analysis — superseded, kept for history.)

---

## Where we are

| | Branch | URL | Verdict |
|---|---|---|---|
| v1 | `legacy-ui`, tag `v1-ui` | `/` | The original. Still live and untouched. |
| v2 | `redesign-v2` | `/redesign/` | Too conservative — "a very small step" |
| v3 | `redesign-v3` | `/v3/` | Brutalist. **Claustrophobic** |
| v4 | `redesign-v4` | `/v4/` | Contained journal. "Prebuilt WordPress site" |
| v5 | `redesign-v5` | `/v5/` | Broadsheet, one viewport. **Claustrophobic again** |
| v6 | `redesign-v6` | `/v6/` | Modern app. Hierarchy solved |
| v7 | `redesign-v7` | `/v7/` | **The List leads.** Poster grid, view modes |

Every version is preserved. The live site has never been modified.

---

## The core diagnosis

**Claustrophobia is not a spacing problem. It is simultaneous competing claims
on attention.**

This took three attempts to get right. The evidence:

- v3 and v5 both went edge-to-edge with every element at equal visual weight.
  Five things all asking to be read at once.
- v4 did not trigger it — not because it had more air, but because it only
  asked for attention in one place at a time.

Spacing elements further apart does not fix it. It spreads the shouting.

**The fix is hierarchy.** One loud thing, everything else small, muted and
subordinate. Peripheral presence is not competing presence.

This is also why **modern app** is the right register and **newspaper** is the
wrong one. A front page deliberately gives many items competing weight — that
is what a front page *is*. An app deliberately does not.

### The measurable version

In v6: lead headline **34px**, base **13px**. That ~2.6× gap is what does the
work. Because the parts are small, real empty space is left over — and that
leftover space is what actually kills the overwhelm.

### What v1 already had right

The original site solved this. Quiet narrow left nav, quiet theatre rail
pinned to the edge, content in the middle. Chrome recessive, one thing focal.
v3–v5 promoted everything to focal and threw that away. v6 returns to it.

---

## Comfort and atmosphere

These are **two different problems** and were being treated as one.

**Comfort is subtractive** — removing friction. No overwhelm, no hunting,
predictable, legible, forgiving. Every version since v3 has been fighting this
battle; v6 largely won it.

**Atmosphere is additive** — putting presence in. Signs of life, ritual,
warmth, a sense of *somewhere* rather than *something*. Barely started.

The goal is a place people visit daily and stay in — not only for the
information, but for the feeling of being there.

### The governing principle

> **Comfort in the mechanics. Personality in the atmosphere.**
> Never make something harder to use in order to seem distinctive.

This resolves the apparent conflict between "don't look generic" (the v4 note)
and "be comfortable" (this one). Screen Slate resolves it the wrong way — it
buys uniqueness with friction, and reads as clunky and confusing as a result.
Get distinctiveness from what the site *is*, not from an unusual interface.

### Atmosphere assets already owned

- **The synced screening.** Everyone watching the same thing at the same time
  is a real daily ritual and a reason to be here *now*. No listings site can
  copy it. Currently under-used — a small card.
- **The programmer's note.** `notes.note` + `films.program` — a named human
  saying why tonight's film is on. The warmest content in the database.
  **v6 dropped it entirely**; v4 and v5 showed it. Restore it.
- **The multilingual welcome.** Pure warmth, zero function. Protect it.
- **The room.** Statuses are the beginning of a presence layer.

### Missing

- **Presence.** Nobody can tell anyone else is here. No "3 in the theatre",
  no sense of live company.
- **Return reward.** Nothing is meaningfully different because you came back.
- **Ritual beyond the showtime.** The showtime is the anchor; nothing else
  gives a reason to arrive at a particular moment.

---

## Locked decisions

Settled across sessions. Do not relitigate without a reason.

**Identity**
- Accent is **`#922E32`**, reclaimed after being given freedom to drop it.
  On dark it needs a lifted variant (`--red-hi`) for legible text.
- **Paper is the default; dark is a real option.** (Reversed at v7 — v6 had
  this the other way round.)
- The **theatre is always dark**, in both modes, on tokens that never flip.
- The **multilingual welcome** stays. Ten languages, ten-second cadence.
  It is the one piece of interface that simply has personality.
- **"Cinema, TX" gets emphasis** — but through scale and letterform, held at
  a muted tone. Presence without contrast. Never absent from the screen.

**Structure**
- **Left rail + top bar.** Both. Rail collapsible.
- Utility (sign in, join, account) lives in **chrome, never in the page**.
- Content is **contained** — the canvas caps out so wide screens gain margin
  rather than sprawl. Full-bleed has failed every time it was tried.
- **Everything above the fold.** The page does not scroll. Panels scroll
  inside themselves. This is a hard requirement, stated three times.
- The index carries **a taste of the entire site** — cinema, list, journal,
  room, members. Also stated three times.

**Content model**
- Weight order is **1 The List · 2 The Journal · 3 The Theatre · 4 The Room**
  (settled at v7; supersedes the earlier "journal earns the most room").
  Nothing may be isolated — everything stays represented above the fold.
- The list is a **social surface, not a timetable**. Member-submitted
  screenings sit in the same chronological stream, visibly attributed.
- Every screening for the window is **on the front page**. The separate
  `/list/` page is optional, never mandatory.
- The **social layer is prominent** — statuses, the room, who's here.

**Depth**
- Reached through **overlays that blur the page behind them**. Reading in full
  never costs you your place.

---

## What each version taught

**v2** — Preserving the existing philosophy too faithfully produces a tidy-up,
not a redesign. Also surfaced the biggest technical finding of the project:
the original stylesheet asked for Montserrat 600/700/900 while importing only
weight 300, and referenced Roboto 19 times without ever loading it. The site
as rendered was substantially not the site as designed.

**v3** — Brutalism and full-bleed are exhausting. Confirmed dropping the
original panel-shadow and Bebas Neue was fine. The zine/riso register is
usable but only as an accent.

**v4** — Containment works. Paper works. Serif for prose works. But a centred
column with a right sidebar and stacked full-width sections reads as a stock
template no matter how well it's typeset. Skeleton matters more than surface.

**v5** — Above-the-fold is achievable, and the marquee-letterboard idea for
the list is good. But packing five equal-weight zones into one screen
reproduces the v3 problem exactly.

**v6** — Hierarchy plus app-compact scale resolves the tension between
"everything above the fold" and "not overwhelming". This is the direction.

**v7** — The list can lead without becoming a wall, because posters carry
weight without demanding to be read. Also: check the data before adopting
another site's mechanic — multi-showtime collapsing is a Screen Slate win that
does not transfer to a repertory city.

---

## v7 — weight order

Settled ranking: **1 The List · 2 The Journal · 3 The Theatre · 4 The Room.**

The reason the list leads is **cadence**. Screenings change every day; essays
change weekly at best. The thing that makes the site a daily habit has to be
the thing that is different every day. The journal is the reason to stay once
you arrive, not the reason to arrive.

**Why a list can lead without becoming a wall:** posters carry visual weight
without demanding to be *read*. A grid of artwork dominates the page while the
journal stays readable beside it — they dominate on different axes. That is
what lets a four-level hierarchy sit above the fold without reproducing the
v3/v5 problem.

Mechanics decided:
- **View modes** — poster grid (default) and compact rows, persisted.
- **Venue filter** — chips. Austin has three venues, so *grouping* by venue
  buys little but *filtering* is genuinely useful (AFS is 65% of listings).
- **Every screening is in the DOM.** The panel scrolls internally, so nothing
  hides behind a required navigation and the page still never scrolls. The
  `/list/` page is optional, never mandatory.
- **Series parsing.** Venue titles bake the programme into the title
  (`Discovery Zone: MIRACLE MILE`). Stripping it took poster coverage from
  89% to **100%** and recovered the series as real metadata. TMDB arbitrates
  the ambiguity — a prefix is only treated as a series when the raw title
  failed to match and the cleaned one succeeded, so a genuine colon title like
  `BLACK IS BEAUTIFUL: THE KWAME BRATHWAITE STORY` is left alone.

Rejected after checking the data: **multi-showtime collapsing.** It is a large
win for Screen Slate because NYC first-run houses play a film five times a
day. Austin's venues are repertory — only six titles repeat across a week.
The mechanic does not pay for itself here.

---

## Conversion plan

Decided: content pages scroll normally (only the index is one screen);
`/th1/` and `/th2/` survive as full pages; the dashboard keeps its jQuery for
now; cutover happens **per page** as each is finished.

| Phase | | Status |
|---|---|---|
| 0 | Shared chrome + shared data includes | **done** |
| 0.5 | Onboarding states in v7 (`onboard`, `gated`) | **done** |
| 1 | Swap `/` to v7 | |
| 2 | `/list/` — immediately after, same journey | |
| 3 | `/posts/`, `/about/` | |
| 4 | `/directory/`, `/users/profile.php` | |
| 5 | `/dashboard/` — markup restyle only | |
| 6 | `/th1/`, `/th2/` | |
| — | `_admin/` — deliberately left on the old stylesheet | won't do |

### Phase 0 shape

    v7/_lib.php      bootstrap, escaping, one function per content pillar
    v7/_head.php     <head> + shell open;  $ctx_scroll picks the shell variant
    v7/_chrome.php   rail + bar;  $ctx_active drives the nav highlight
    v7/_foot.php     shell close, scrim, universal account panel, theatre

`ctx_state()` returns **guest / onboard / gated / member** — the front page
has to branch four ways, not two. Every reference build so far handled only
two, which is why the onboarding states are a cutover blocker.

`CTX_HOME` in `_lib.php` is the cutover switch: it points at `/v7/` today and
becomes `/` when the root swaps. Nothing else needs editing.

### Tech debt, written down so "later" is real

- **`dashboard.js` + `quick-create.js` (1,222 lines of jQuery)** stay as-is
  through the conversion; only their markup is restyled. Rewriting working
  upload / autosave / draft logic is the highest-risk change available for no
  user-visible gain. Revisit once everything is on v7 and stable.
- ~~The **signup SQL injection**~~ — **fixed at Phase 0.5**, along with two
  worse bugs found beside it. `activateacct` and `updateprof` both took `uid`
  straight from POST with no session check, so any caller could deactivate an
  arbitrary account or repoint another member's email at their own. All uid
  values now come from the session; all queries are bound.
- `script-jlm.js` and `script.js` retire when the last old page converts,
  taking the `[th1]` console noise with them.

## Taste profile

Evidence-based, from six versions of feedback.

**Reliably wanted**
- Modularity — panels as the atom. Never once objected to, in any version.
- The red. Reclaimed unprompted.
- Reveal-on-demand rather than navigate-away. Proposed the blur-modal himself.
- Everything represented on the index.
- Contained fields. Every positive reaction came when content sat in a
  defined area; every negative one when it spanned the viewport.
- The left rail. Raised weather.com's collapsible rail unprompted in the very
  first exchange, and called the navbar "very important" early on.

**Reliably rejected**
- Full-bleed sprawl
- Anything that reads as a template
- Being made to hunt or scan
- Equal-weight information walls
- Excessive scrolling

**Working style**
- Iterative, and wants to be asked before big swings.
- Gives precise corrections — take them literally.
- Thinks in terms of *form follows function* and future extensibility.
- References: Cahiers du Cinéma (philosophy, **not** its visual trademarks),
  art-house zine, Austin.

---

## Technical notes

**The reference builds are parallel and non-destructive.** Each version is a
directory (`/v6/`) plus a stylesheet (`css/v6.scss`) and a script (`js/v6.js`).
`/index.php` has never been touched.

**PHP endpoints are unchanged across every version.** The front-end binding
layer is rewritten each time; the server contracts are not:
- `/posts/quick.php` — `type=post|delete`
- `/dashboard/signup.php?action=signup|login|logout|firstcontact`
- `/dashboard/recent.php?action=list` — returns no URL; build from `kind`+`id`
- `list/fetch_screenings.php` — `fetch_all_screenings($conn, $now, $end)`

**The theatre sync algorithm** is carried forward intact from `theatre_1()`:
park pre-show, seek to elapsed, muted autoplay, correct drift beyond 2s every
3s, snap back on scrub. `stream.php` is required for range requests.

**Compile:** `sass css/v6.scss css/v6.css --no-source-map`

### Verification gotchas worth remembering

- **Reading computed style immediately after a class change returns the
  mid-transition value.** This produced several false bug reports. Always
  measure in a separate step.
- **The preview pane cannot composite `backdrop-filter`** — overlays look
  transparent in screenshots but are opaque in a real browser. Verify via DOM.
- **The pane also fails to repaint scrolled content.** Hide upper sections or
  size the viewport up rather than scrolling.
- **Rail state persists to localStorage**, so a reload may start collapsed and
  a subsequent toggle click will do the opposite of what you expect.

---

## Open items for next session

1. **`events` table is empty.** The member-submitted screening treatment has
   never been verified against real data. Submitting one through the dashboard
   would close this.
2. **Only the front page exists in v6.** The List, dashboard, directory,
   profiles, post pages and `/th1` are all still on the original `sass.scss`.
   The List is the natural next surface — it is a co-equal pillar and it is
   where the future job-listings generalisation will live.
3. **Listing generalisation.** Job listings and similar are coming. The shape
   to preserve is *a stream of dated items carrying a source, a type, and
   sometimes a person* — which is already true today. Do not build the
   abstraction before there is a second type.
4. **Five reference builds are live.** Pruning the rejected ones costs
   nothing; they remain on their branches.
5. **Data duplication.** Each reference build copies the theatre/list/journal
   queries. When one wins, that block moves to a shared include.
