<?php

namespace Tests\Feature;

use App\Models\Cam;
use App\Models\CamClickEvent;
use App\Models\PageViewEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CamProfilePageTest extends TestCase
{
    use RefreshDatabase;

    private const BOT_UA = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    /**
     * A cam with its profile already fetched, so nothing on these tests goes
     * out to the network unless that's what's being exercised.
     */
    private function camWithProfile(array $attributes = []): Cam
    {
        return Cam::factory()->create(array_merge([
            'username' => 'anna',
            'profile_fetched_at' => now(),
            'bio' => "First paragraph.\n\nSecond paragraph.",
            'profile_attributes' => [
                'location' => 'Ukraine',
                'languages' => 'English, Українська',
                'follower_count' => 12345,
            ],
            'photo_sets' => [
                [
                    'id' => 1,
                    'name' => 'Sunset toes',
                    'cover_url' => 'https://static-pub.example.com/cover-1.jpg',
                    'tokens' => 150,
                    'is_video' => true,
                    'fan_club_only' => false,
                    'photo_count' => 0,
                    'num_videos' => 1,
                    'duration_seconds' => 227,
                ],
                [
                    'id' => 2,
                    'name' => 'Barefoot set',
                    'cover_url' => 'https://static-pub.example.com/cover-2.jpg',
                    'tokens' => 0,
                    'is_video' => false,
                    'fan_club_only' => false,
                    'photo_count' => 24,
                    'num_videos' => 0,
                    'duration_seconds' => 0,
                ],
            ],
        ], $attributes));
    }

    public function test_it_renders_a_performers_profile(): void
    {
        $cam = $this->camWithProfile();

        $response = $this->get(route('cams.show', $cam->username));

        $response->assertStatus(200);
        $response->assertViewIs('cams.show');
        $response->assertSee('anna');
        $response->assertSee('First paragraph.');
        $response->assertSee('Second paragraph.');
        $response->assertSee('Ukraine');
        $response->assertSee('12,345 followers');
    }

    public function test_it_renders_the_pics_and_vids_tab(): void
    {
        $cam = $this->camWithProfile();

        $response = $this->get(route('cams.show', $cam->username));

        $response->assertSee('Pics &amp; Vids', false);
        $response->assertSee('Sunset toes');
        $response->assertSee('Barefoot set');
        $response->assertSee('https://static-pub.example.com/cover-1.jpg', false);
        // A 227-second video, formatted from `duration_seconds`.
        $response->assertSee('03:47');
        $response->assertSee('24 pics');
        $response->assertSee('150 tk');
        $response->assertSee('Free');
    }

    public function test_videos_are_listed_before_photo_sets(): void
    {
        $cam = $this->camWithProfile();

        $ordered = $cam->orderedPhotoSets();

        $this->assertTrue($ordered[0]['is_video']);
        $this->assertFalse($ordered[1]['is_video']);
    }

    /**
     * Everything on the page that isn't navigation is a route into the room,
     * so the affiliate credit matches a listing click — and is attributed to
     * the profile page rather than the listing that led there.
     */
    public function test_every_content_link_goes_out_through_the_tracked_redirect(): void
    {
        $cam = $this->camWithProfile();

        $response = $this->get(route('cams.show', $cam->username));

        $response->assertSee(e(route('cams.redirect', [$cam, 'src' => 'profile'])), false);
        // Never the raw room URL — that would skip the click log.
        $response->assertDontSee($cam->room_url, false);
    }

    public function test_it_embeds_the_live_room_for_an_online_performer(): void
    {
        $cam = $this->camWithProfile([
            'is_online' => true,
            'embed_url' => 'https://cbxyz.com/embed/anna/?disable_sound=1',
        ]);

        $response = $this->get(route('cams.show', $cam->username));

        $response->assertSee('data-embed-url="'.e($cam->embed_url).'"', false);
        $response->assertSee('LIVE', false);
    }

    public function test_an_offline_performer_still_has_a_page(): void
    {
        $cam = $this->camWithProfile([
            'is_online' => false,
            'embed_url' => 'https://cbxyz.com/embed/anna/?disable_sound=1',
        ]);

        $response = $this->get(route('cams.show', $cam->username));

        $response->assertStatus(200);
        $response->assertSee('Offline');
        // The bio and photo sets are the durable half of the page — still there.
        $response->assertSee('Sunset toes');
        // But nothing mounts a stream for a room that isn't broadcasting.
        $response->assertDontSee('data-embed-url', false);
    }

    public function test_an_unknown_performer_404s(): void
    {
        $this->get('/cam/nobody-by-that-name')->assertStatus(404);
    }

    public function test_it_offers_the_way_back_to_the_listing_the_visitor_came_from(): void
    {
        $cam = $this->camWithProfile();

        $this->get(route('cams.show', ['cam' => $cam->username, 'from' => 'feed']))
            ->assertSee('Back to the feed')
            ->assertSee(e(route('cams.feed')), false);

        $this->get(route('cams.show', ['cam' => $cam->username, 'from' => 'grid']))
            ->assertSee('Back to all cams');

        // No `from` at all (organic search, a bookmark) still gets a way out.
        $this->get(route('cams.show', $cam->username))
            ->assertSee('Back to all cams');
    }

    public function test_it_logs_a_profile_page_view(): void
    {
        $cam = $this->camWithProfile();

        $this->get(route('cams.show', $cam->username));

        $this->assertDatabaseHas('page_view_events', ['page' => 'profile']);
    }

    /**
     * Listing cards now land here instead of on Chaturbate. Logging that
     * arrival under the listing's own name is what keeps the grid-vs-feed
     * CTR comparison measuring the same thing it measured before profile
     * pages existed.
     */
    public function test_arriving_from_a_listing_logs_a_click_for_that_listing(): void
    {
        $cam = $this->camWithProfile();

        $this->get(route('cams.show', ['cam' => $cam->username, 'from' => 'feed']));

        $this->assertDatabaseHas('cam_click_events', [
            'cam_id' => $cam->id,
            'source_page' => 'feed',
        ]);
    }

    public function test_an_unrecognized_source_is_not_logged(): void
    {
        $cam = $this->camWithProfile();

        $this->get(route('cams.show', ['cam' => $cam->username, 'from' => 'not-a-listing']));

        $this->assertSame(0, CamClickEvent::count());
    }

    public function test_a_direct_visit_logs_a_view_but_no_listing_click(): void
    {
        $cam = $this->camWithProfile();

        $this->get(route('cams.show', $cam->username));

        $this->assertSame(1, PageViewEvent::where('page', 'profile')->count());
        $this->assertSame(0, CamClickEvent::count());
    }

    public function test_bot_visits_render_but_are_not_logged(): void
    {
        $cam = $this->camWithProfile();

        $response = $this->withHeader('User-Agent', self::BOT_UA)
            ->get(route('cams.show', ['cam' => $cam->username, 'from' => 'grid']));

        $response->assertStatus(200);
        $this->assertSame(0, PageViewEvent::count());
        $this->assertSame(0, CamClickEvent::count());
    }

    public function test_outbound_clicks_from_a_profile_are_attributed_to_it(): void
    {
        $cam = $this->camWithProfile();

        $response = $this->get(route('cams.redirect', [$cam, 'src' => 'profile']));

        $response->assertRedirect($cam->room_url.'?track=profile');
        $this->assertDatabaseHas('cam_click_events', [
            'cam_id' => $cam->id,
            'source_page' => 'profile',
        ]);
    }

    /**
     * The scheduled backfill can't have reached every performer, so a page
     * that has never been fetched fills itself in on the first view rather
     * than rendering bare.
     */
    public function test_a_never_fetched_profile_is_filled_in_on_first_view(): void
    {
        Http::fake([
            'chaturbate.com/api/biocontext/*' => Http::response([
                'about_me' => '<p>Fetched on demand.</p>',
                'location' => 'Kyiv',
                'photo_sets' => [],
            ]),
        ]);

        $cam = Cam::factory()->create([
            'username' => 'anna',
            'profile_fetched_at' => null,
        ]);

        $this->get(route('cams.show', $cam->username))->assertSee('Fetched on demand.');

        Http::assertSentCount(1);
        $this->assertSame('Kyiv', $cam->fresh()->profileAttribute('location'));
    }

    public function test_an_already_fetched_profile_does_not_hit_the_network(): void
    {
        Http::fake();

        $this->get(route('cams.show', $this->camWithProfile()->username));

        Http::assertNothingSent();
    }

    /**
     * Several visitors landing on the same cold page — or a crawler working
     * through the sitemap — should produce one outbound request, not one
     * each. Whoever loses the race renders without the profile.
     */
    public function test_concurrent_first_views_only_fetch_once(): void
    {
        Http::fake([
            'chaturbate.com/api/biocontext/*' => Http::response(['about_me' => '<p>Hi.</p>']),
        ]);

        $cam = Cam::factory()->create(['username' => 'anna', 'profile_fetched_at' => null]);

        // The second request only skips the fetch because of the cache guard;
        // the first has already written `profile_fetched_at` by the time it
        // returns, so roll that back to isolate the guard itself.
        $this->get(route('cams.show', $cam->username));
        $cam->forceFill(['profile_fetched_at' => null])->save();
        $this->get(route('cams.show', $cam->username));

        Http::assertSentCount(1);
    }

    public function test_a_failed_fetch_still_renders_the_page(): void
    {
        Http::fake(['chaturbate.com/*' => Http::response('', 500)]);

        $cam = Cam::factory()->create(['username' => 'anna', 'profile_fetched_at' => null]);

        $this->get(route('cams.show', $cam->username))
            ->assertStatus(200)
            ->assertSee('anna');
    }

    public function test_online_profiles_are_in_the_sitemap(): void
    {
        $online = Cam::factory()->create(['username' => 'anna', 'is_online' => true]);
        $offline = Cam::factory()->create(['username' => 'bea', 'is_online' => false]);

        $response = $this->get('/sitemap.xml');

        $response->assertSee(route('cams.show', $online->username), false);
        // Submitting a URL whose main content is a stream that isn't running
        // invites a "crawled, not indexed" verdict.
        $response->assertDontSee(route('cams.show', $offline->username), false);
    }

    public function test_profile_pages_are_indexable(): void
    {
        $cam = $this->camWithProfile();

        $this->get(route('cams.show', $cam->username))
            ->assertSee('index,follow', false)
            ->assertSee('<link rel="canonical" href="'.e(route('cams.show', $cam->username)).'">', false);

        $this->get('/robots.txt')->assertSee('Allow: /cam/', false);
    }

    public function test_listing_cards_link_to_profile_pages(): void
    {
        $cam = Cam::factory()->create(['username' => 'anna', 'is_online' => true]);

        $this->get('/feed')
            ->assertSee(e(route('cams.show', ['cam' => 'anna', 'from' => 'feed'])), false);

        $this->withCookie('ab_feed_variant', 'grid')->get('/')
            ->assertSee(e(route('cams.show', ['cam' => 'anna', 'from' => 'grid'])), false);
    }

    /**
     * The feed's explicit "Join the room" button is a decision already made —
     * it stays a one-click path out, unlike the card itself.
     */
    public function test_the_feed_cta_still_links_straight_out(): void
    {
        $cam = Cam::factory()->create(['username' => 'anna', 'is_online' => true]);

        $this->get('/feed')
            ->assertSee(e(route('cams.redirect', [$cam, 'src' => 'feed'])), false);
    }
}
