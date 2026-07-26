<?php

namespace Tests\Feature;

use App\Models\PageViewEvent;
use App\Services\HomepageAbTest;
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
}
