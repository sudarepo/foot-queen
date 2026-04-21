<?php

use App\Http\Controllers\CamController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

/*
 * Core routes
 */

Route::get('/', [CamController::class, 'index'])->name('cams.index');

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
    Route::get('/' . $slug, [CamController::class, 'landing'])
        ->defaults('slug', $slug)
        ->name('landing.' . str_replace('/', '.', $slug));
}
