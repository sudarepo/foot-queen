<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A domain served by this codebase.
 *
 * One deploy, one database, one shared pool of cams; a Site is the lens each
 * domain looks at that pool through (see Cam::scopeForSite) plus everything
 * that makes it look like its own website. Adding a domain is a Filament
 * record and a DNS entry — no deploy.
 */
class Site extends Model
{
    use HasFactory;

    /**
     * Every active site, keyed by nothing in particular — small enough that
     * resolving a host is a PHP loop rather than a query per request.
     */
    private const REGISTRY_CACHE_KEY = 'sites.registry';

    /**
     * Hydrated registry for the current request, so the repeated lookups in
     * resolveForHost() don't re-hydrate models on every call.
     *
     * @var Collection<int, self>|null
     */
    private static ?Collection $registry = null;

    protected $guarded = [];

    protected $casts = [
        'domains' => 'array',
        'nav_links' => 'array',
        'tags' => 'array',
        'featured_categories' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(function (Site $site) {
            /**
             * "Default" is the fallback for unmatched hosts, so two of them
             * would make which site a stray request lands on depend on row
             * order. Promoting one demotes the rest.
             */
            if ($site->is_default) {
                static::query()->whereKeyNot($site->getKey())->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            static::flushRegistry();
        });

        static::deleted(fn () => static::flushRegistry());
    }

    /* ----------  Resolution  ---------- */

    /**
     * The site serving a given request host, or the default if none claims it.
     *
     * Returns null only when there are no sites at all — a fresh install
     * before the seed, which the middleware turns into a clear failure rather
     * than a half-rendered page.
     */
    public static function resolveForHost(string $host): ?self
    {
        $host = Str::lower(Str::before($host, ':'));

        $match = static::registry()->first(
            fn (self $site) => in_array($host, array_map('strtolower', $site->domains ?? []), strict: true)
        );

        return $match ?? static::registry()->firstWhere('is_default', true) ?? static::registry()->first();
    }

    /**
     * Only raw attribute rows go through the cache — never the models
     * themselves. `cache.serializable_classes` is false by default, so any
     * object put into a serializing store (database, file, redis) comes back
     * as __PHP_Incomplete_Class. Arrays survive; hydrate() turns them back
     * into models with casts intact.
     *
     * @return Collection<int, self>
     */
    public static function registry(): Collection
    {
        if (static::$registry !== null) {
            return static::$registry;
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = Cache::rememberForever(
            self::REGISTRY_CACHE_KEY,
            fn () => static::query()
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->get()
                ->map(fn (self $site) => $site->getRawOriginal())
                ->all()
        );

        return static::$registry = static::hydrate($rows);
    }

    public static function flushRegistry(): void
    {
        static::$registry = null;

        Cache::forget(self::REGISTRY_CACHE_KEY);
    }

    /* ----------  Branding  ---------- */

    /**
     * Uploaded logo if there is one, otherwise the file that has always sat in
     * public/img — so the existing site keeps its logo without a re-upload,
     * and a site with neither falls back to the text wordmark in the layout.
     */
    public function logoUrl(): ?string
    {
        if (filled($this->logo_path)) {
            return $this->uploadUrl($this->logo_path);
        }

        return file_exists(public_path('img/logo.png')) ? asset('img/logo.png') : null;
    }

    public function ogImageUrl(): string
    {
        return filled($this->og_image_path)
            ? $this->uploadUrl($this->og_image_path)
            : asset('og-image.png');
    }

    /**
     * A public-disk upload, served from whichever domain is being viewed.
     *
     * Deliberately not Storage::url(), which builds its absolute URL from
     * APP_URL: every site's logo would then load from the primary domain,
     * making each other domain's pages depend on it and quietly disclosing it
     * in the markup. asset() uses the current request's host instead.
     *
     * Assumes the public disk is the local storage:link setup (it is — see
     * config/filesystems.php); a remote disk would need Storage::url() back.
     */
    private function uploadUrl(string $path): string
    {
        return asset('storage/'.ltrim($path, '/'));
    }

    /* ----------  Copy  ---------- */

    /**
     * Copy fields fall back rather than being required, so a site created in
     * Filament with nothing but a name, a domain, and its tags is a working
     * site. The alternative is a form with a dozen mandatory text fields, or
     * a half-filled record that 500s the homepage on a null.
     */
    public function homeH1(): string
    {
        return $this->home_h1 ?: $this->name;
    }

    public function homeTitle(): string
    {
        return $this->home_title ?: $this->name;
    }

    public function homeMeta(): string
    {
        return $this->home_meta ?: (string) $this->tagline;
    }

    public function feedMeta(): string
    {
        return $this->feed_meta ?: $this->homeMeta();
    }

    public function profileTitleLive(): string
    {
        return $this->profile_title_live ?: 'Live Cam';
    }

    public function profileTitleOffline(): string
    {
        return $this->profile_title_offline ?: 'Cam Profile, Pics & Videos';
    }

    public function profileNoun(): string
    {
        return $this->profile_noun ?: 'live cam';
    }

    /**
     * Categories offered in the filter dropdown, falling back to the shared
     * taxonomy when the site doesn't narrow it.
     *
     * @return array<int, string>
     */
    public function featuredCategories(): array
    {
        return filled($this->featured_categories)
            ? $this->featured_categories
            : config('cam-taxonomy.featured_categories', []);
    }

    /**
     * The registry under config/seo-pages/ backing this site's landing pages.
     */
    public function seoRegistry(): string
    {
        return $this->seo_pages ?: $this->slug;
    }

    /**
     * The `track` sub-id sent to Chaturbate for an outbound click.
     *
     * Unprefixed sites report the bare source label, which is what Foot Queen
     * has always sent — keeping its historical affiliate stats continuous.
     */
    public function trackLabel(string $source): string
    {
        return filled($this->track_prefix) ? "{$this->track_prefix}-{$source}" : $source;
    }
}
