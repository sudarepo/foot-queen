<?php

namespace Tests\Feature;

use App\Filament\Resources\Sites\Pages\EditSite;
use App\Filament\Widgets\ChaturbateStatsWidget;
use App\Filament\Widgets\TrafficStatsWidget;
use App\Models\Cam;
use App\Models\CamClickEvent;
use App\Models\ChaturbateStatsDay;
use App\Models\PageViewEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\HomepageAbTest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
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

    /**
     * A file picked but not yet saved is the upload field's business, not the
     * "in use now" preview's — reading it there used to stringify the pending
     * TemporaryUploadedFile into the preview's src, pointing the browser at
     * /storage plus a PHP tmp path that the public disk never serves.
     */
    public function test_a_pending_upload_does_not_leak_a_temporary_path_into_the_in_use_preview(): void
    {
        Storage::fake('public');
        Storage::disk('public')->putFileAs('sites/logos', UploadedFile::fake()->image('custom.png'), 'custom.png');

        $user = User::factory()->create();
        $site = Site::factory()->create(['logo_path' => 'sites/logos/custom.png']);

        Livewire::actingAs($user)
            ->test(EditSite::class, ['record' => $site->getRouteKey()])
            ->fillForm(['logo_path' => [UploadedFile::fake()->image('replacement.png')]])
            ->assertDontSee('storage/private', false)
            ->assertDontSee('/tmp/php', false)
            ->assertSee('storage/sites/logos/custom.png', false);
    }

    /**
     * Filament's FileUpload builds its preview URL with Storage::url(), which
     * this app can't route through Site::uploadUrl(). Pinning the disk to a
     * relative URL is what keeps that preview same-origin on every domain —
     * an absolute one pointing at the primary domain makes the browser block
     * FilePond's fetch, which then sits on "Loading" forever because Filament's
     * loader has no error path.
     */
    public function test_the_upload_field_previews_uploads_from_the_domain_being_viewed(): void
    {
        $url = Storage::disk('public')->url('sites/logos/custom.png');

        $this->assertSame('/storage/sites/logos/custom.png', $url);
        $this->assertStringStartsNotWith('http', $url);
    }

    /**
     * The whole round trip the branding tab exists for, since the preview bug
     * above was only visible once a real upload was in flight.
     */
    public function test_a_logo_can_be_uploaded_through_the_branding_tab(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $site = Site::factory()->create(['logo_path' => null]);

        Livewire::actingAs($user)
            ->test(EditSite::class, ['record' => $site->getRouteKey()])
            ->fillForm(['logo_path' => [UploadedFile::fake()->image('logo.png', 200, 64)]])
            ->call('save')
            ->assertHasNoFormErrors();

        $site->refresh();

        $this->assertNotNull($site->logo_path);
        Storage::disk('public')->assertExists($site->logo_path);
    }

    public function test_an_authenticated_user_can_view_the_stats_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/stats-dashboard')->assertSuccessful();
    }

    /**
     * Mirrors the equivalent conversion-dashboard test — traffic on the
     * Stats page is site-scoped for the same reason: pooling every domain's
     * numbers together describes none of them. Tested directly against the
     * widget (rather than scraping the full page) since the exact markup
     * around a Stat's value isn't part of this app's contract.
     */
    public function test_the_stats_dashboard_counts_only_the_selected_sites_traffic(): void
    {
        $user = User::factory()->create();
        $site = Site::query()->where('is_default', true)->firstOrFail();
        $other = Site::factory()->create();

        PageViewEvent::create(['site_id' => $site->id, 'page' => 'grid', 'created_at' => now()]);

        foreach (range(1, 5) as $ignored) {
            PageViewEvent::create(['site_id' => $other->id, 'page' => 'grid', 'created_at' => now()]);
        }

        Livewire::actingAs($user)
            ->test(TrafficStatsWidget::class, ['pageFilters' => ['site_id' => $site->id]])
            ->assertSeeHtml('1 page loads in range')
            ->assertDontSeeHtml('6 page loads in range');
    }

    /**
     * The whole reason this section exists disconnected from the Site
     * filter: Chaturbate's affiliate API can't be broken down per site, so
     * picking a site above must not make the revenue card look scoped when
     * it isn't.
     */
    public function test_the_stats_dashboard_chaturbate_section_ignores_the_site_filter(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();

        ChaturbateStatsDay::create([
            'date' => '2026-08-01',
            'program' => 'Revshare',
            'payout' => 100,
            'is_ledger' => false,
            'data' => ['Date' => '2026-08-01', 'Payout' => 100],
        ]);

        Livewire::actingAs($user)
            ->test(ChaturbateStatsWidget::class, ['pageFilters' => ['site_id' => $site->id]])
            ->assertSeeHtml('$100.00');
    }

    /**
     * The regression test for the bug this design avoids: a withdrawal
     * (negative payout, "Daily Payout Request") must not silently subtract
     * itself from the revenue total.
     */
    public function test_the_stats_dashboard_excludes_ledger_rows_from_the_revenue_total(): void
    {
        $user = User::factory()->create();

        ChaturbateStatsDay::create([
            'date' => '2026-08-01',
            'program' => 'Revshare',
            'payout' => 100,
            'is_ledger' => false,
            'data' => ['Date' => '2026-08-01', 'Payout' => 100],
        ]);

        ChaturbateStatsDay::create([
            'date' => '2026-08-07',
            'program' => 'Daily Payout Request',
            'payout' => -859.94,
            'is_ledger' => true,
            'data' => ['Date' => '2026-08-07', 'Payout' => -859.94],
        ]);

        Livewire::actingAs($user)
            ->test(ChaturbateStatsWidget::class)
            ->assertSeeHtml('$100.00')
            ->assertDontSeeHtml('-759.94');
    }

    public function test_the_stats_dashboard_explains_the_chaturbate_account_wide_caveat(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/stats-dashboard')
            ->assertSee('account-wide across every domain', false)
            ->assertSee('settlement rows', false);
    }
}
