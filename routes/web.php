<?php

use App\Http\Controllers\CamController;
use App\Http\Controllers\SitemapController;
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
 * Each slug in config/seo-pages.php gets its own explicit route. This is
 * deliberate — no catch-all — so typos, future admin routes, or arbitrary
 * paths get a proper 404 rather than hitting the landing handler.
 *
 * When you add a new page to config/seo-pages.php, you get a new route here
 * automatically on the next request (or after `php artisan route:cache`).
 */
foreach (array_keys(config('seo-pages', [])) as $slug) {
    Route::get('/'.$slug, [CamController::class, 'landing'])
        ->defaults('slug', $slug)
        ->name('landing.'.str_replace('/', '.', $slug));
}
