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
use App\Services\LegalPage;
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
     * The legal tab lists all four pages whether or not this site has ever
     * touched them — a page you can't find in here reads as a page the site
     * doesn't have, and every site has all four.
     */
    public function test_the_legal_tab_lists_every_page_and_says_which_are_standard(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create([
            'legal_pages' => [LegalPage::Dmca->value => ['body' => '<p>Ours.</p>']],
        ]);

        $response = $this->actingAs($user)->get("/admin/sites/{$site->id}/edit");

        foreach (LegalPage::all() as $page) {
            $response->assertSee($page->title());
        }

        $response->assertSee('Rewritten for this site — /dmca', escape: false);
        $response->assertSee('Standard text — /2257', escape: false);
    }

    public function test_an_admin_can_rewrite_a_legal_page_from_the_site_form(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();

        Livewire::actingAs($user)
            ->test(EditSite::class, ['record' => $site->getRouteKey()])
            ->fillForm([
                'legal_contact_email' => 'legal@example.com',
                'legal_pages' => [
                    LegalPage::Terms->value => [
                        'title' => 'House Rules',
                        'body' => '<p>Be excellent to each other.</p>',
                    ],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $site->refresh();

        $this->assertSame('legal@example.com', $site->legal_contact_email);
        $this->assertSame('House Rules', $site->legalOverride(LegalPage::Terms, 'title'));
        $this->assertNull($site->legalOverride(LegalPage::Dmca, 'body'));

        $this->get('http://'.$site->primaryDomain().'/terms-and-conditions')
            ->assertSee('Be excellent to each other.');
    }

    /**
     * The way an admin is meant to start an override: load the standard text
     * into the editor and edit it, rather than write a legal page from
     * scratch. It has to arrive written out for *this* site.
     */
    public function test_the_legal_tab_can_load_the_standard_text_into_the_editor(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->onDomain('bbwcams.test')->create(['name' => 'BBW Cams']);

        $component = Livewire::actingAs($user)
            ->test(EditSite::class, ['record' => $site->getRouteKey()])
            ->callFormComponentAction(
                'legal_pages.'.LegalPage::Dmca->value.'.body',
                'loadDefault_'.LegalPage::Dmca->value,
            );

        /**
         * The editor holds its state as a rich text document rather than as
         * the HTML string that was handed to it, so what is asserted here is
         * that the standard text arrived in it — written out for this site,
         * not for some other one.
         */
        $loaded = json_encode(data_get($component->get('data'), 'legal_pages.'.LegalPage::Dmca->value.'.body'));

        $this->assertStringContainsString('BBW Cams', $loaded);
        $this->assertStringContainsString('abuse@bbwcams.test', $loaded);
        $this->assertStringContainsString('Sending a notice of claimed infringement', $loaded);

        // Loading it is not saving it — the site is still on the standard text.
        $this->assertNull($site->fresh()->legalOverride(LegalPage::Dmca, 'body'));
    }

    /**
     * The whole point of "use the standard text" is that it is a starting
     * point you save. A rich text editor round-trips what it is given through
     * its own document model, so this checks the page that comes out the other
     * side is still the document that went in — headings, lists and links
     * intact — rather than a wall of text.
     */
    public function test_the_standard_text_survives_being_loaded_edited_and_saved(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->onDomain('bbwcams.test')->create(['name' => 'BBW Cams']);
        $field = 'legal_pages.'.LegalPage::Dmca->value.'.body';

        $component = Livewire::actingAs($user)
            ->test(EditSite::class, ['record' => $site->getRouteKey()])
            ->callFormComponentAction($field, 'loadDefault_'.LegalPage::Dmca->value)
            ->call('save')
            ->assertHasNoFormErrors();

        $saved = $site->fresh()->legalOverride(LegalPage::Dmca, 'body');

        $this->assertNotNull($saved, 'The loaded text was not stored as an override.');

        $response = $this->get('http://bbwcams.test/dmca')->assertOk();

        $response->assertSee('Sending a notice of claimed infringement');
        $response->assertSee('Counter-notification');
        $response->assertSee('bbwcams.test');
        // The list of statutory elements is a list, not one run-on paragraph.
        $response->assertSee('<li>', escape: false);
        // And the links out of it still work.
        $response->assertSee('chaturbate.com', escape: false);
    }

    /**
     * Opening the site record and pressing save must not quietly take over
     * the legal pages: an untouched rich text editor hands back `<p></p>`,
     * which stored as an override would serve four blank pages.
     */
    public function test_saving_a_site_without_touching_the_legal_tab_leaves_it_on_the_standard_text(): void
    {
        $user = User::factory()->create();
        $site = Site::factory()->create();

        Livewire::actingAs($user)
            ->test(EditSite::class, ['record' => $site->getRouteKey()])
            ->fillForm(['name' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $site->refresh();

        foreach (LegalPage::all() as $page) {
            $this->assertNull($site->legalOverride($page, 'body'), $page->value.' was overridden by a plain save');
        }

        $this->get('http://'.$site->primaryDomain().'/dmca')
            ->assertOk()
            ->assertSee('Sending a notice of claimed infringement');
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
