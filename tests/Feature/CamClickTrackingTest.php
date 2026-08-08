<?php

namespace Tests\Feature;

use App\Models\Cam;
use App\Models\CamClickEvent;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CamClickTrackingTest extends TestCase
{
    use RefreshDatabase;

    private const IPHONE_USER_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    private const MACOS_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    public function test_clicking_through_from_the_feed_logs_its_source(): void
    {
        $cam = Cam::factory()->create();

        $response = $this->get(route('cams.redirect', [$cam, 'src' => 'feed']));

        $response->assertRedirect($cam->room_url.'?track=feed-d');
        $this->assertDatabaseHas('cam_click_events', [
            'cam_id' => $cam->id,
            'source_page' => 'feed',
        ]);
    }

    public function test_clicking_through_without_a_recognized_source_defaults_to_grid(): void
    {
        $cam = Cam::factory()->create();

        $this->get(route('cams.redirect', $cam));

        $this->assertDatabaseHas('cam_click_events', [
            'cam_id' => $cam->id,
            'source_page' => 'grid',
        ]);
    }

    public function test_clicking_through_with_an_unrecognized_source_falls_back_to_grid(): void
    {
        $cam = Cam::factory()->create();

        $this->get(route('cams.redirect', [$cam, 'src' => 'not-a-real-page']));

        $this->assertDatabaseHas('cam_click_events', [
            'cam_id' => $cam->id,
            'source_page' => 'grid',
        ]);
    }

    public function test_clicking_through_from_the_admin_panel_logs_its_source(): void
    {
        $cam = Cam::factory()->create();

        $response = $this->get(route('cams.redirect', [$cam, 'src' => 'admin']));

        $response->assertRedirect($cam->room_url.'?track=admin-d');
        $this->assertDatabaseHas('cam_click_events', [
            'cam_id' => $cam->id,
            'source_page' => 'admin',
        ]);
    }

    public function test_redirect_overrides_the_existing_track_param_with_the_source_so_chaturbate_reports_it_separately(): void
    {
        $cam = Cam::factory()->create([
            'room_url' => 'https://chaturbate.com/in/?tour=LQps&campaign=Vg4Qi&track=default&room=foxfilms',
        ]);

        $response = $this->get(route('cams.redirect', [$cam, 'src' => 'feed']));

        $response->assertRedirect('https://chaturbate.com/in/?tour=LQps&campaign=Vg4Qi&track=feed-d&room=foxfilms');
    }

    public function test_click_event_belongs_to_its_cam(): void
    {
        $cam = Cam::factory()->create();
        $event = CamClickEvent::create(['cam_id' => $cam->id, 'source_page' => 'grid']);

        $this->assertTrue($event->cam->is($cam));
    }

    public function test_bot_clicks_still_redirect_but_are_not_logged(): void
    {
        $cam = Cam::factory()->create();
        $botUserAgent = 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)';

        $response = $this->withHeader('User-Agent', $botUserAgent)
            ->get(route('cams.redirect', [$cam, 'src' => 'grid']));

        // The redirect itself is never blocked — only the click log is
        // bot-gated. This is what fixes CTR being inflated past 100%: a
        // crawler following /go/ links (robots.txt disallows it, but not
        // everything respects that) was logging a click with no matching
        // filtered view to divide it against.
        $response->assertRedirect($cam->room_url.'?track=grid-d');
        $this->assertSame(0, CamClickEvent::count());
    }

    /* ----------  Device  ---------- */

    public function test_clicks_from_a_phone_are_logged_as_mobile_and_reported_to_chaturbate_as_such(): void
    {
        $cam = Cam::factory()->create();

        $response = $this->withHeader('User-Agent', self::IPHONE_USER_AGENT)
            ->get(route('cams.redirect', [$cam, 'src' => 'feed']));

        // Both halves of the split matter: the suffix is how the affiliate
        // dashboard separates phone revenue from desktop revenue, and the
        // column is how our own CTR does the same for clicks.
        $response->assertRedirect($cam->room_url.'?track=feed-m');
        $this->assertDatabaseHas('cam_click_events', [
            'cam_id' => $cam->id,
            'source_page' => 'feed',
            'device' => 'mobile',
        ]);
    }

    public function test_clicks_from_a_laptop_are_logged_as_desktop(): void
    {
        $cam = Cam::factory()->create();

        $response = $this->withHeader('User-Agent', self::MACOS_USER_AGENT)
            ->get(route('cams.redirect', [$cam, 'src' => 'grid']));

        $response->assertRedirect($cam->room_url.'?track=grid-d');
        $this->assertDatabaseHas('cam_click_events', [
            'cam_id' => $cam->id,
            'source_page' => 'grid',
            'device' => 'desktop',
        ]);
    }

    /**
     * Chromium answers the question itself on every request, so where the
     * hint is present it's taken over any reading of the user-agent — here
     * a desktop-looking UA paired with the phone hint, which is exactly what
     * a Chrome-on-Android request looks like.
     */
    public function test_the_client_hint_wins_over_the_user_agent_string(): void
    {
        $cam = Cam::factory()->create();

        $response = $this
            ->withHeaders([
                'User-Agent' => self::MACOS_USER_AGENT,
                'Sec-CH-UA-Mobile' => '?1',
            ])
            ->get(route('cams.redirect', [$cam, 'src' => 'grid']));

        $response->assertRedirect($cam->room_url.'?track=grid-m');
        $this->assertDatabaseHas('cam_click_events', [
            'cam_id' => $cam->id,
            'device' => 'mobile',
        ]);
    }

    public function test_the_site_prefix_and_the_device_suffix_wrap_the_same_label(): void
    {
        Site::query()->where('is_default', true)->firstOrFail()->update(['track_prefix' => 'fq']);
        $cam = Cam::factory()->create();

        $this->withHeader('User-Agent', self::IPHONE_USER_AGENT)
            ->get(route('cams.redirect', [$cam, 'src' => 'profile']))
            ->assertRedirect($cam->room_url.'?track=fq-profile-m');
    }

    public function test_page_views_record_the_device_they_were_served_to(): void
    {
        $this->withHeader('User-Agent', self::IPHONE_USER_AGENT)->get('/feed');

        $this->assertDatabaseHas('page_view_events', [
            'page' => 'feed',
            'device' => 'mobile',
        ]);
    }
}
