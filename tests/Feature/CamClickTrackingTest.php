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

        $response->assertRedirect($cam->room_url);
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

    public function test_click_event_belongs_to_its_cam(): void
    {
        $cam = Cam::factory()->create();
        $event = CamClickEvent::create(['cam_id' => $cam->id, 'source_page' => 'grid']);

        $this->assertTrue($event->cam->is($cam));
    }
}
