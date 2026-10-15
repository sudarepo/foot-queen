<?php

namespace App\Mcp\Support;

use App\Models\Site;
use App\Services\HomepageLayout;
use App\Services\LegalPage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The editable fields of a Site, shared by create-site and update-site so the
 * two can't drift apart. Mirrors SiteForm's limits — anything the panel would
 * refuse, the MCP server refuses too.
 */
class SiteFields
{
    public const GENDERS = ['female', 'male', 'trans', 'couple'];

    /**
     * Image columns an agent sets by URL, with the folder and types the panel
     * uses for the same upload.
     *
     * @var array<string, array{column: string, directory: string, mimes: array<int, string>}>
     */
    public const IMAGES = [
        'logo_url' => ['column' => 'logo_path', 'directory' => 'sites/logos', 'mimes' => ['image/png', 'image/jpeg', 'image/gif', 'image/webp']],
        'favicon_url' => ['column' => 'favicon_path', 'directory' => 'sites/favicons', 'mimes' => ['image/png', 'image/svg+xml', 'image/x-icon', 'image/vnd.microsoft.icon']],
        'og_image_url' => ['column' => 'og_image_path', 'directory' => 'sites/og', 'mimes' => ['image/png', 'image/jpeg', 'image/gif', 'image/webp']],
    ];

