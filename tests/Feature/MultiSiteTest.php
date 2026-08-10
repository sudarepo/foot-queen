<?php

namespace Tests\Feature;

use App\Models\Cam;
use App\Models\CamClickEvent;
use App\Models\PageViewEvent;
use App\Models\Site;
use App\Services\HomepageAbTest;
use App\Services\Providers\ChaturbateProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * One deploy, one cam pool, several domains.
 *
 * The seeded 'foot-queen' site (created by the sites migration) is the
 * baseline throughout: these tests assert both that a second domain gets its
 * own identity and listing, and that the original keeps behaving exactly as it
 * did when all of this was hardcoded.
 */
class MultiSiteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Visit a homepage pinned to the grid variant. "/" is subject to the live
     * 50/50 A/B split (the test client's User-Agent isn't bot-shaped), and a
     * redirect to /feed has none of the markup these tests assert on.
     */
    private function gridVisit(string $url): TestResponse
    {
        return $this->withCookie(HomepageAbTest::COOKIE_NAME, HomepageAbTest::VARIANT_GRID)->get($url);
    }

    /**
     * An absolute URL on the default site's host.
     *
     * Relative URLs can't be mixed with absolute ones here: the test client
     * builds them with url(), whose root comes from the *previous* request, so
     * a plain '/feed' after a request to another domain silently stays on that
     * domain. Production has no such carry-over — every request stands alone —
     * but the tests have to be explicit about which host they mean.
     */
    private function defaultHost(string $path): string
    {
        return rtrim(config('app.url'), '/').$path;
    }

    private function footQueen(): Site
    {
        return Site::query()->where('slug', 'foot-queen')->firstOrFail();
    }

    /**
     * A second domain sharing the pool, defined by a different niche.
     */
    private function bbwSite(): Site
    {
        return Site::factory()->create([
            'slug' => 'bbw-cams',
            'name' => 'BBW Cams',
            'domains' => ['bbwcams.test'],
            'gender' => 'female',
            'tags' => ['bbw'],
            'home_h1' => 'Live BBW Cams',
            'home_title' => 'Live BBW Cams',
            'tagline' => 'Curvy performers streaming now.',
            'seo_pages' => 'foot-queen',
        ]);
    }

    /* ----------  Host resolution  ---------- */

    public function test_a_request_resolves_the_site_matching_its_host(): void
    {
        $this->bbwSite();
        Cam::factory()->create(['tags' => ['bbw'], 'categories' => ['bbw']]);

        $this->gridVisit('http://bbwcams.test/')
            ->assertOk()
            ->assertSee('BBW Cams', false)
            ->assertDontSee('Foot Queen Cams', false);
    }

    public function test_an_unknown_host_falls_back_to_the_default_site(): void
    {
        $this->bbwSite();

        // No site claims this host, so the fallback decides what gets served
        // rather than the first row in the table.
        $this->gridVisit('http://some-parked-domain.test/')
            ->assertOk()
            ->assertSee('Foot Queen Cams', false);
    }

    public function test_promoting_a_default_site_demotes_the_previous_one(): void
    {
        $bbw = $this->bbwSite();

        $bbw->update(['is_default' => true]);

        $this->assertTrue($bbw->fresh()->is_default);
        $this->assertFalse($this->footQueen()->is_default);
    }

    public function test_an_inactive_site_stops_serving_its_domain(): void
    {
        $bbw = $this->bbwSite();
        $bbw->update(['is_active' => false]);

        // Falls through to the default rather than 404ing — the domain still
        // points here, and a parked site shouldn't mean a broken one.
        $this->gridVisit('http://bbwcams.test/')
            ->assertOk()
            ->assertSee('Foot Queen Cams', false);
    }

    /* ----------  The shared pool, sliced per site  ---------- */

    public function test_each_site_only_lists_performers_matching_its_tags(): void
    {
        $this->bbwSite();

        Cam::factory()->create(['username' => 'feetonly', 'tags' => ['feet'], 'categories' => ['feet']]);
        Cam::factory()->create(['username' => 'bbwonly', 'tags' => ['bbw'], 'categories' => ['bbw']]);

        $this->gridVisit('http://bbwcams.test/')
            ->assertSee('bbwonly')
            ->assertDontSee('feetonly');
    }

    public function test_a_performer_in_both_niches_is_stored_once_and_shown_on_both(): void
    {
        $this->bbwSite();

        // The whole point of a shared pool: one row, one profile fetch, two
        // domains showing it.
        Cam::factory()->create([
            'username' => 'curvytoes',
            'tags' => ['feet', 'bbw'],
            'categories' => ['feet', 'bbw'],
        ]);

        $this->assertSame(1, Cam::query()->count());

        $this->get('http://bbwcams.test/feed')->assertSee('curvytoes');
        $this->get($this->defaultHost('/feed'))->assertSee('curvytoes');
    }

    public function test_clearing_the_category_filter_still_cannot_leave_the_site(): void
    {
        Cam::factory()->create(['username' => 'feetgirl', 'tags' => ['feet'], 'categories' => ['feet']]);
        Cam::factory()->create(['username' => 'outsider', 'tags' => ['bbw'], 'categories' => ['bbw']]);

        // "All categories" submits an empty category, which clears the site's
        // default — but the site's tags are a boundary, not a filter.
        $this->get($this->defaultHost('/feed?category='))
            ->assertSee('feetgirl')
            ->assertDontSee('outsider');
    }

    public function test_a_profile_outside_the_site_is_not_found_there(): void
    {
        $this->bbwSite();
        $cam = Cam::factory()->create(['username' => 'feetonly', 'tags' => ['feet'], 'categories' => ['feet']]);

        $this->get($this->defaultHost('/cam/'.$cam->username))->assertOk();
        $this->get('http://bbwcams.test/cam/'.$cam->username)->assertNotFound();
    }

    public function test_the_online_count_in_the_header_is_per_site(): void
    {
        $this->bbwSite();

        Cam::factory()->count(3)->create(['tags' => ['feet'], 'categories' => ['feet']]);
        Cam::factory()->create(['tags' => ['bbw'], 'categories' => ['bbw']]);

        $this->get('http://bbwcams.test/feed')->assertViewHas('totalOnline', 1);
        $this->get($this->defaultHost('/feed'))->assertViewHas('totalOnline', 3);
    }

    /* ----------  Per-site output  ---------- */

    public function test_the_sitemap_only_advertises_this_sites_performers(): void
    {
        $this->bbwSite();

        Cam::factory()->create(['username' => 'feetonly', 'tags' => ['feet'], 'categories' => ['feet']]);
        Cam::factory()->create(['username' => 'bbwonly', 'tags' => ['bbw'], 'categories' => ['bbw']]);

        $this->get('http://bbwcams.test/sitemap.xml')
            ->assertOk()
            ->assertSee('/cam/bbwonly', false)
            ->assertDontSee('/cam/feetonly', false);
    }

    public function test_robots_points_at_the_requesting_domains_own_sitemap(): void
    {
        $this->bbwSite();

        $this->get('http://bbwcams.test/robots.txt')
            ->assertOk()
            ->assertSee('Sitemap: http://bbwcams.test/sitemap.xml', false);
    }

    public function test_a_landing_page_outside_the_sites_registry_is_not_found(): void
    {
        // Routes exist for every registry's slugs, but a site with no registry
        // has no landing pages — the slug must 404 rather than render.
        $bbw = $this->bbwSite();
        $bbw->update(['seo_pages' => 'nonexistent-registry']);

        $this->get($this->defaultHost('/girls'))->assertOk();
        $this->get('http://bbwcams.test/girls')->assertNotFound();
    }

    public function test_branding_and_copy_come_from_the_site_record(): void
    {
        $bbw = $this->bbwSite();
        $bbw->update([
            'accent_color' => '#ff0000',
            'meta_keywords' => 'bbw cams, curvy',
            'theme_color' => '#123456',
        ]);

        $this->get('http://bbwcams.test/feed')
            ->assertSee('<title>Live BBW Cams — BBW Cams</title>', false)
            ->assertSee('Curvy performers streaming now.', false)
            ->assertSee('--accent: #ff0000;', false)
            ->assertSee('<meta name="keywords" content="bbw cams, curvy">', false)
            ->assertSee('<meta name="theme-color" content="#123456">', false);
    }

    public function test_a_site_without_keywords_emits_no_keywords_tag(): void
    {
        // The default, and what the site did before it was configurable.
        $this->get($this->defaultHost('/feed'))->assertDontSee('name="keywords"', false);
    }

    public function test_an_uploaded_favicon_replaces_the_shared_icon_set(): void
    {
        $this->bbwSite()->update(['favicon_path' => 'sites/favicons/bbw.png']);

        $response = $this->get('http://bbwcams.test/feed');

        $response->assertSee('<link rel="icon" href="http://bbwcams.test/storage/sites/favicons/bbw.png" type="image/png">', false)
            ->assertSee('<link rel="apple-touch-icon" href="http://bbwcams.test/storage/sites/favicons/bbw.png">', false);

        // Not one of Foot Queen's files survives, or the tab would show
        // whichever of the two the browser preferred.
        $response->assertDontSee('favicon.ico', false)
            ->assertDontSee('favicon-32x32.png', false)
            ->assertDontSee('site.webmanifest', false);
    }

    public function test_a_favicon_that_ios_cannot_render_is_not_offered_as_a_touch_icon(): void
    {
        $this->bbwSite()->update(['favicon_path' => 'sites/favicons/bbw.ico']);

        $this->get('http://bbwcams.test/feed')
            ->assertSee('type="image/x-icon"', false)
            ->assertDontSee('apple-touch-icon', false);
    }

    public function test_a_site_without_a_favicon_keeps_the_icon_set_in_public(): void
    {
        $this->get($this->defaultHost('/feed'))
            ->assertSee('<link rel="icon" href="'.asset('favicon.ico').'" sizes="any">', false)
            ->assertSee('apple-touch-icon', false)
            ->assertSee('site.webmanifest', false);
    }

    /* ----------  Attribution  ---------- */

    public function test_outbound_clicks_carry_the_sites_track_prefix(): void
    {
        $bbw = $this->bbwSite();
        $bbw->update(['track_prefix' => 'bbw']);

        $cam = Cam::factory()->create([
            'tags' => ['bbw'],
            'categories' => ['bbw'],
            'room_url' => 'https://chaturbate.com/in/?tour=dT8X&campaign=abc&track=default&room=anna',
        ]);

        $this->get('http://bbwcams.test/go/'.$cam->id.'?src=feed')
            ->assertRedirectContains('track=bbw-feed-d');
    }

    public function test_a_site_without_a_prefix_still_sends_the_bare_source_label(): void
    {
        // Foot Queen's existing affiliate history is keyed on 'grid'/'feed',
        // so the unprefixed default stays that — the device suffix is the
        // only thing added to it.
        $cam = Cam::factory()->create([
            'tags' => ['feet'],
            'categories' => ['feet'],
            'room_url' => 'https://chaturbate.com/in/?tour=dT8X&campaign=abc&track=default&room=anna',
        ]);

        $this->get($this->defaultHost('/go/'.$cam->id.'?src=grid'))->assertRedirectContains('track=grid-d');
    }

    public function test_analytics_events_record_which_site_they_happened_on(): void
    {
        $bbw = $this->bbwSite();
        $cam = Cam::factory()->create(['tags' => ['bbw'], 'categories' => ['bbw']]);

        $this->get('http://bbwcams.test/feed');
        $this->get('http://bbwcams.test/go/'.$cam->id.'?src=feed');

        $this->assertSame($bbw->id, PageViewEvent::query()->latest('id')->value('site_id'));
        $this->assertSame($bbw->id, CamClickEvent::query()->latest('id')->value('site_id'));
    }

    /* ----------  Sync  ---------- */

    public function test_the_sync_searches_the_union_of_every_active_sites_tags(): void
    {
        $this->bbwSite();
        Site::factory()->create(['tags' => ['feet'], 'gender' => 'female']);   // duplicate of foot-queen's
        Site::factory()->inactive()->create(['tags' => ['ignored'], 'gender' => 'female']);

        config([
            'cam-providers.chaturbate.affiliate_id' => 'Vg4Qi',
            'cam-providers.chaturbate.campaign' => 'default',
        ]);

        $requestedTags = [];

        Http::fake(function ($request) use (&$requestedTags) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $requestedTags[] = $query['tag'] ?? null;

            return Http::response(['count' => 0, 'results' => []]);
        });

        app(ChaturbateProvider::class)->fetchCams();

        sort($requestedTags);

        // foot-queen's three plus bbw — the duplicate 'feet' costs one fetch,
        // not two, and the inactive site costs none at all.
        $this->assertSame(['bbw', 'feet', 'footfetish', 'toes'], $requestedTags);
    }

    public function test_a_site_with_no_tags_is_searched_by_gender_alone(): void
    {
        Site::query()->delete();
        Site::factory()->create(['gender' => 'male', 'tags' => [], 'is_default' => true]);

        config(['cam-providers.chaturbate.affiliate_id' => 'Vg4Qi']);

        $queries = [];

        Http::fake(function ($request) use (&$queries) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $queries[] = $query;

            return Http::response(['count' => 0, 'results' => []]);
        });

        app(ChaturbateProvider::class)->fetchCams();

        $this->assertCount(1, $queries);
        $this->assertSame('m', $queries[0]['gender']);
        // Sending an empty tag isn't the same as sending none — the API
        // rejects a blank one outright.
        $this->assertArrayNotHasKey('tag', $queries[0]);
    }

    public function test_the_sync_stores_the_raw_tags_so_new_niches_work_without_a_taxonomy_edit(): void
    {
        config(['cam-providers.chaturbate.affiliate_id' => 'Vg4Qi']);

        Http::fake([
            'chaturbate.com/api/public/affiliates/onlinerooms/*' => Http::response([
                'count' => 1,
                'results' => [[
                    'username' => 'anna',
                    'gender' => 'f',
                    // 'pawg' is in no featured-categories list, so it would be
                    // dropped entirely if only `categories` were stored.
                    'tags' => ['Feet', 'pawg'],
                    'current_show' => 'public',
                    'num_users' => 10,
                    'chat_room_url_revshare' => 'https://chaturbate.com/anna/',
                ]],
            ]),
        ]);

        $cams = app(ChaturbateProvider::class)->fetchCams();

        $this->assertSame(['feet', 'pawg'], $cams[0]['tags']);
        $this->assertSame(['feet'], $cams[0]['categories']);
    }

    /**
     * The registry is cached, and every production cache store serializes.
     * `cache.serializable_classes` is false by default, so anything that puts
     * an object in there gets __PHP_Incomplete_Class back and every request
     * 500s — which the array store used by the rest of the suite can't show,
     * because it never serializes at all.
     */
    public function test_the_registry_round_trips_through_a_serializing_cache_store(): void
    {
        config([
            'cache.default' => 'file',
            'cache.stores.file.path' => storage_path('framework/testing/cache-'.uniqid()),
        ]);

        $bbw = $this->bbwSite();
        Site::flushRegistry();

        // First call populates the cache, second reads back what was written.
        Site::registry();
        Site::flushRegistry();
        $registry = Site::registry();

        $site = $registry->firstWhere('slug', $bbw->slug);

        $this->assertInstanceOf(Site::class, $site);
        $this->assertSame($bbw->domains, $site->domains, 'Array casts must survive the round trip.');
        $this->assertTrue($site->exists, 'Hydrated sites must still be persisted models.');
    }

    /**
     * A registry entry left behind by a release that cached the models
     * themselves unserializes to __PHP_Incomplete_Class. It never expires, so
     * it has to be thrown away on sight — otherwise every domain stays down
     * until someone runs cache:clear by hand.
     */
    public function test_an_unusable_cached_registry_is_rebuilt_rather_than_fatal(): void
    {
        $bbw = $this->bbwSite();

        // Flush first: planting the entry afterwards is what leaves the
        // in-memory memo empty and the cache holding something unusable.
        Site::flushRegistry();
        Cache::forever('sites.registry', unserialize(
            'O:8:"stdClass":0:{}',
            ['allowed_classes' => false]
        ));

        $this->assertNotNull(
            Site::registry()->firstWhere('slug', $bbw->slug),
            'A junk cache entry must be replaced by a freshly queried registry.'
        );
    }
}
