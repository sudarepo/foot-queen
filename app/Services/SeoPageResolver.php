<?php

namespace App\Services;

/**
 * Reads the landing-page registries under config/seo-pages/.
 *
 * Every lookup is scoped to one registry (a filename in that folder), which a
 * Site names via Site::seoRegistry(). Two sites can share a registry; a site
 * whose registry doesn't exist simply has no landing pages, rather than
 * inheriting another site's.
 */
class SeoPageResolver
{
    /**
     * Look up a landing page by slug within a registry. Returns null if the
     * registry doesn't exist or doesn't contain the slug.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $registry, string $slug): ?array
    {
        $page = config("seo-pages.{$registry}.{$slug}");

        if (! is_array($page)) {
            return null;
        }

        // Fill in defaults so callers don't have to worry about missing keys.
        return array_merge([
            'slug' => $slug,
            'filters' => [],
            'h1' => '',
            'title' => '',
            'meta' => '',
            'canonical' => null,     // null means THIS page is canonical
            'priority' => 0.7,
            'changefreq' => 'daily',
        ], $page);
    }

    /**
     * All pages in a registry, with defaults applied.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(string $registry): array
    {
        $result = [];

        foreach (array_keys(config("seo-pages.{$registry}", [])) as $slug) {
            $result[$slug] = $this->find($registry, $slug);
        }

        return $result;
    }

    /**
     * Only the canonical pages (no aliases). Used by the sitemap so we don't
     * submit duplicate content to Google.
     *
     * @return array<string, array<string, mixed>>
     */
    public function canonical(string $registry): array
    {
        return array_filter($this->all($registry), fn ($page) => empty($page['canonical']));
    }

    /**
     * Every slug across every registry — what routes/web.php registers, so one
     * route table serves all sites. Which of them actually resolve on a given
     * domain is decided per request by find().
     *
     * @return array<int, string>
     */
    public function allSlugs(): array
    {
        $slugs = [];

        foreach (config('seo-pages', []) as $pages) {
            foreach (array_keys($pages) as $slug) {
                $slugs[$slug] = true;
            }
        }

        return array_keys($slugs);
    }

    /**
     * Resolve a slug to its canonical URL path (with leading slash).
     * If the slug is already canonical, returns its own path.
     */
    public function canonicalUrlFor(array $page): string
    {
        $slug = ! empty($page['canonical']) ? $page['canonical'] : $page['slug'];

        return '/'.$slug;
    }
}
