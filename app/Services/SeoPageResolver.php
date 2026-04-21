<?php

namespace App\Services;

class SeoPageResolver
{
    /**
     * Look up a landing page by slug. Returns null if the slug isn't registered.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $slug): ?array
    {
        $pages = config('seo-pages', []);
        $page  = $pages[$slug] ?? null;

        if ($page === null) {
            return null;
        }

        // Fill in defaults so callers don't have to worry about missing keys.
        return array_merge([
            'slug'       => $slug,
            'filters'    => [],
            'h1'         => '',
            'title'      => '',
            'meta'       => '',
            'canonical'  => null,     // null means THIS page is canonical
            'priority'   => 0.7,
            'changefreq' => 'daily',
        ], $page);
    }

    /**
     * All registered pages, with defaults applied.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $pages = config('seo-pages', []);
        $result = [];
        foreach (array_keys($pages) as $slug) {
            $result[$slug] = $this->find($slug);
        }
        return $result;
    }

    /**
     * Only the canonical pages (no aliases). Used by the sitemap so we don't
     * submit duplicate content to Google.
     *
     * @return array<string, array<string, mixed>>
     */
    public function canonical(): array
    {
        return array_filter($this->all(), fn ($p) => empty($p['canonical']));
    }

    /**
     * Resolve a slug to its canonical URL path (with leading slash).
     * If the slug is already canonical, returns its own path.
     */
    public function canonicalUrlFor(array $page): string
    {
        $slug = !empty($page['canonical']) ? $page['canonical'] : $page['slug'];
        return '/' . $slug;
    }
}
