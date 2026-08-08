<?php

namespace Tests\Feature;

use App\Models\Cam;
use App\Models\CamClickEvent;
use App\Models\PageViewEvent;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two kinds of panel user: admins, who run the network, and users assigned
 * individual sites, who see only those.
 */
class UserAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_manage_users(): void
    {
        $admin = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($admin)->get('/admin/users')->assertSuccessful();
        $this->actingAs($admin)->get('/admin/users/create')->assertSuccessful();
        $this->actingAs($admin)->get("/admin/users/{$other->id}/edit")->assertSuccessful();
    }

    /**
     * The user form grants both the admin flag and site assignments, so
     * reaching it at all is the whole escalation.
     */
    public function test_a_site_manager_cannot_reach_the_users_resource(): void
    {
        $site = Site::query()->where('is_default', true)->firstOrFail();
        $manager = User::factory()->siteManager($site)->create();
        $admin = User::factory()->create();

        $this->actingAs($manager)->get('/admin/users')->assertForbidden();
        $this->actingAs($manager)->get('/admin/users/create')->assertForbidden();
        $this->actingAs($manager)->get("/admin/users/{$admin->id}/edit")->assertForbidden();
    }

    public function test_a_site_manager_only_sees_their_own_sites(): void
    {
        $mine = Site::factory()->create(['name' => 'My Own Site']);
        $theirs = Site::factory()->create(['name' => 'Someone Elses Site']);
        $manager = User::factory()->siteManager($mine)->create();

        $this->actingAs($manager)
            ->get('/admin/sites')
            ->assertSuccessful()
            ->assertSee('My Own Site')
            ->assertDontSee('Someone Elses Site');
    }

    public function test_a_site_manager_can_edit_an_assigned_site(): void
    {
        $site = Site::factory()->create();
        $manager = User::factory()->siteManager($site)->create();

        $this->actingAs($manager)->get("/admin/sites/{$site->id}/edit")->assertSuccessful();
    }

    /**
     * Scoped by query, not just by policy, so an unassigned site isn't
     * reachable by guessing its URL.
     */
    public function test_a_site_manager_cannot_edit_an_unassigned_site(): void
    {
        $mine = Site::factory()->create();
        $theirs = Site::factory()->create();
        $manager = User::factory()->siteManager($mine)->create();

        $this->actingAs($manager)->get("/admin/sites/{$theirs->id}/edit")->assertNotFound();
    }

    public function test_only_admins_can_create_sites(): void
    {
        $site = Site::factory()->create();
        $manager = User::factory()->siteManager($site)->create();

        $this->actingAs($manager)->get('/admin/sites/create')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/admin/sites/create')->assertSuccessful();
    }

    /**
     * A user with neither the admin flag nor a single site has nothing to
     * manage — the panel is closed to them rather than empty.
     */
    public function test_a_user_with_no_sites_and_no_admin_flag_cannot_access_the_panel(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_a_site_manager_can_access_the_panel(): void
    {
        $site = Site::factory()->create();
        $manager = User::factory()->siteManager($site)->create();

        $this->actingAs($manager)->get('/admin')->assertSuccessful();
    }

    public function test_admins_administer_every_site_without_being_assigned_one(): void
    {
        $admin = User::factory()->create();
        $site = Site::factory()->create();

        $this->assertTrue($admin->administers($site));
        $this->assertNull($admin->administeredSiteIds());
    }

    public function test_a_site_manager_administers_only_their_own_sites(): void
    {
        $mine = Site::factory()->create();
        $theirs = Site::factory()->create();
        $manager = User::factory()->siteManager($mine)->create();

        $this->assertTrue($manager->administers($mine));
        $this->assertFalse($manager->administers($theirs));
        $this->assertSame([$mine->id], $manager->administeredSiteIds());
    }

    /**
     * The A/B numbers are per-site, and a site manager's are their own — both
     * the site picker's options and the widget's queries are scoped, so a
     * tampered filter can't pool in someone else's traffic either.
     */
    public function test_a_site_manager_only_sees_their_own_sites_conversion_numbers(): void
    {
        $mine = Site::factory()->create(['name' => 'My Own Site']);
        $theirs = Site::factory()->create(['name' => 'Someone Elses Site']);
        $manager = User::factory()->siteManager($mine)->create();
        $cam = Cam::factory()->create();

        PageViewEvent::create(['site_id' => $mine->id, 'page' => 'grid', 'created_at' => now()]);
        CamClickEvent::create(['site_id' => $mine->id, 'cam_id' => $cam->id, 'source_page' => 'grid', 'created_at' => now()]);

        foreach (range(1, 5) as $ignored) {
            PageViewEvent::create(['site_id' => $theirs->id, 'page' => 'grid', 'created_at' => now()]);
        }

        $this->actingAs($manager)
            ->get('/admin/conversion-dashboard')
            ->assertSuccessful()
            ->assertSee('1 clicks / 1 views', false)
            ->assertDontSee('Someone Elses Site');
    }

    /**
     * Deleting your own account would log you out mid-session, and if you were
     * the last admin it would leave no one able to create another.
     */
    public function test_an_admin_cannot_delete_themselves(): void
    {
        $admin = User::factory()->create();
        $other = User::factory()->create();

        $this->assertFalse($admin->can('delete', $admin));
        $this->assertTrue($admin->can('delete', $other));
    }
}
