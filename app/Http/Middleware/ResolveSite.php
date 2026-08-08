<?php

namespace App\Http\Middleware;

use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the Site matching the request's host, so everything downstream —
 * controllers via constructor injection, Blade via `$site` — is talking about
 * one domain without ever having to ask which.
 *
 * Runs at the front of the web group: it must be resolved before any
 * controller is constructed.
 */
class ResolveSite
{
    public function handle(Request $request, Closure $next): Response
    {
        $site = Site::resolveForHost($request->getHost());

        /**
         * No sites at all means the table was truncated or never migrated.
         * Failing loudly beats serving every domain a page with no name, no
         * logo, and the entire unfiltered cam pool.
         */
        abort_if($site === null, 503, 'No site is configured for this installation.');

        app()->instance(Site::class, $site);
        View::share('site', $site);

        return $next($request);
    }
}