    /**
     * @return array<string, Type>
     */
    public static function schema(JsonSchema $schema): array
    {
        $string = fn (string $description) => $schema->string()->nullable()->description($description);
        $strings = fn (string $description) => $schema->array()->items($schema->string())->description($description);

        return [
            'name' => $schema->string()->description('Site name, shown in the header, <title>, footer and Open Graph tags. Max 120 chars.'),
            'slug' => $schema->string()->description('Internal key (alpha-dash, max 64, unique) — asset folders and default SEO registry. Avoid changing on a live site.'),
            'domains' => $strings('Hostnames that serve this site — no scheme, no port, e.g. ["example.com", "www.example.com"]. Replaces the whole list.'),
            'is_active' => $schema->boolean()->description('Inactive sites stop resolving and stop contributing tags to the cam sync.'),
            'is_default' => $schema->boolean()->description('Serves any unmatched host. Turning this on turns it off on every other site.'),
            'accent_color' => $string('Hex colour, e.g. #ff3366.'),
            'theme_color' => $string('Hex colour for the browser chrome, e.g. #111111.'),
            'nav_links' => $schema->array()->items($schema->object([
                'label' => $schema->string()->required(),
                'url' => $schema->string()->required(),
            ]))->description('Header navigation links. Replaces the whole list.'),
            'gender' => $schema->string()->enum([...self::GENDERS, null])->nullable()->description('Restrict the site to one gender; null for any.'),
            'tags' => $strings('Provider tags that define the site\'s niche — the hard boundary of what it shows and what the sync fetches. Empty = everything matching gender.'),
            'featured_categories' => $strings('Category dropdown options; empty for the shared default list.'),
            'default_category' => $string('Pre-selected category on the homepage and feed; null for all.'),
            'home_h1' => $string('Homepage H1 (max 160). Falls back to the site name.'),
            'home_title' => $string('Homepage <title> (max 200). Falls back to the site name.'),
            'tagline' => $string('Tagline (max 320).'),
            'home_meta' => $string('Homepage meta description (max 320). Falls back to the tagline.'),
            'feed_meta' => $string('/feed meta description (max 320). Falls back to home_meta.'),
            'meta_keywords' => $string('Meta keywords (max 320).'),
            'profile_title_live' => $string('Profile page title suffix when the performer is live (max 120).'),
            'profile_title_offline' => $string('Profile page title suffix when offline (max 120).'),
            'profile_noun' => $string('Noun used on profile pages, e.g. "foot cam" (max 120).'),
            'seo_pages' => $schema->string()->enum([...array_keys(config('seo-pages', [])), null])->nullable()->description('Landing page registry from config/seo-pages; null to use the slug.'),
            'home_layout_desktop' => $schema->string()->enum(array_column(HomepageLayout::cases(), 'value'))->description('"ab" = 50/50 grid-vs-feed test, "grid" or "feed" to serve one outright.'),
            'home_layout_mobile' => $schema->string()->enum(array_column(HomepageLayout::cases(), 'value'))->description('As home_layout_desktop, for phones.'),
            'track_prefix' => $string('Prefix for the Chaturbate `track` sub-id (max 32). Changing it breaks continuity with existing affiliate stats.'),
            'ga_measurement_id' => $string('GA4 measurement ID, e.g. G-XXXXXXXXXX.'),
            'legal_contact_email' => $string('Address shown on the legal pages; null derives abuse@<primary domain>.'),
            'legal_pages' => $schema->object(collect(LegalPage::cases())->mapWithKeys(fn (LegalPage $page) => [
                $page->value => $schema->object([
                    'title' => $schema->string()->nullable(),
                    'body' => $schema->string()->nullable(),
                ])->nullable(),
            ])->all())->description('Per-page overrides of the standard legal text (HTML body, plain title). Only the pages you pass change; null or empty values restore the standard text.'),
            'logo_url' => $string('URL of a PNG/JPEG/GIF/WebP to download as the logo; null removes it.'),
            'favicon_url' => $string('URL of a PNG/SVG/ICO to download as the favicon; null removes it.'),
            'og_image_url' => $string('URL of a PNG/JPEG/GIF/WebP to download as the Open Graph image; null removes it.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(?Site $site = null): array
    {
        $creating = $site === null;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'slug' => ['sometimes', 'string', 'max:64', 'alpha_dash', Rule::unique('sites', 'slug')->ignore($site?->getKey())],
            'domains' => ['sometimes', 'array'],
            'domains.*' => ['string', 'max:253', 'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i'],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'accent_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-f]{3,8}$/i'],
            'theme_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-f]{3,8}$/i'],
            'nav_links' => ['sometimes', 'array'],
            'nav_links.*.label' => ['required', 'string', 'max:40'],
            'nav_links.*.url' => ['required', 'string', 'max:255'],
            'gender' => ['sometimes', 'nullable', Rule::in(self::GENDERS)],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:64'],
            'featured_categories' => ['sometimes', 'array'],
            'featured_categories.*' => ['string', 'max:64'],
            'default_category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'home_h1' => ['sometimes', 'nullable', 'string', 'max:160'],
            'home_title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:320'],
            'home_meta' => ['sometimes', 'nullable', 'string', 'max:320'],
            'feed_meta' => ['sometimes', 'nullable', 'string', 'max:320'],
            'meta_keywords' => ['sometimes', 'nullable', 'string', 'max:320'],
            'profile_title_live' => ['sometimes', 'nullable', 'string', 'max:120'],
            'profile_title_offline' => ['sometimes', 'nullable', 'string', 'max:120'],
            'profile_noun' => ['sometimes', 'nullable', 'string', 'max:120'],
            'seo_pages' => ['sometimes', 'nullable', Rule::in(array_keys(config('seo-pages', [])))],
            'home_layout_desktop' => ['sometimes', Rule::enum(HomepageLayout::class)],
            'home_layout_mobile' => ['sometimes', Rule::enum(HomepageLayout::class)],
            'track_prefix' => ['sometimes', 'nullable', 'string', 'max:32'],
            'ga_measurement_id' => ['sometimes', 'nullable', 'string', 'max:32'],
            'legal_contact_email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'legal_pages' => ['sometimes', 'array:'.implode(',', array_column(LegalPage::cases(), 'value'))],
            'legal_pages.*' => ['nullable', 'array:title,body'],
            'legal_pages.*.title' => ['nullable', 'string', 'max:160'],
            'legal_pages.*.body' => ['nullable', 'string'],
            'logo_url' => ['sometimes', 'nullable', 'url'],
            'favicon_url' => ['sometimes', 'nullable', 'url'],
            'og_image_url' => ['sometimes', 'nullable', 'url'],
        ];
    }

    /**
     * Turns validated input into model attributes: lower-cased domains,
     * downloaded images, and legal overrides merged onto what's stored —
     * with blank text dropped, the same "no opinion" SiteForm stores.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function toAttributes(array $validated, ?Site $site = null): array
    {
        $attributes = Arr::except($validated, [...array_keys(self::IMAGES), 'legal_pages']);

        if (array_key_exists('domains', $attributes)) {
            $attributes['domains'] = collect($attributes['domains'])
                ->map(fn (string $domain): string => Str::lower(trim($domain)))
                ->unique()
                ->values()
                ->all();
        }

        if (blank($site?->slug) && blank($attributes['slug'] ?? null) && filled($attributes['name'] ?? null)) {
            $attributes['slug'] = Str::slug($attributes['name']);
        }

        foreach (self::IMAGES as $argument => $image) {
            if (array_key_exists($argument, $validated)) {
                $attributes[$image['column']] = filled($validated[$argument])
                    ? RemoteImage::store($validated[$argument], $image['directory'], $image['mimes'])
                    : null;
            }
        }

        if (array_key_exists('legal_pages', $validated)) {
            $legalPages = $site?->legal_pages ?? [];

            foreach ($validated['legal_pages'] as $page => $override) {
                $title = trim((string) ($override['title'] ?? ''));
                $body = $override['body'] ?? null;

                $legalPages[$page] = [
                    'title' => $title !== '' ? $title : null,
                    'body' => Site::isBlankRichText($body) ? null : $body,
                ];
            }

            $attributes['legal_pages'] = $legalPages;
        }

        return $attributes;
    }

    /**
     * A site as the tools report it: every stored setting, plus the URLs
     * and fallbacks the public pages actually use.
     *
     * @return array<string, mixed>
     */
    public static function present(Site $site): array
    {
        return [
            ...Arr::except($site->attributesToArray(), ['legal_pages']),
            'legal_pages' => collect(LegalPage::cases())->mapWithKeys(fn (LegalPage $page) => [
                $page->value => [
                    'url_path' => '/'.$page->slug(),
                    'title_override' => $site->legalOverride($page, 'title'),
                    'body_override' => $site->legalOverride($page, 'body'),
                ],
            ])->all(),
            'is_deleted' => $site->trashed(),
            'logo_url' => filled($site->logo_path) ? $site->logoUrl() : null,
            'favicon_url' => $site->faviconUrl(),
            'og_image_url' => filled($site->og_image_path) ? $site->ogImageUrl() : null,
            'effective_seo_registry' => $site->seoRegistry(),
        ];
    }
}
