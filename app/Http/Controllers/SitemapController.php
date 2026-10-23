<?php

namespace App\Http\Controllers;

use App\Models\Cam;
use App\Models\Site;
use App\Services\LegalPage;
use App\Services\SeoPageResolver;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    /**
     * Well under the 50,000-URL / 50MB sitemap ceiling. The list is every
     * performer we've ever synced for this site, not just the ones online, so
     * it only grows — the cap is what keeps a year of roster turnover from
     * pushing the file past the ceiling.
     */
    private const PROFILE_URL_LIMIT = 20000;

    /**
     * Crawlers turned away entirely in robots.txt.
     *
     * @var array<int, string>
     */
    private const BLOCKED_CRAWLERS = ['DotBot', 'AhrefsBot', 'SemrushBot', 'MJ12bot', 'GPTBot'];

    public function __construct(private SeoPageResolver $seo) {}

    /**
     * The domain this request is being served on. Resolved per call rather
     * than constructor-injected — Laravel caches the controller instance on
     * the Route object, which outlives a single request under a persistent
     * runtime, so an injected Site would be the first domain's forever.
     */
    private function site(): Site
    {
        return app(Site::class);
    }

    /**
     * Sitemaps are rebuilt this often at most. Each one is ~20k profile URLs,
     * which took 10+ seconds of CPU to build — and crawlers fetch them on every
     * domain, so building on each request kept PHP-FPM saturated.
     */
    private const CACHE_SECONDS = 6 * 60 * 60;

    public function sitemap(): Response
    {
        /**
         * Keyed by the URL root as well as the site, since every <loc> is
         * built with url() — www and apex each get their own copy. A hash of
         * the site's record is in the key so an edit (a layout pinned, the
         * SEO registry swapped) shows on the next crawl; the default site
         * answers for any unclaimed host, so its copies couldn't be listed to
         * forget. Stored gzipped and base64'd: ~10x smaller, safe in any store.
         */
        $site = $this->site();
        $version = md5(json_encode($site->getAttributes()));

        $xml = gzdecode(base64_decode(Cache::remember(
            "sitemap:{$site->getKey()}:{$version}:".url('/'),
            self::CACHE_SECONDS,
            fn () => base64_encode(gzencode($this->buildSitemap(), 6)),
        )));

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
        ]);
    }

    private function buildSitemap(): string
    {
        $today = now()->toDateString();

        $urls = [
            [
                'loc' => url('/'),
                'lastmod' => $today,
                'changefreq' => 'hourly',
                'priority' => '1.0',
            ],
        ];

        /**
         * "/feed" stays a working URL whatever the site is set to — it may be
         * indexed, bookmarked, or linked from a profile page's back link —
         * but a site that has pinned every device to the grid has switched
         * the feed off, and there's no reason to keep advertising a layout it
         * no longer sends anyone to.
         */
        if ($this->site()->servesFeed()) {
            $urls[] = [
                'loc' => url('/feed'),
                'lastmod' => $today,
                'changefreq' => 'hourly',
                'priority' => '0.6',
            ];
        }

        // Only include canonical URLs. Aliases (e.g. /blonde/ when /girls/blonde/
        // is canonical) are intentionally excluded so Google doesn't index duplicates.
        foreach ($this->seo->canonical($this->site()->seoRegistry()) as $page) {
            $urls[] = [
                'loc' => url('/'.$page['slug']),
                'lastmod' => $today,
                'changefreq' => $page['changefreq'],
                'priority' => number_format((float) $page['priority'], 1),
            ];
        }

        /**
         * The legal pages. Low priority and rarely changing — they exist in
         * here so they are discoverable and attributable to the domain, which
         * is part of what makes them count as published, not so they compete
         * for crawl budget with the listings.
         */
        foreach (LegalPage::all() as $page) {
            $urls[] = [
                'loc' => url('/'.$page->slug()),
                'lastmod' => $today,
                'changefreq' => 'yearly',
                'priority' => '0.1',
            ];
        }

        foreach ($this->profileUrls() as $url) {
            $urls[] = $url;
        }

        return $this->buildXml($urls);
    }

    public function robots(): Response
    {
        $lines = [];

        // SEO-tool and AI crawlers: no search traffic in return, and across
        // ~240 domains they were a large share of the load on the box.
        foreach (self::BLOCKED_CRAWLERS as $crawler) {
            array_push($lines, "User-agent: {$crawler}", 'Disallow: /', '');
        }

        array_push($lines,
            'User-agent: *',
            'Disallow: /go/',                 // outbound affiliate redirects — don't index
            'Disallow: /?gender=',            // filtered variants of homepage — avoid dupes
            'Disallow: /?category=',
            'Disallow: /?age=',
            'Disallow: /?hair=',
            'Disallow: /?body=',
            'Allow: /cam/',                   // performer profile pages — index these
            'Allow: /',
            '',
            'Sitemap: '.url('/sitemap.xml'),
        );

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    /**
     * Performer profile pages (/cam/{username}).
     *
     * Scoped to this site: the cams table is a shared pool across every domain
     * the deploy serves, and a sitemap that advertises another site's
     * performers is submitting URLs that 404 — /cam/{username} is served by
     * whichever domain the crawler asked, and that domain won't have them.
     *
     * Every performer, online or not. An offline profile is a real page — the
     * bio and photo sets are the durable half of it, and the performer is
     * usually back within a day — so leaving them out just meant the bulk of
     * our URL space was never advertised, and a crawler that only learns a
     * URL while its performer happens to be live is a crawler that mostly
     * never learns it.
     *
     * Online rows are ordered first, and rank ahead on `priority`, so both
     * the cap and the crawl budget land on the pages that are live right now.
     *
     * @return array<int, array{loc: string, lastmod: string, changefreq: string, priority: string}>
     */
    private function profileUrls(): array
    {
        return Cam::forSite($this->site())
            ->orderByDesc('is_online')
            ->orderByDesc('viewers')
            ->limit(self::PROFILE_URL_LIMIT)
            ->get(['username', 'is_online', 'updated_at'])
            ->map(fn (Cam $cam) => [
                'loc' => route('cams.show', $cam->username),
                'lastmod' => $cam->updated_at->toDateString(),
                // Hourly for a live room: whether the performer is streaming
                // is the part of the page that changes, and it changes
                // constantly. An offline page is just the bio until they're
                // back, so daily is honest and doesn't burn crawl budget.
                'changefreq' => $cam->is_online ? 'hourly' : 'daily',
                'priority' => $cam->is_online ? '0.5' : '0.3',
            ])
            ->all();
    }

    private function buildXml(array $urls): string
    {
        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $out .= "  <url>\n";
            $out .= '    <loc>'.htmlspecialchars($url['loc'], ENT_XML1)."</loc>\n";
            $out .= "    <lastmod>{$url['lastmod']}</lastmod>\n";
            $out .= "    <changefreq>{$url['changefreq']}</changefreq>\n";
            $out .= "    <priority>{$url['priority']}</priority>\n";
            $out .= "  </url>\n";
        }

        $out .= '</urlset>'."\n";

        return $out;
    }
}
