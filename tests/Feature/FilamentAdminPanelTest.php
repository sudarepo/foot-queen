<?php

namespace Tests\Feature;

use App\Models\Cam;
use App\Models\CamClickEvent;
use App\Models\PageViewEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\HomepageAbTest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        $site = Site::query()->where('is_default', true)->firstOrFail();

        // Before the A/B split shipped — should be excluded by the default
        // "from" filter (HomepageAbTest::LAUNCHED_AT).
        PageViewEvent::create(['site_id' => $site->id, 'page' => 'grid', 'created_at' => '2026-01-01 00:00:00']);

        // Within the default range.
        PageViewEvent::create(['site_id' => $site->id, 'page' => 'grid', 'created_at' => now()]);
        $cam = Cam::factory()->create();
        CamClickEvent::create(['site_id' => $site->id, 'cam_id' => $cam->id, 'source_page' => 'grid', 'created_at' => now()]);

        $response = $this->actingAs($user)->get('/admin/conversion-dashboard');

        $response->assertStatus(200);
        // 1 view in range (not 2 — the pre-launch one is excluded by default).
        $response->assertSee('1 clicks / 1 views', false);
        $response->assertSee(HomepageAbTest::LAUNCHED_AT, false);
    }

    /**
     * With several domains logging into the same two tables, a pooled CTR
     * averages unrelated audiences and describes neither, so the dashboard
     * measures one site at a time and defaults to the default site.
     */
    public function test_the_conversion_dashboard_counts_only_the_selected_sites_traffic(): void
    {
        $user = User::factory()->create();
        $site = Site::query()->where('is_default', true)->firstOrFail();
        $other = Site::factory()->create();

        $cam = Cam::factory()->create();

        PageViewEvent::create(['site_id' => $site->id, 'page' => 'grid', 'created_at' => now()]);
        CamClickEvent::create(['site_id' => $site->id, 'cam_id' => $cam->id, 'source_page' => 'grid', 'created_at' => now()]);

        // Another domain's traffic, which must not land in these numbers.
        foreach (range(1, 5) as $ignored) {
            PageViewEvent::create(['site_id' => $other->id, 'page' => 'grid', 'created_at' => now()]);
        }

        $this->actingAs($user)
            ->get('/admin/conversion-dashboard')
            ->assertSee('1 clicks / 1 views', false);
    }

    public function test_an_authenticated_user_can_manage_sites(): void
    {
        $user = User::factory()->create();
        $site = Site::query()->where('is_default', true)->firstOrFail();

        $this->actingAs($user)->get('/admin/sites')->assertSuccessful();
        $this->actingAs($user)->get('/admin/sites/create')->assertSuccessful();
        $this->actingAs($user)->get("/admin/sites/{$site->id}/edit")->assertSuccessful();
    }

    /**
     * A site with nothing uploaded is still serving the files in public/, so
     * the branding tab has to show them — otherwise it reads as "no logo".
     */
    public function test_the_branding_tab_previews_the_public_fallbacks_when_nothing_is_uploaded(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create(['logo_path' => null, 'favicon_path' => null]);

        $this->actingAs($user)
            ->get("/admin/sites/{$site->id}/edit")
            ->assertSee('Logo in use now')
            ->assertSee('Favicon in use now')
            ->assertSee('img/logo.png', false)
            ->assertSee('favicon-48x48.png', false);
    }

    /**
     * Once there's an upload, the "in use now" preview switches from the
     * public/ fallback to this site's own file — it never disappears,
     * because something is always being served and worth showing.
     */
    public function test_the_branding_tab_previews_the_sites_own_upload_once_one_exists(): void
    {
        Storage::fake('public');
        Storage::disk('public')->putFileAs('sites/logos', UploadedFile::fake()->image('custom.png'), 'custom.png');
        Storage::disk('public')->putFileAs('sites/favicons', UploadedFile::fake()->image('custom.png'), 'custom.png');

        $user = User::factory()->create();
        $site = Site::factory()->create([
            'logo_path' => 'sites/logos/custom.png',
            'favicon_path' => 'sites/favicons/custom.png',
        ]);

        $this->actingAs($user)
            ->get("/admin/sites/{$site->id}/edit")
            ->assertSee('Logo in use now — uploaded for this site.')
            ->assertSee('Favicon in use now — uploaded for this site.')
            ->assertSee('storage/sites/logos/custom.png', false)
            ->assertSee('storage/sites/favicons/custom.png', false);
    }
}
