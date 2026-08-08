<?php

use App\Http\Middleware\ResolveSite;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /**
         * Prepended: every public page, the sitemap, and the outbound
         * redirect all need to know which domain they're being served on
         * before anything else runs.
         */
        $middleware->web(prepend: [ResolveSite::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
