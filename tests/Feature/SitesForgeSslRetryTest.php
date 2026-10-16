<?php

namespace Tests\Feature;

use App\Filament\Resources\Sites\Pages\ListSites;
use App\Jobs\AddSiteToForge;
use App\Jobs\CheckSiteForgeStatus;
use App\Jobs\RequestSiteSsl;
use App\Models\Site;
use App\Models\User;
use App\Services\Forge\ForgeDomainStatus;
use App\Services\Forge\ForgeSslStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The sites list shows the Forge status recorded on each site; drawing it
 * never calls Forge, however many sites there are.
 */
class SitesForgeSslRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Creating a site queues its Forge provisioning; these tests drive
        // Forge themselves, so those jobs are only recorded, not run.
        Queue::fake([AddSiteToForge::class, RequestSiteSsl::class, CheckSiteForgeStatus::class]);

        config()->set('services.forge.base_url', 'https://forge.example.test/api/orgs/acme');
        config()->set('services.forge.token', 'forge-token');
        config()->set('services.forge.server_id', '10');
        config()->set('services.forge.site_id', '99');
    }

    public function test_the_sites_list_shows_the_stored_forge_status_without_calling_forge(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create(['is_admin' => true]);
        Http::fake();

        $provisioned = Site::factory()->create(['name' => 'Done Site', 'domains' => ['done.example']]);
        $issuing = Site::factory()->create(['name' => 'Issuing Site', 'domains' => ['issuing.example']]);
        $failed = Site::factory()->create(['name' => 'Failed Site', 'domains' => ['failed.example']]);

        Site::query()->whereKey($provisioned->id)->update(['forge_domain_status' => 'provisioned', 'forge_ssl_status' => 'installed']);
        Site::query()->whereKey($issuing->id)->update(['forge_domain_status' => 'provisioned', 'forge_ssl_status' => 'in_progress']);
        Site::query()->whereKey($failed->id)->update(['forge_domain_status' => 'provisioned', 'forge_ssl_status' => 'failed']);

        $this->actingAs($admin)
            ->get('/admin/sites')
            ->assertSuccessful()
            ->assertSee('Installed')
            ->assertSee('In progress')
            ->assertSee('Failed');

        Http::assertNothingSent();
    }

    public function test_the_forge_sync_button_is_gone_from_the_sites_list(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(ListSites::class)
            ->assertActionDoesNotExist('syncForgeDomains')
            ->assertActionDoesNotExist('refreshForgeStatus');
    }

    public function test_a_new_site_shows_as_in_progress_straight_away(): void
    {
        $site = Site::factory()->create(['domains' => ['brand-new.example']]);

        $site->refresh();

        $this->assertSame(ForgeDomainStatus::InProgress, $site->forge_domain_status);
        $this->assertSame(ForgeSslStatus::Queued, $site->forge_ssl_status);
    }
}
