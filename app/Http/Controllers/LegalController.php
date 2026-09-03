<?php

namespace App\Http\Controllers;

use App\Models\Cam;
use App\Models\Site;
use App\Services\LegalPage;
use App\Services\LegalPageResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The four legal pages, served on every domain.
 *
 * Which page is being served comes from the route's defaults rather than a
 * path parameter, the same way landing pages work — so /2257 and /dmca are
 * real, individually named routes and anything else under them is a 404 rather
 * than a lookup miss.
 */
class LegalController extends Controller
{
    public function __construct(private LegalPageResolver $legal) {}

    /**
     * The domain this request is being served on. Resolved per call, not
     * constructor-injected: the controller instance is cached on the Route
     * object and outlives one request under a persistent runtime.
     */
    private function site(): Site
    {
        return app(Site::class);
    }

    public function show(Request $request): View
    {
        /** @var LegalPage $page */
        $page = $request->route()->defaults['legalPage'];

        $site = $this->site();

        return view('legal.show', [
            'page' => $page,
            'heading' => $this->legal->title($site, $page),
            'body' => $this->legal->body($site, $page),
            'pageTitle' => $this->legal->title($site, $page),
            'metaDesc' => $page->metaDescription(),
            'canonicalUrl' => url('/'.$page->slug()),

            // The "N live" counter in the header is part of the chrome on
            // every other page; a legal page that reported zero would read as
            // a dead site rather than as a static document.
            'totalOnline' => Cam::online()->forSite($site)->count(),
        ]);
    }
}
