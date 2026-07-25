# SEO improvements + `/feed` landing page variant

Date: 2026-07-25

## Why

The site's SEO was underperforming: several structural issues beyond just competing
in a crowded niche. This work fixed the quick-win issues and added an alternate,
Instagram-style landing page (`/feed`) to test as a design variant against the
existing grid homepage — without touching `/` itself.

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

### 2. Click tracking (for comparing `/` vs `/feed`)

- **Migration:** `database/migrations/2026_07_25_184605_create_cam_click_events_table.php`
  — new `cam_click_events` table (`cam_id`, `source_page`, timestamps).
- **Model:** `app/Models/CamClickEvent.php`
- Every outbound click (`/go/{cam}`) is now logged with `source_page` = `grid` or
  `feed`, based on a `?src=` query param appended to the card links in
  `cams/index.blade.php` and `cams/feed.blade.php`.
- `CamController::redirectToRoom()` validates `src` against an allow-list and
  defaults to `grid` for anything else (old links, direct hits, bots).

**To compare conversion:**

```sql
select source_page, count(*) as clicks
from cam_click_events
group by source_page;
```

Divide by page views (not currently tracked server-side — pair with your
analytics tool, or add a `page_view_events` table the same way, if you want a
true CTR rather than raw click volume).

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

## Not done — still worth a decision

`/guys`, `/trans`, `/couples` and their sub-pages (`config/seo-pages.php`) are
still registered, still in `sitemap.xml`, but permanently render an empty grid
— the Chaturbate sync has been female/feet-only since commit `aaf3f15`, and
nav already routes that traffic to external sites (commit `cbf6f50`). Google
sees ~10+ indexed pages with no content, which drags on the rest of the site's
perceived quality. Options: prune them from `config/seo-pages.php`, or add
another provider/broaden the sync so they have real data.
