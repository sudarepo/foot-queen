<?php

namespace Tests\Feature;

use App\Models\Cam;
use App\Models\User;
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
}
