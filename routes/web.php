<?php

use App\Http\Controllers\CamController;
use App\Http\Controllers\SitemapController;
use App\Services\SeoPageResolver;
use Illuminate\Support\Facades\Route;

/*
 * Core routes
 */

Route::get('/', [CamController::class, 'index'])->name('cams.index');

/*
 * Instagram-feed-style layout variant of the homepage — same live cam data,
 * different presentation, served on its own URL so it can be A/B tested
 * against the grid without changing "/". See CamController::feed().
 */
Route::get('/feed', [CamController::class, 'feed'])->name('cams.feed');

/*
 * Performer profile pages — live room, bio, and pics & vids on our own URL.
 * Bound on `username` rather than id so the URL is readable and stable; the
 * outbound redirect below keeps binding on the primary key.
 *
 * Declared before the landing-page loop so a config slug can never shadow it.
 */
Route::get('/cam/{cam:username}', [CamController::class, 'show'])
    ->name('cams.show');

Route::get('/go/{cam}', [CamController::class, 'redirectToRoom'])
    ->name('cams.redirect');

Route::get('/sitemap.xml', [SitemapController::class, 'sitemap'])
    ->name('sitemap');

Route::get('/robots.txt', [SitemapController::class, 'robots'])
    ->name('robots');

/*
 * Landing pages.
 *
 * Each slug across every registry in config/seo-pages/ gets its own explicit
 * route. This is deliberate — no catch-all — so typos, future admin routes, or
 * arbitrary paths get a proper 404 rather than hitting the landing handler.
 *
 * The route table is shared by all sites rather than built per domain: route
 * names stay stable ('landing.girls' means the same thing everywhere, so views
 * and redirects don't need to know which site they're on), and `route:cache`
 * still works. Sites are separated inside the handler instead — a slug that
 * isn't in the current site's registry 404s, same as one that doesn't exist.
 *
 * When you add a page to a registry, you get a new route here automatically on
 * the next request (or after `php artisan route:cache`).
 */
foreach (app(SeoPageResolver::class)->allSlugs() as $slug) {
    Route::get('/'.$slug, [CamController::class, 'landing'])
        ->defaults('slug', $slug)
        ->name('landing.'.str_replace('/', '.', $slug));
}
