<?php

namespace Tests\Feature;

use App\Models\Cam;
use App\Models\CamClickEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CamClickTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_clicking_through_from_the_feed_logs_its_source(): void
    {
        $cam = Cam::factory()->create();

        $response = $this->get(route('cams.redirect', [$cam, 'src' => 'feed']));

        $response->assertRedirect($cam->room_url.'?track=feed');
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

        $response->assertRedirect($cam->room_url.'?track=admin');
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

        $response->assertRedirect('https://chaturbate.com/in/?tour=LQps&campaign=Vg4Qi&track=feed&room=foxfilms');
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
        $response->assertRedirect($cam->room_url.'?track=grid');
        $this->assertSame(0, CamClickEvent::count());
    }
}
