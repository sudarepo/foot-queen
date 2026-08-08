<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per domain this codebase serves.
 *
 * Everything that used to be hardcoded for Foot Queen — the name in the
 * <title>, the logo, the Chaturbate tags the sync searches, the category the
 * listing defaults to — moves here so a second domain is a Filament record
 * rather than a fork.
 *
 * The seed at the bottom recreates the current Foot Queen setup exactly, so
 * this migration is a no-op behaviourally: the site that already exists keeps
 * every string, tag, and tracking label it had before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();

            $table->string('slug', 64)->unique();       // 'foot-queen' — asset folder + analytics label
            $table->string('name', 120);                // 'Foot Queen Cams'

            /**
             * Hostnames that resolve to this site, without scheme or port.
             * Include local dev hosts here too (e.g. 'foot-queen.test').
             */
            $table->json('domains')->nullable();

            /**
             * Fallback when the request host matches no site at all — a bare
             * IP, a preview URL, a domain pointed at the server before it has
             * a record. Exactly one site holds this; Site::booted() enforces it.
             */
            $table->boolean('is_default')->default(false)->index();

            /**
             * Inactive sites keep their record but stop resolving *and* stop
             * contributing their tags to the sync, so parking a domain doesn't
             * mean it keeps costing outbound API calls.
             */
            $table->boolean('is_active')->default(true)->index();

            /* ----------  Branding  ---------- */

            $table->string('logo_path')->nullable();        // storage disk path, or null for text-only logo
            $table->string('og_image_path')->nullable();
            $table->string('accent_color', 16)->nullable(); // overrides --accent
            $table->string('theme_color', 16)->nullable();  // <meta name="theme-color">

            /**
             * Header nav. Array of {label, url} — mostly cross-promotion to the
             * operator's other domains, which is by definition per-site.
             */
            $table->json('nav_links')->nullable();

            /* ----------  What this site is about  ---------- */

            /**
             * The site's slice of the shared cam pool, applied by
             * Cam::scopeForSite(). Null gender means every gender; empty tags
             * means no tag narrowing. Unlike `default_category` below, this is
             * a hard boundary — no filter in the UI can escape it, or a foot
             * site would start showing another site's performers.
             *
             * `tags` doubles as the sync's search keywords: ChaturbateProvider
             * fetches the union of every active site's tags in one run.
             */
            $table->string('gender', 16)->nullable();
            $table->json('tags')->nullable();

            /**
             * Soft default for the category dropdown — pre-selected, but
             * clearable via "All categories" (which still can't leave `tags`).
             */
            $table->string('default_category', 32)->nullable();

            /**
             * Categories offered in the filter dropdown. Null falls back to
             * config('cam-taxonomy.featured_categories').
             */
            $table->json('featured_categories')->nullable();

            /* ----------  Copy & SEO  ---------- */

            $table->string('home_h1', 160)->nullable();
            $table->string('home_title', 200)->nullable();
            $table->string('home_meta', 320)->nullable();
            $table->string('feed_meta', 320)->nullable();
            $table->string('tagline', 320)->nullable();       // sub-heading + default meta description
            $table->string('meta_keywords', 320)->nullable(); // omitted from the <head> entirely when null

            /**
             * Profile page copy. `profile_noun` is the phrase used to describe
             * a performer when they have no bio of their own to quote.
             */
            $table->string('profile_title_live', 120)->nullable();
            $table->string('profile_title_offline', 120)->nullable();
            $table->string('profile_noun', 120)->nullable();

            /**
             * Which registry under config/seo-pages/ supplies this site's
             * landing pages. Sites sharing a niche can share a registry.
             */
            $table->string('seo_pages', 64)->nullable();

            /* ----------  Affiliate tracking  ---------- */

            /**
             * Prefixes the `track` sub-id on outbound Chaturbate links
             * ('fq' → 'fq-grid'), so revenue is attributable per domain in
             * Chaturbate's own affiliate dashboard. Null keeps the bare
             * source label ('grid'), which is what Foot Queen already reports
             * — changing it would break continuity with its existing stats.
             */
            $table->string('track_prefix', 32)->nullable();

            /**
             * GA4 property for this domain (see layouts/_analytics). Each site
             * reports into its own property — pooling several domains into one
             * would make every audience metric an average of unrelated
             * traffic. Null simply emits no tag.
             */
            $table->string('ga_measurement_id', 32)->nullable();

            $table->timestamps();
        });

        $this->seedFootQueen();
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }

    /**
     * The site that already exists, written out as data.
     *
     * Every value here is lifted verbatim from where it used to be hardcoded:
     * CamController's DEFAULT_CATEGORY and page copy, ChaturbateProvider::TAGS,
     * and layouts/app.blade.php.
     */
    private function seedFootQueen(): void
    {
        $now = now();

        DB::table('sites')->insert([
            'slug' => 'foot-queen',
            'name' => 'Foot Queen Cams',
            'domains' => json_encode([]),
            'is_default' => true,
            'is_active' => true,

            'logo_path' => null,        // still served from public/img/logo.png — see Site::logoUrl()
            'og_image_path' => null,
            'accent_color' => null,
            'theme_color' => '#0b0b0d',
            'nav_links' => json_encode([
                ['label' => 'Girls', 'url' => '/girls'],
                ['label' => 'Guys', 'url' => 'http://gaycams.xxx'],
                ['label' => 'Trans', 'url' => 'http://besttrannysex.com'],
                ['label' => 'Couples', 'url' => 'http://erotictelevision.net'],
            ]),

            'gender' => 'female',
            'tags' => json_encode(['feet', 'footfetish', 'toes']),
            'default_category' => 'feet',
            'featured_categories' => null,

            'home_h1' => 'Live Feet Cams',
            'home_title' => 'Live Feet Cams — Watch Free Foot Fetish Webcams Now',
            'home_meta' => 'Feet, soles, and toes live on cam right now. Filter by age, hair color, body type, and more.',
            'feed_meta' => 'Feet, soles, and toes live on cam right now. Scroll the feed and tap in.',
            'tagline' => 'Watch sexy feet, soles, toes, and foot worship cams streaming 24/7 from verified performers.',
            'meta_keywords' => null,

            'profile_title_live' => 'Live Feet Cam',
            'profile_title_offline' => 'Feet Cam Profile, Pics & Videos',
            'profile_noun' => 'live feet and foot fetish cam',

            'seo_pages' => 'foot-queen',
            'track_prefix' => null,
            'ga_measurement_id' => 'G-LYD5B7B36X',

            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
