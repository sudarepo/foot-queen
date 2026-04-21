<?php

namespace App\Http\Controllers;

use App\Services\SeoPageResolver;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function __construct(private SeoPageResolver $seo) {}

    public function sitemap(): Response
    {
        $today = now()->toDateString();

        $urls = [
            [
                'loc'        => url('/'),
                'lastmod'    => $today,
                'changefreq' => 'hourly',
                'priority'   => '1.0',
            ],
        ];

        // Only include canonical URLs. Aliases (e.g. /blonde/ when /girls/blonde/
        // is canonical) are intentionally excluded so Google doesn't index duplicates.
        foreach ($this->seo->canonical() as $page) {
            $urls[] = [
                'loc'        => url('/' . $page['slug']),
                'lastmod'    => $today,
                'changefreq' => $page['changefreq'],
                'priority'   => number_format((float) $page['priority'], 1),
            ];
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
            'Allow: /',
            '',
            'Sitemap: ' . url('/sitemap.xml'),
        ];

        return response(implode("\n", $lines) . "\n", 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    private function buildXml(array $urls): string
    {
        $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $out .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $url) {
            $out .= "  <url>\n";
            $out .= "    <loc>" . htmlspecialchars($url['loc'], ENT_XML1) . "</loc>\n";
            $out .= "    <lastmod>{$url['lastmod']}</lastmod>\n";
            $out .= "    <changefreq>{$url['changefreq']}</changefreq>\n";
            $out .= "    <priority>{$url['priority']}</priority>\n";
            $out .= "  </url>\n";
        }

        $out .= '</urlset>' . "\n";
        return $out;
    }
}
