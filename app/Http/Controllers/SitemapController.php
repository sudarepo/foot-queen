<?php

namespace App\Http\Controllers;

use App\Models\Cam;
use App\Services\SeoPageResolver;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    /**
     * Well under the 50,000-URL / 50MB sitemap ceiling, and comfortably more
     * than the ~400 performers a feet-tag sync typically has online — a
     * bound against the roster growing, not a limit we expect to hit.
     */
    private const PROFILE_URL_LIMIT = 2000;

    public function __construct(private SeoPageResolver $seo) {}

    public function sitemap(): Response
    {
        $today = now()->toDateString();

        $urls = [
            [
                'loc' => url('/'),
                'lastmod' => $today,
                'changefreq' => 'hourly',
                'priority' => '1.0',
            ],
            [
                'loc' => url('/feed'),
                'lastmod' => $today,
                'changefreq' => 'hourly',
                'priority' => '0.6',
            ],
        ];

        // Only include canonical URLs. Aliases (e.g. /blonde/ when /girls/blonde/
        // is canonical) are intentionally excluded so Google doesn't index duplicates.
        foreach ($this->seo->canonical() as $page) {
            $urls[] = [
                'loc' => url('/'.$page['slug']),
                'lastmod' => $today,
                'changefreq' => $page['changefreq'],
                'priority' => number_format((float) $page['priority'], 1),
            ];
        }

        foreach ($this->profileUrls() as $url) {
            $urls[] = $url;
        }

        $xml = $this->buildXml($urls);

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
        ]);
    }

    public function robots(): Response
    {
        $lines = [
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
        ];

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    /**
     * Performer profile pages (/cam/{username}).
     *
     * Only currently-online performers are listed. An offline profile still
     * resolves — the bio and photo sets are the durable half of the page —
     * but submitting a URL whose main content is a stream that isn't running
     * invites a "crawled, not indexed" verdict, and the roster turns over
     * constantly. Ordered by viewers so the cap, when it bites, keeps the
     * performers with the most substantial pages.
     *
     * @return array<int, array{loc: string, lastmod: string, changefreq: string, priority: string}>
     */
    private function profileUrls(): array
    {
        return Cam::online()
            ->orderByDesc('viewers')
            ->limit(self::PROFILE_URL_LIMIT)
            ->get(['username', 'updated_at'])
            ->map(fn (Cam $cam) => [
                'loc' => route('cams.show', $cam->username),
                'lastmod' => $cam->updated_at->toDateString(),
                // Hourly, not daily: whether the performer is live is the
                // part of the page that changes, and it changes constantly.
                'changefreq' => 'hourly',
                'priority' => '0.5',
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
