<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The in-panel manual (App\Filament\Pages\Guide) — reference material rather
 * than behaviour, so what's worth testing is that it stays true: that it still
 * covers every tab of the site form, that its screenshots still exist on disk,
 * and that it doesn't describe screens the reader isn't allowed to open.
 */
class AdminGuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authenticated_user_can_view_the_guide(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/guide')
            ->assertSuccessful()
            ->assertSee('How to use this admin');
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin/guide')->assertRedirect('/admin/login');
    }

    /**
     * Six tabs on the site form, six sections here. A tab added to SiteForm
     * without a section here is an undocumented field group.
     */
    public function test_the_guide_covers_every_tab_of_the_site_form(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin/guide')->assertSuccessful();

        foreach (['Identity', 'Branding', 'Homepage layout', 'Content', 'Copy &amp; SEO', 'Legal', 'Tracking'] as $tab) {
            $response->assertSee($tab, false);
        }
    }

    /**
     * The screenshots are files in public/, not something the framework would
     * fail on if they went missing — a renamed or unshipped image would just
     * render as a broken box for every user of the panel.
     */
    public function test_every_screenshot_the_guide_references_exists(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/admin/guide')->assertSuccessful()->getContent();

        preg_match_all('#img/docs/([\w-]+\.png)#', (string) $html, $matches);

        $files = array_unique($matches[1]);

        $this->assertNotEmpty($files, 'The guide rendered no screenshots at all.');

        foreach ($files as $file) {
            $this->assertFileExists(public_path("img/docs/{$file}"));
        }
    }

    /**
     * A site manager can't create sites or reach the users resource, so
     * walking them through either is at best confusing and at worst reads as
     * access they've been denied.
     */
    public function test_a_site_manager_is_not_shown_the_administrator_only_sections(): void
    {
        $site = Site::query()->where('is_default', true)->firstOrFail();
        $manager = User::factory()->siteManager($site)->create();

        $this->actingAs($manager)
            ->get('/admin/guide')
            ->assertSuccessful()
            ->assertSee('Configuring a site')
            ->assertDontSee('Launching a new site')
            ->assertDontSee('Users and access');
    }

    public function test_an_admin_is_shown_the_administrator_only_sections(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->get('/admin/guide')
            ->assertSuccessful()
            ->assertSee('Launching a new site')
            ->assertSee('Users and access');
    }
}
