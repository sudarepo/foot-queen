<?php

namespace Tests\Feature;

use App\Models\Cam;
use App\Models\PageViewEvent;
use App\Services\HomepageAbTest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CamFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_renders_the_grid_layout(): void
    {
        Cam::factory()->count(3)->create();

        // Pinned to the grid variant — the 50/50 homepage A/B split itself is
        // covered by HomepageAbTestTest; this test is about the grid render path.
        $response = $this->withCookie(HomepageAbTest::COOKIE_NAME, 'grid')->get('/');

        $response->assertStatus(200);
        $response->assertViewIs('cams.index');
    }

    public function test_feed_page_renders_the_same_online_cams_as_the_homepage(): void
    {
        Cam::factory()->count(3)->create(['is_online' => true]);
        Cam::factory()->create(['is_online' => false]);

        $response = $this->get('/feed');

        $response->assertStatus(200);
        $response->assertViewIs('cams.feed');
        $response->assertViewHas('cams', fn ($cams) => $cams->total() === 3);
    }

    public function test_feed_only_shows_cams_in_the_feet_category(): void
    {
        Cam::factory()->count(2)->create(['is_online' => true, 'categories' => ['feet', 'lovense']]);
        Cam::factory()->create(['is_online' => true, 'categories' => ['lovense']]);

        $this->get('/feed')->assertViewHas('cams', fn ($cams) => $cams->total() === 2);
    }

    public function test_grid_only_shows_cams_in_the_feet_category(): void
    {
        Cam::factory()->count(2)->create(['is_online' => true, 'categories' => ['feet', 'lovense']]);
        Cam::factory()->create(['is_online' => true, 'categories' => ['lovense']]);

        $this->withCookie(HomepageAbTest::COOKIE_NAME, 'grid')
            ->get('/')
            ->assertViewHas('cams', fn ($cams) => $cams->total() === 2);
    }

    public function test_feed_category_is_a_default_the_visitor_can_switch_away_from(): void
    {
        Cam::factory()->create(['is_online' => true, 'categories' => ['feet']]);
        Cam::factory()->create(['is_online' => true, 'categories' => ['feet', 'anal']]);

        $this->get('/feed?category=anal')->assertViewHas('cams', fn ($cams) => $cams->total() === 1);

        // "All categories" submits an empty value — a real choice, not an
        // absent one, so it clears the default rather than restoring it.
        $this->get('/feed?category=')->assertViewHas('cams', fn ($cams) => $cams->total() === 2);
    }

    public function test_feed_shows_the_filter_bar_with_the_feet_category_selected(): void
    {
        Cam::factory()->create(['is_online' => true]);

        $response = $this->get('/feed');

        $response->assertSee('class="filters-bar"', false);

        foreach (['category', 'age', 'hair', 'body'] as $param) {
            $response->assertSee('name="'.$param.'"', false);
        }

        $response->assertSee('<option value="feet" selected>', false);
    }

    public function test_grid_filter_bar_also_starts_on_the_feet_category(): void
    {
        Cam::factory()->create(['is_online' => true]);

        $this->withCookie(HomepageAbTest::COOKIE_NAME, 'grid')
            ->get('/')
            ->assertSee('<option value="feet" selected>', false);
    }

    public function test_feed_filter_bar_keeps_filters_it_does_not_render(): void
    {
        Cam::factory()->create(['is_online' => true, 'gender' => 'female']);

        $this->get('/feed?gender=female')
            ->assertSee('<input type="hidden" name="gender" value="female">', false);
    }

    public function test_header_navigation_is_present_on_the_feed(): void
    {
        Cam::factory()->create(['is_online' => true]);

        $response = $this->get('/feed');

        foreach (['Girls', 'Guys', 'Trans', 'Couples'] as $link) {
            $response->assertSee($link, false);
        }
    }

    public function test_feed_page_is_indexable_and_in_the_sitemap(): void
    {
        $this->get('/feed')
            ->assertStatus(200)
            ->assertSee('index,follow', false);

        $this->get('/sitemap.xml')->assertSee(url('/feed'), false);
    }

    public function test_feed_exposes_an_infinite_scroll_anchor_pointing_at_the_next_page(): void
    {
        Cam::factory()->count(50)->create(['is_online' => true]);

        $response = $this->get('/feed');

        $response->assertSee('id="igFeedMore"', false);
        $response->assertSee('data-next-url="'.e(url('/feed?page=2')).'"', false);
    }

    public function test_feed_hides_the_infinite_scroll_anchor_on_the_last_page(): void
    {
        Cam::factory()->count(3)->create(['is_online' => true]);

        $this->get('/feed')->assertDontSee('id="igFeedMore"', false);
    }

    public function test_partial_feed_request_returns_only_post_markup_and_the_next_page_url(): void
    {
        Cam::factory()->count(50)->create(['is_online' => true]);

        $response = $this->getJson('/feed?partial=1&page=2');

        $response->assertStatus(200);
        $response->assertJsonStructure(['html', 'next_page_url']);

        // Two full pages exactly: page 2 holds the remaining 2 cams and ends the feed.
        $this->assertNull($response->json('next_page_url'));
        $this->assertSame(2, substr_count($response->json('html'), '<article class="ig-post">'));

        // Post markup only — no layout chrome, since it's appended into an existing page.
        $this->assertStringNotContainsString('<html', $response->json('html'));
    }

    public function test_partial_feed_pages_carry_active_filters_but_not_the_partial_flag(): void
    {
        Cam::factory()->count(50)->create(['is_online' => true, 'gender' => 'female']);
        Cam::factory()->count(5)->create(['is_online' => true, 'gender' => 'male']);

        $nextPageUrl = $this->getJson('/feed?partial=1&gender=female')->json('next_page_url');

        $this->assertStringContainsString('gender=female', $nextPageUrl);
        $this->assertStringNotContainsString('partial', $nextPageUrl);
    }

    public function test_partial_feed_requests_do_not_log_extra_page_views(): void
    {
        Cam::factory()->count(50)->create(['is_online' => true]);

        $this->get('/feed');
        $this->getJson('/feed?partial=1&page=2');

        $this->assertSame(1, PageViewEvent::where('page', HomepageAbTest::VARIANT_FEED)->count());
    }

    public function test_feed_cards_expose_a_hover_preview_hook_only_when_an_embed_url_exists(): void
    {
        Cam::factory()->create([
            'username' => 'has-preview',
            'embed_url' => 'https://chaturbate.com/in/?tour=abc&campaign=Vg4Qi&track=embed&room=has-preview&disable_sound=1',
        ]);
        Cam::factory()->create(['username' => 'no-preview', 'embed_url' => null]);

        $response = $this->get('/feed');

        $response->assertSee('data-preview-video-url="https://thumb.live.mmcdn.com/roomad/has-preview.mp4"', false);
        $response->assertDontSee('data-preview-video-url="https://thumb.live.mmcdn.com/roomad/no-preview.mp4"', false);
        $this->assertSame(1, substr_count($response->getContent(), 'Live preview'));
    }
}
