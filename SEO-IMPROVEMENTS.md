# SEO improvements + `/feed` landing page variant

Date: 2026-07-25

## Why

The site's SEO was underperforming: several structural issues beyond just competing
in a crowded niche. This work fixed the quick-win issues and added an alternate,
Instagram-style landing page (`/feed`) to test as a design variant against the
existing grid homepage. Initially built without touching `/` at all; `/` now
also runs a real randomized 50/50 split (see §2) so the comparison is against
actual homepage traffic — but the grid itself, and what a grid-assigned visitor
sees, is unchanged.

## What changed

### 1. `/feed` — Instagram-style landing page variant

- **Route:** `GET /feed` → `CamController::feed()` (`routes/web.php`,
  `app/Http/Controllers/CamController.php`)
- **View:** `resources/views/cams/feed.blade.php` — single-column scrolling feed:
  circular avatar, username + live badge header, square post image, heart icon +
  real viewer count, hashtag caption, "Join the room" CTA button.
- **CSS:** appended `.ig-*` classes to `public/css/app.css` (scoped under
  `.ig-feed`, doesn't affect the existing `.cam-grid` styles used by `/`).
  Responsive: single column below 780px (mobile), 2 columns from 780px, 3
  columns from 1240px — a fixed 470px single column looked broken/empty on
  wide desktop viewports, so it now widens into a card wall instead.
- Same live cam data as the homepage (no preset filter) — a presentation test,
  not a new content set.
- Indexable (no noindex) and included in `sitemap.xml`
  (`app/Http/Controllers/SitemapController.php`).

`CamController::renderGrid()` was generalized to accept a `$view` parameter so
`/`, all `/girls/...` landing pages, and `/feed` share one query/data path.

**Live video preview — device-adaptive.** On `/feed` only, a card's thumbnail
swaps for a real live iframe of that room, muted. At most one iframe is ever
mounted at a time — the previous one is torn down before the next mounts — so
scrolling past dozens of cards never loads dozens of live streams at once.
This uses Chaturbate's own `iframe_embed_revshare` field from the affiliates
API (`ChaturbateProvider::extractEmbedUrl()`,
`app/Services/Providers/ChaturbateProvider.php`) — a revenue-share-tracked
embed URL Chaturbate provides specifically for affiliates to embed rooms on
their own pages, not a scrape or workaround. It's stored per-cam in a new
`embed_url` column (migration
`2026_07_25_192238_add_embed_url_to_cams_table.php`) and refreshed on every
`cams:sync`. Cams without one (e.g. a future provider that doesn't offer this)
just keep the static thumbnail — the preview only activates when
`data-embed-url` is present. All logic lives in one script at the bottom of
`resources/views/cams/feed.blade.php`, which branches on
`matchMedia('(hover: none)')` to pick the interaction model:

- **Desktop (has a mouse):** hover (or keyboard focus) a card for 250ms to
  preview it; moving away (or blurring) tears it down. Unchanged from the
  original implementation.
- **Mobile/touch (TikTok/Reels-style):** there's no hover on touch, so instead
  an `IntersectionObserver` watches every card and auto-plays whichever one is
  ≥60% visible, muted, as you scroll — settling for 200ms first so a fast
  flick-scroll doesn't spin up a live embed per frame it passes through. The
  active card gets a visible highlight (`.ig-post--active` — accent-colored
  ring/glow around the whole post, not just the thumbnail) so it's obvious
  which one is currently live. The first time a card auto-activates, a
  bouncing "Scroll for the next live cam ↓" pill (`.ig-scroll-hint`,
  `#igScrollHint` in the view) fades in and stays until the user's first
  scroll, then dismisses itself for the rest of the session. Posts also get
  `scroll-snap-align: center` with `scroll-snap-type: y proximity` on the page
  (scoped to `body.is-feed-page`, set via a new `@section('bodyClass')` in
  `resources/views/layouts/app.blade.php`) so scrolling settles roughly
  centered on a card — proximity rather than mandatory, so it nudges rather
  than hijacking the scroll the way a rigid snap would.

Confirmed working end-to-end against live data on both interaction models: ran
a real `cams:sync`, then drove a real (headless) browser —
- desktop viewport: hovered a card, watched the thumbnail become a live video
  feed, and watched it unmount cleanly on mouse-leave;
- mobile viewport (iPhone 13 emulation, touch-primary): loaded `/feed` cold
  and the first card auto-played with the highlight ring and scroll hint
  visible, with no tap/interaction needed; scrolled down and confirmed
  exactly one iframe was ever mounted (the new card), the previous one had
  unmounted, and the hint had dismissed.

One caveat worth knowing: the embed is Chaturbate's actual room widget (video
+ their tip button, chat panel, room title bar), not a clean video-only feed —
there's no video-only option exposed via the affiliate API. It's cropped into
a square card, which reads fine but isn't a bespoke minimal player.

**Known limitation: ad blockers.** A report came in that the live preview
"wasn't working." Investigation (curl'd the production HTML directly, then
drove `https://www.footqueen.com/feed` with a real headless browser and
hovered a card) confirmed the deployed code, data, and embed all work
correctly — the video genuinely plays. The difference was the reporter's own
ad blocker: Chaturbate iframes are commonly caught by ad-blocker filter lists
since the same kind of third-party cam widget is often used for ads elsewhere
on the web. There's no reliable client-side fix for this — a
"did-the-iframe-actually-load" timeout was considered and rejected, because
cross-origin restrictions mean the parent page can't inspect whether a
same-origin `load` event corresponds to real content or an empty/blocked
response; the heuristic would misfire more often than not and create false
confidence. Instead, a small muted notice was added under the page heading
(`.ig-feed__notice` in `resources/views/cams/feed.blade.php` /
`public/css/app.css`) explaining that previews need third-party embeds
allowed and that "Join the room" always works regardless — honest framing
instead of pretending to detect and fix something that can't reliably be
detected.

**Fixed: scroll got trapped inside the preview on desktop.** With the cursor
over a playing preview, scrolling the mouse wheel didn't move the page — it
was being consumed by Chaturbate's iframe instead. This is a real limitation
of cross-origin iframes: once the pointer is over one, wheel/touch-scroll
input goes straight into that separate document, and the parent page has no
visibility into it at all (can't listen for it, can't forward it — cross-origin
restrictions block that entirely). The fix is a transparent overlay `<div>`
placed on top of the iframe (`.ig-post__live-embed-overlay`, created/removed
alongside the iframe in `activate()`/`unmountActive()` in
`resources/views/cams/feed.blade.php`; styled in `public/css/app.css`). Because
the overlay is a normal element in *our* document rather than a foreign one,
wheel and click events on it behave completely normally — scrolling bubbles up
and moves the page, and clicks bubble up to the card's `<a>` and go through our
tracked `/go/{cam}?src=feed` redirect. Side effect (net positive): during the
preview, clicks can no longer land on Chaturbate's own tip/chat controls inside
the widget — previously a click there would route into their UI instead of our
tracked link; now every click reliably goes through our own funnel.

Verified with a real headless browser against live data: positioned the cursor
over a playing preview and sent a wheel scroll — the page scrolled by the full
delta (0 → 600px) exactly as it would over any other part of the page. Also
confirmed a click there still opens the tracked `/go/` redirect (not the
widget) by checking the resulting popup URL.

**Speeding up preview loads.** Most of the latency is Chaturbate's own embed
page — a redirect chain (`/in/` → `/gotoroom/embed/` → `/embed/{username}/`)
landing on a page that pulls in its own JS/CSS/chat/analytics — which is
outside our control. Two things *are* in our control, both in
`resources/views/cams/feed.blade.php`:

- **`<link rel="preconnect">` / `dns-prefetch` to chaturbate.com**, pushed into
  `<head>` only on `/feed` (new `@stack('head')` in
  `resources/views/layouts/app.blade.php`, matching the existing
  `@stack('scripts')` pattern). Warms DNS/TCP/TLS setup ahead of the first
  preview instead of paying that cost cold on first hover/scroll-into-view.
- **One-ahead preloading**, capped deliberately at exactly one hidden iframe in
  flight — not "preload several," which would mean multiple live video streams
  loading in the background for cams the visitor might never look at (real,
  wasted bandwidth/CPU cost). The script now tracks two roles instead of one:
  `active` (visible, playing — at most one) and `preloading` (hidden via
  `.ig-post__live-embed--preloading`, i.e. `opacity: 0; pointer-events: none;`
  — not `display: none` and no `loading="lazy"`, both of which would stop the
  browser from actually fetching it while hidden — at most one). `activate()`
  reuses the matching preload's iframe instead of creating a fresh one, so
  nothing ever double-loads.
  - **Desktop:** `startPreload()` fires on `mouseenter`, immediately — the
    network fetch now runs *during* the existing 250ms hover-dwell instead of
    starting only after it. If the user leaves before the dwell completes, the
    preload is canceled (iframe removed) — no wasted load for a quick pass-by,
    same as before.
  - **Mobile:** the moment a card is confirmed active (scroll-settled), the
    *next* card in feed order starts preloading in the background — the same
    technique TikTok/Reels use to make the next swipe feel instant. If the
    user scrolls somewhere other than that pre-warmed next card, the stale
    preload is discarded and a fresh one starts for wherever they actually
    landed.

Verified with a real headless browser against live data, counting actual
network requests (not just DOM state) to be sure nothing double-loads:
confirmed a hidden preloading iframe exists ~80ms after `mouseenter` (well
before the 250ms dwell completes), confirmed exactly **one** request ever hits
the iframe's entry URL for a full hover-to-active cycle, confirmed the
preload is torn down cleanly on an early mouse-leave, and confirmed the next
card is already preloading in the background on mobile as soon as the first
one activates.

### 2. A real A/B test: random split + view tracking + click tracking

Originally `/feed` was just a separate page nobody was actually sent to (not
linked from nav or the homepage) — comparing it to `/` wasn't a real test, just
two pages that happened to exist. This is now a proper randomized experiment.

**Traffic split.** `CamController::index()` (`GET /`) now splits real visitors
50/50 between the grid and the feed variant, via a new service class:

- **`app/Services/HomepageAbTest.php`** — `resolve($request)` returns the
  variant for this visitor: honors an existing `ab_feed_variant` cookie if
  present (so repeat visits are consistent, not re-randomized), otherwise
  assigns one at random via `random_int(0, 1)`. `isBot($request)` excludes
  known crawlers/bots (regex over common user-agent tokens — no dependency
  added for this) and requests with no user-agent at all.
- **Bots always see the grid at `/`** — no cookie, no redirect, ever. This
  keeps `/` canonical and consistent for indexing (no cloaking risk) and keeps
  crawler traffic out of the conversion numbers.
- **Grid-assigned visitors:** `/` renders normally, exactly as before.
- **Feed-assigned visitors:** `/` redirects (302) to `/feed`, preserving any
  query filters (`redirect()->route('cams.feed', $request->query())` — *not*
  `RedirectResponse::withQueryString()`, which doesn't actually exist as a real
  method; it's silently swallowed by `RedirectResponse::__call()`'s
  session-flash fallback and throws `Undefined array key 0` if called with no
  arguments — caught by the test suite, not by chance).
- The assignment cookie lasts 90 days (`HomepageAbTest::cookieMinutes()`).

**View tracking.** New `page_view_events` table (migration
`2026_07_26_163925_create_page_view_events_table.php`, model
`app/Models/PageViewEvent.php`) — one row (`page` = `grid`/`feed`) per real
page render. Logged in exactly one place per variant — inside `feed()` for
feed views (covers both the A/B redirect *and* anyone landing on `/feed`
directly/organically) and inside `index()` for grid views (only on the
non-redirect branch) — specifically so a `/` → `/feed` redirect hop is never
counted twice. Verified this holds with a real browser: visited `/` in fresh
browser contexts and confirmed total `page_view_events` rows always matched
total real visits, split correctly across variants, across multiple runs.

**Click tracking** (unchanged from before, now paired with the above for real
conversion rate instead of raw volume): `cam_click_events` table (`cam_id`,
`source_page`, migration `2026_07_25_184605_create_cam_click_events_table.php`,
model `app/Models/CamClickEvent.php`). Every outbound click (`/go/{cam}`) is
logged with `source_page` = `grid` or `feed`, from a `?src=` query param on the
card links in `cams/index.blade.php` and `cams/feed.blade.php`.
`CamController::redirectToRoom()` validates `src` against an allow-list and
defaults to `grid` for anything else (old links, direct hits, bots).

**To see results, run against the production database:**

```sql
select
  v.page as variant,
  v.views,
  coalesce(c.clicks, 0) as clicks,
  round(100.0 * coalesce(c.clicks, 0) / v.views, 2) as ctr_percent
from
  (select page, count(*) as views from page_view_events group by page) v
left join
  (select source_page, count(*) as clicks from cam_click_events group by source_page) c
  on c.source_page = v.page;
```

Give it real time to accumulate — with a fresh 50/50 split just shipped,
there's no meaningful sample yet. Re-run periodically; there's no built-in
statistical-significance check, so treat early numbers as noisy until the
sample size (views per variant) is large enough that the CTR gap is bigger
than normal run-to-run variance would produce.

### 3. SEO fixes

- **Structured data (previously none existed):**
  - `ItemList` JSON-LD on `/` and `/feed`, top 20 cams
    (`resources/views/cams/_structured-data.blade.php`, included by both views).
  - Sitewide `WebSite` JSON-LD in `resources/views/layouts/app.blade.php`.
- **Broken assets (previously missing/empty, causing broken social previews and
  a blank favicon):**
  - Regenerated `favicon.ico`, `favicon-16x16.png`, `favicon-32x32.png`,
    `favicon-48x48.png`, `apple-touch-icon.png`, `icon-192.png`, `icon-512.png` —
    a branded "FQ" monogram in the site's accent gradient.
  - Added `icon.svg` (vector version of the same monogram).
  - Added `site.webmanifest`.
  - Added `og-image.png` (1200×630, logo + tagline on the site's dark gradient)
    for social share previews.
  - Generated via a one-off script (not part of the app) using PHP's GD/Imagick,
    from the brand colors already defined in `public/css/app.css`.

### 4. Test infrastructure (needed to verify the above)

- `database/factories/CamFactory.php` — didn't exist; added so `Cam` model
  usage is testable (`use HasFactory` added to `app/Models/Cam.php`).
- `tests/Feature/CamFeedTest.php` — homepage and `/feed` render correctly,
  `/feed` is indexable and listed in the sitemap.
- `tests/Feature/CamClickTrackingTest.php` — click events are logged with the
  correct `source_page`, including the fallback for unrecognized `src` values.
- `tests/Feature/ChaturbateProviderTest.php` — `embed_url` extraction from the
  API's `iframe_embed_revshare`/`iframe_embed` fields, `disable_sound=1` is
  always forced, and it's `null` when the API gives us neither field.
- `tests/Feature/HomepageAbTestServiceTest.php` — `HomepageAbTest` in
  isolation: bot detection (incl. the "Symfony" default user-agent
  `Request::create()` silently fills in when none is given — worth knowing if
  you write similar tests), existing-cookie is honored, a missing/garbage
  cookie gets a fresh random assignment, and 200 runs of the random assignment
  actually produce both variants (not a fixed/biased outcome).
- `tests/Feature/HomepageAbTestTest.php` — the full HTTP-level wiring: bots
  never get a cookie/redirect/logged view; a `grid`-cookied visitor stays and
  logs one view; a `feed`-cookied visitor redirects and logs exactly one view
  *only once it lands*, not at the redirect step; a first-time visitor gets a
  new cookie; and (using `followingRedirects()`, which carries cookies across
  the hop the same way a real browser would) a first-time visitor logs exactly
  one page view end-to-end regardless of which variant they land on.
- Updating `CamFeedTest`/`ExampleTest` to pin or follow the homepage's variant
  — both previously hit `/` assuming it always renders the grid, which became
  a 50/50 coin flip once the A/B split shipped and would have made them flaky.
- Fixed two pre-existing local setup issues unrelated to this feature, both of
  which blocked `php artisan test` from running at all: missing `APP_KEY` and
  a never-created `database/database.sqlite`. Also fixed
  `tests/Feature/ExampleTest.php`, which had `RefreshDatabase` commented out in
  the stub and was failing against the homepage's DB query even before this
  work.
- The hover/scroll-autoplay JS itself isn't covered by PHPUnit (there's no JS
  test runner in this project) — it was verified manually with a headless
  Playwright browser against real synced data instead (see above). If this
  logic grows more complex, worth adding a JS test setup rather than
  continuing to rely on manual browser verification.

### 5. Admin panel (Filament) — a real place to see all of this

The A/B numbers and cam data were only queryable via raw SQL. Added
[Filament](https://filamentphp.com) v4 (`filament/filament: ^4.0`, resolved to
`v4.12.3`) as a proper admin UI at `/admin`. This is a real dependency
addition (~33 packages: Livewire, Alpine, Filament's own asset pipeline) —
done deliberately, not silently; confirmed via `composer audit` that the only
flagged advisories (guzzle/symfony, pre-existing) predate this and weren't
introduced by it.

**What's there:**
- **`/admin/conversion-dashboard`** ("A/B Test" in the nav) — the grid-vs-feed
  CTR from §2, as two stat cards (`app/Filament/Widgets/ConversionStatsWidget.php`),
  the current leader highlighted green. A **"Sync cams now"** button runs
  `cams:sync` on demand instead of waiting for the schedule
  (`app/Filament/Pages/ConversionDashboard.php`).
- **`/admin/cams`** — browsable/searchable/filterable cam list + detail view
  (`app/Filament/Resources/Cams/`). Deliberately **read-only**: cam rows are
  entirely owned by `CamSyncService` and get overwritten on every sync, so
  there's no create/edit page — only List and View
  (`CamResource::getPages()`; `canCreate()` returns `false`). Verified the
  `/create` and `/{id}/edit` routes actually 404, not just that the buttons
  are hidden.
- Panel primary color set to the site's accent (`#e85d22`) in
  `app/Providers/Filament/AdminPanelProvider.php`.

**Access control — read this before deploying.** Filament has a deliberate
safety default: without a `FilamentUser::canAccessPanel()` implementation on
the User model, panel access only falls through when `APP_ENV=local` — in any
other environment (including `production`), it's a hard 403 for everyone,
regardless of login. This is *why* the panel worked fine in manual local
browser testing but the first version of the test suite failed everything
with 403 (`phpunit.xml` sets `APP_ENV=testing`) — a real gap the tests caught,
not a testing artifact to work around. Fixed properly, not bypassed:
`App\Models\User` now implements `FilamentUser`, and `canAccessPanel()`
returns `true` unconditionally — there's no public registration route, so
every row in `users` was already created deliberately (via
`make:filament-user` or direct DB access), and is trusted by design.

**No admin user was created on production, and none should be created by
assumption.** To get in, run this yourself against production (Laravel Cloud
console/SSH, or wherever `artisan` runs for the deployed app):

```bash
php artisan make:filament-user
```

It prompts for name/email/password interactively — nothing to hand off,
nothing generated on your behalf. For local development only, a throwaway
account exists in the local sqlite DB: `admin@example.test` /
`localtest123` — local-only, never touches production, don't reuse it as a
real credential anywhere.

**Verified end-to-end with a real (headless) browser, not just route tests:**
logged in, loaded the conversion dashboard (matched the DB counts from §2
exactly), browsed the cams list with live thumbnails/filters, opened a cam's
detail view, and confirmed `/admin/cams/create` and `/admin/cams/{id}/edit`
both 404. No JS console errors on any page.

- `tests/Feature/FilamentAdminPanelTest.php` — guests redirected to login;
  an authenticated user can reach the panel, the dashboard, the cams list,
  and a cam's detail view; create/edit routes 404 for the read-only resource.

### 6. Chaturbate sync: broader foot-content coverage via multiple tags

`ChaturbateProvider` already filtered the sync to `gender=f&tag=feet` (since
commit `aaf3f15`) — the site's whole premise is women's feet, so this was
already the right filter, not a gap. Checked whether it was missing anything:
the API only accepts one `tag` per request (comma-separating them is rejected
outright — `{"errors":{"tag":[{"message":"Enter a valid value."}]}}`), so
broadening means separate paginated requests per tag, merged together.

Checked live against the real API before changing anything (2026-07-26,
~420 online results for `feet` at the time): `soles` added zero performers
not already covered by `feet`; `footfetish` and `toes` each added only ~2;
`feetworship` and `pedicure` returned zero results outright. Reported these
numbers before implementing — added the two tags that showed any signal
(`footfetish`, `toes`), skipped the two that showed none.

`fetchCams()` now loops `ChaturbateProvider::TAGS = ['feet', 'footfetish',
'toes']`, paginating each tag independently via the extracted `fetchTag()`
method, and merges results into an array keyed by username so a performer
tagged both ways (or fetched twice due to pagination) is only processed
once — verified with a live sync afterward (`juicykendra`, one of the
performers only reachable via `toes`, showed up in the `cams` table with
real synced data).

- `tests/Feature/ChaturbateProviderTest.php` — added a test that fakes
  different rooms per tag (including the same username under two tags) and
  asserts the merged result contains each performer exactly once.

### 7. Admin panel: date-filtered stats, in-app explanations, admin clicks tracked

Three follow-ups after actually using the dashboard from §5:

**Date range filter — fixes a real reporting bug, not just a nice-to-have.**
Before the 50/50 split shipped (2026-07-26, see `HomepageAbTest::LAUNCHED_AT`),
"/" had no A/B logic at all — every visit was "grid" by definition, since
nothing sent anyone to "/feed" yet. All-time totals on the dashboard were
mixing that pre-launch period in with real post-launch data, which is exactly
what made grid look artificially ahead. `ConversionDashboard` now has a
`filtersForm()` (Filament's dashboard-filters pattern —
`Filament\Pages\Dashboard\Concerns\HasFiltersForm`, adapted here for a plain
`Page` rather than the built-in `Dashboard` class, so
`getFiltersFormContentComponent()` had to be defined by hand — it only exists
on `Dashboard` itself, not the trait) with `from`/`until` date pickers.
`from` defaults to `LAUNCHED_AT`; `until` defaults to blank ("up to now").
`ConversionStatsWidget` reads the selected range via
`InteractsWithPageFilters`'s `$pageFilters` (Livewire prop, reactive — the
stats re-render live as the dates change) and scopes both the
`page_view_events` and `cam_click_events` queries by it, so clicks and views
are always filtered together, never one without the other.

Along the way: Filament widgets lazy-load by default
(`Filament\Support\Concerns\CanBeLazy`, `$isLazy = true`) — a follow-up AJAX
round-trip after the initial page load, invisible in a browser but meaning
the stats never appeared in a plain HTTP test response at all. Set
`$isLazy = false` on `ConversionStatsWidget`: these are cheap aggregate
counts on a low-traffic admin page, not worth the pop-in delay, and it made
the behavior actually testable.

**In-app explanations.** A collapsible "What these numbers mean" section on
the dashboard now spells out what a View, a Click, and CTR actually are, plus
an explicit warning about the pre-launch skew and why the date filter
defaults where it does — so the answer to "what is a view" lives next to the
numbers themselves, not only in this file.

**Admin outbound clicks are now tracked too.** The Cam resource's "Room URL"
was previously just copyable text, not a real link — clicking through from
the admin panel wasn't possible, let alone tracked. Added a "Visit room"
action (table row action in `CamsTable.php`, header action on `ViewCam.php`)
that opens the real room in a new tab via the *same* tracked
`/go/{cam}` redirect the public pages use
(`route('cams.redirect', [$record, 'src' => 'admin'])`), reusing the existing
click-logging path rather than duplicating it. `CamController::redirectToRoom()`'s
source allow-list now includes `'admin'` alongside `'grid'`/`'feed'`.

Verified with a real browser end-to-end: confirmed the "From" date field
actually renders `Jul 26, 2026` (not just that the constant is correct in
PHP), confirmed the explanation section text renders, and clicked the real
"Visit room" link — it opened `chaturbate.com` in a new tab, and the click
showed up in `cam_click_events` with `source_page = 'admin'` immediately
after.

- `tests/Feature/FilamentAdminPanelTest.php` — the cams list and cam view
  page both link to the tracked visit-room URL; the dashboard explanation
  text renders; the default date filter excludes a pre-launch page view from
  the stats (seeded a view dated `2026-01-01` alongside one dated "now,"
  asserted only the in-range one is counted).
- `tests/Feature/CamClickTrackingTest.php` — clicking through with `src=admin`
  logs `source_page = 'admin'`.

### 8. Fixed: bot-inflated click counts (CTR was reading over 1000%)

Real production numbers reported: **Grid — 1,107 clicks / 92 views (1203%
CTR)**, Feed — 47 clicks / 84 views (56% CTR). Grid's number is impossible
for organic traffic at that scale, so this was a real bug, not just "people
click a lot" — clicking from a card opens Chaturbate in a new tab (the grid
page itself stays open), so more clicks than views *can* happen legitimately
if someone browses several cams from one visit, but not 12 clicks per view.

