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
- Same live cam data as the homepage (no preset filter) — a presentation test,
  not a new content set.
- Indexable (no noindex) and included in `sitemap.xml`
  (`app/Http/Controllers/SitemapController.php`).

`CamController::renderGrid()` was generalized to accept a `$view` parameter so
`/`, all `/girls/...` landing pages, and `/feed` share one query/data path.

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
- Fixed two pre-existing local setup issues unrelated to this feature, both of
  which blocked `php artisan test` from running at all: missing `APP_KEY` and
  a never-created `database/database.sqlite`. Also fixed
  `tests/Feature/ExampleTest.php`, which had `RefreshDatabase` commented out in
  the stub and was failing against the homepage's DB query even before this
  work.

## Not done — still worth a decision

`/guys`, `/trans`, `/couples` and their sub-pages (`config/seo-pages.php`) are
still registered, still in `sitemap.xml`, but permanently render an empty grid
— the Chaturbate sync has been female/feet-only since commit `aaf3f15`, and
nav already routes that traffic to external sites (commit `cbf6f50`). Google
sees ~10+ indexed pages with no content, which drags on the rest of the site's
perceived quality. Options: prune them from `config/seo-pages.php`, or add
another provider/broaden the sync so they have real data.
