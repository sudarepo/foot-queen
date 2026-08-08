<?php

namespace Tests\Feature;

use App\Models\PageViewEvent;
use App\Models\Site;
use App\Services\HomepageAbTest;
use App\Services\HomepageLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageAbTestTest extends TestCase
{
    use RefreshDatabase;

    private const BOT_UA = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    public function test_bots_always_see_the_grid_with_no_cookie_and_no_page_view_logged(): void
    {
        $response = $this->withHeader('User-Agent', self::BOT_UA)->get('/');

        $response->assertStatus(200);
        $response->assertViewIs('cams.index');
        $response->assertCookieMissing(HomepageAbTest::COOKIE_NAME);
        $this->assertSame(0, PageViewEvent::count());
    }

    public function test_bots_visiting_feed_directly_log_no_page_view(): void
    {
        $this->withHeader('User-Agent', self::BOT_UA)->get('/feed');

        $this->assertSame(0, PageViewEvent::count());
    }

    public function test_a_returning_grid_visitor_stays_on_the_grid_and_logs_one_view(): void
    {
        $response = $this->withCookie(HomepageAbTest::COOKIE_NAME, 'grid')->get('/');

        $response->assertStatus(200);
        $response->assertViewIs('cams.index');
        $response->assertCookieMissing(HomepageAbTest::COOKIE_NAME); // already assigned — no re-issue
        $this->assertSame(1, PageViewEvent::count());
        $this->assertDatabaseHas('page_view_events', ['page' => 'grid']);
    }

    public function test_a_returning_feed_visitor_is_redirected_and_logs_exactly_one_view_total(): void
    {
        $response = $this->withCookie(HomepageAbTest::COOKIE_NAME, 'feed')->get('/');

        $response->assertRedirect(route('cams.feed'));
        // index() must not log a view on the feed branch — only the page that
        // actually renders should, otherwise a "/" -> "/feed" hop double-counts.
        $this->assertSame(0, PageViewEvent::count());

        $this->withCookie(HomepageAbTest::COOKIE_NAME, 'feed')->get('/feed');

        $this->assertSame(1, PageViewEvent::count());
        $this->assertDatabaseHas('page_view_events', ['page' => 'feed']);
    }

    public function test_a_first_time_visitor_receives_a_new_assignment_cookie(): void
    {
        $response = $this->get('/');

        $response->assertCookie(HomepageAbTest::COOKIE_NAME);
    }

    public function test_a_first_time_visitor_logs_exactly_one_page_view_end_to_end(): void
    {
        // followingRedirects() carries cookies across the "/" -> "/feed" hop
        // within the same request cycle, same as a real browser would — this
        // exercises the full flow regardless of which variant gets assigned.
        $response = $this->followingRedirects()->get('/');

        $response->assertStatus(200);
        $this->assertSame(1, PageViewEvent::count());
    }

    public function test_visiting_feed_directly_logs_exactly_one_view(): void
    {
        $this->get('/feed');

        $this->assertSame(1, PageViewEvent::count());
        $this->assertDatabaseHas('page_view_events', ['page' => 'feed']);
    }

    /* ----------  Per-site, per-device layout  ---------- */

    private const IPHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    private const DESKTOP_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /**
     * Pins the site every test request resolves to (the seeded default).
     */
    private function pinLayouts(HomepageLayout $desktop, HomepageLayout $mobile): void
    {
        Site::query()->where('is_default', true)->firstOrFail()->update([
            'home_layout_desktop' => $desktop,
            'home_layout_mobile' => $mobile,
        ]);
    }

    public function test_a_site_pinned_to_the_grid_overrides_a_visitors_feed_cookie(): void
    {
        $this->pinLayouts(HomepageLayout::Grid, HomepageLayout::Grid);

        $response = $this->withHeader('User-Agent', self::DESKTOP_UA)
            ->withCookie(HomepageAbTest::COOKIE_NAME, HomepageAbTest::VARIANT_FEED)
            ->get('/');

        $response->assertOk();
        $response->assertViewIs('cams.index');
        $this->assertDatabaseHas('page_view_events', ['page' => 'grid']);
    }

    public function test_a_site_pinned_to_the_feed_overrides_a_visitors_grid_cookie(): void
    {
        $this->pinLayouts(HomepageLayout::Feed, HomepageLayout::Feed);

        $this->withHeader('User-Agent', self::DESKTOP_UA)
            ->withCookie(HomepageAbTest::COOKIE_NAME, HomepageAbTest::VARIANT_GRID)
            ->get('/')
            ->assertRedirect(route('cams.feed'));
    }

    public function test_a_pinned_layout_issues_no_assignment_cookie(): void
    {
        $this->pinLayouts(HomepageLayout::Grid, HomepageLayout::Grid);

        $this->withHeader('User-Agent', self::DESKTOP_UA)
            ->get('/')
            ->assertCookieMissing(HomepageAbTest::COOKIE_NAME);
    }

    public function test_the_layout_is_chosen_per_device(): void
    {
        $this->pinLayouts(desktop: HomepageLayout::Grid, mobile: HomepageLayout::Feed);

        $this->withHeader('User-Agent', self::DESKTOP_UA)->get('/')
            ->assertOk()
            ->assertViewIs('cams.index');

        $this->withHeader('User-Agent', self::IPHONE_UA)->get('/')
            ->assertRedirect(route('cams.feed'));
    }

    public function test_client_hints_decide_the_device_where_the_browser_sends_them(): void
    {
        $this->pinLayouts(desktop: HomepageLayout::Grid, mobile: HomepageLayout::Feed);

        // A desktop-shaped user agent, but the browser itself says phone.
        $this->withHeader('User-Agent', self::DESKTOP_UA)
            ->withHeader('Sec-CH-UA-Mobile', '?1')
            ->get('/')
            ->assertRedirect(route('cams.feed'));
    }

    public function test_one_device_can_keep_testing_while_the_other_is_pinned(): void
    {
        $this->pinLayouts(desktop: HomepageLayout::AbTest, mobile: HomepageLayout::Grid);

        // The same visitor, carrying the same assignment cookie, on each kind
        // of screen: desktop is still an experiment so the cookie decides,
        // mobile is settled so the site does.
        $this->withCookie(HomepageAbTest::COOKIE_NAME, HomepageAbTest::VARIANT_FEED);

        $this->withHeader('User-Agent', self::DESKTOP_UA)
            ->get('/')
            ->assertRedirect(route('cams.feed'));

        $this->withHeader('User-Agent', self::IPHONE_UA)
            ->get('/')
            ->assertOk()
            ->assertViewIs('cams.index')
            ->assertCookieMissing(HomepageAbTest::COOKIE_NAME);
    }

    public function test_bots_still_get_the_grid_at_the_homepage_when_the_site_is_pinned_to_the_feed(): void
    {
        $this->pinLayouts(HomepageLayout::Feed, HomepageLayout::Feed);

        $response = $this->withHeader('User-Agent', self::BOT_UA)->get('/');

        $response->assertOk();
        $response->assertViewIs('cams.index');
        $this->assertSame(0, PageViewEvent::count());
    }

    public function test_the_feed_url_keeps_working_when_the_site_is_pinned_to_the_grid(): void
    {
        $this->pinLayouts(HomepageLayout::Grid, HomepageLayout::Grid);

        // Indexed and bookmarked URLs don't stop existing because the site
        // stopped sending visitors to them.
        $this->withHeader('User-Agent', self::DESKTOP_UA)->get('/feed')
            ->assertOk()
            ->assertViewIs('cams.feed');
    }

    public function test_the_sitemap_drops_the_feed_only_when_no_device_can_reach_it(): void
    {
        $this->get('/sitemap.xml')->assertOk()->assertSee(url('/feed'), false);

        $this->pinLayouts(desktop: HomepageLayout::Grid, mobile: HomepageLayout::AbTest);
        $this->get('/sitemap.xml')->assertOk()->assertSee(url('/feed'), false);

        $this->pinLayouts(HomepageLayout::Grid, HomepageLayout::Grid);
        $this->get('/sitemap.xml')->assertOk()->assertDontSee(url('/feed'), false);
    }
}