Root cause: `CamController::redirectToRoom()` (`/go/{cam}`) logged **every**
click with no bot filtering at all, while `index()`/`feed()` only log page
views for real (non-bot) visitors via `HomepageAbTest::isBot()`. That
asymmetry means any crawler/scraper that follows `/go/` links — `robots.txt`
disallows that path, but plenty of bots don't respect it — added to the click
count with zero matching filtered view to divide it against. Grid's cards are
plain server-rendered `<a href="/go/...">` links from page one, easy for any
naive HTML-parsing bot to follow at volume; that fits the scale of the
inflation seen.

**One thing this incident did confirm working correctly: the 50/50 split
itself.** Views were 92 grid / 84 feed — a 52%/48% split, well within normal
variance for that sample size. The split math was never broken; only the
click side of the measurement was.

Fix: `redirectToRoom()` now gates the click *log* behind the same
`isBot()` check as page views — mirroring `index()`/`feed()` exactly. The
redirect itself is never blocked for bots (no reason to; a bot following the
link through to Chaturbate is harmless), only whether the click gets counted.

Verified directly against a running server, not just in tests: curled
`/go/{cam}` with an `AhrefsBot` user-agent — got a real `302` redirect, but
`cam_click_events` row count didn't move. Curled the same URL with a normal
browser user-agent immediately after — redirected the same way, and the row
count went up by one.

- `tests/Feature/CamClickTrackingTest.php` — a bot user-agent still gets
  redirected (`assertRedirect`) but logs zero click events.

## Not done — still worth a decision

`/guys`, `/trans`, `/couples` and their sub-pages (`config/seo-pages.php`) are
still registered, still in `sitemap.xml`, but permanently render an empty grid
— the Chaturbate sync has been female/feet-only since commit `aaf3f15`, and
nav already routes that traffic to external sites (commit `cbf6f50`). Google
sees ~10+ indexed pages with no content, which drags on the rest of the site's
perceived quality. Options: prune them from `config/seo-pages.php`, or add
another provider/broaden the sync so they have real data.

`composer audit` flags 22 advisories across `guzzlehttp/guzzle`,
`guzzlehttp/psr7`, and `symfony/routing` — all pre-existing (part of Laravel's
own HTTP client, already locked at these versions before Filament or anything
else in this doc touched the project; confirmed via `git diff composer.lock`).
Not introduced by this work, but worth a `composer update` sweep sometime —
these are patch/minor-level fixes, not breaking changes.
