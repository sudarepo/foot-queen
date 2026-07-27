<?php

namespace Tests\Feature;

use App\Models\Cam;
use App\Models\CamClickEvent;
use App\Models\PageViewEvent;
use App\Models\User;
use App\Services\HomepageAbTest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentAdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_an_authenticated_user_can_access_the_admin_panel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin')->assertSuccessful();
    }

    public function test_an_authenticated_user_can_view_the_conversion_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/conversion-dashboard')->assertSuccessful();
    }

    public function test_an_authenticated_user_can_view_the_cams_list(): void
    {
        $user = User::factory()->create();
        Cam::factory()->count(3)->create();

        $this->actingAs($user)->get('/admin/cams')->assertSuccessful();
    }

    public function test_an_authenticated_user_can_view_a_single_cam(): void
    {
        $user = User::factory()->create();
        $cam = Cam::factory()->create();

        $this->actingAs($user)->get("/admin/cams/{$cam->id}")->assertSuccessful();
    }

    public function test_the_cam_resource_has_no_create_or_edit_routes(): void
    {
        $user = User::factory()->create();
        $cam = Cam::factory()->create();

        $this->actingAs($user)->get('/admin/cams/create')->assertNotFound();
        $this->actingAs($user)->get("/admin/cams/{$cam->id}/edit")->assertNotFound();
    }

    public function test_the_cams_list_links_to_a_tracked_visit_room_url(): void
    {
        $user = User::factory()->create();
        $cam = Cam::factory()->create();

        $this->actingAs($user)
            ->get('/admin/cams')
            ->assertSee(route('cams.redirect', [$cam, 'src' => 'admin']), false);
    }

    public function test_the_cam_view_page_links_to_a_tracked_visit_room_url(): void
    {
        $user = User::factory()->create();
        $cam = Cam::factory()->create();

        $this->actingAs($user)
            ->get("/admin/cams/{$cam->id}")
            ->assertSee(route('cams.redirect', [$cam, 'src' => 'admin']), false);
    }

    public function test_the_conversion_dashboard_explains_what_the_numbers_mean(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/conversion-dashboard')
            ->assertSee('a real (non-bot) page load', false)
            ->assertSee('clicking from a cam card', false)
            ->assertSee('clicks ÷ views', false);
    }

    public function test_the_conversion_dashboard_default_date_filter_excludes_pre_launch_data(): void
    {
        $user = User::factory()->create();

        // Before the A/B split shipped — should be excluded by the default
        // "from" filter (HomepageAbTest::LAUNCHED_AT).
        PageViewEvent::create(['page' => 'grid', 'created_at' => '2026-01-01 00:00:00']);

        // Within the default range.
        PageViewEvent::create(['page' => 'grid', 'created_at' => now()]);
        $cam = Cam::factory()->create();
        CamClickEvent::create(['cam_id' => $cam->id, 'source_page' => 'grid', 'created_at' => now()]);

        $response = $this->actingAs($user)->get('/admin/conversion-dashboard');

        $response->assertStatus(200);
        // 1 view in range (not 2 — the pre-launch one is excluded by default).
        $response->assertSee('1 clicks / 1 views', false);
        $response->assertSee(HomepageAbTest::LAUNCHED_AT, false);
    }
}
