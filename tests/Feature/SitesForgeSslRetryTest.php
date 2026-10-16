<?php

namespace Tests\Feature;

use App\Filament\Resources\Sites\Pages\ListSites;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SitesForgeSslRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sites_list_shows_ssl_missing_when_the_certificate_is_not_provisioned(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create(['is_admin' => true]);

        config()->set('services.forge.base_url', 'https://forge.example.test/api/orgs/acme');
        config()->set('services.forge.token', 'forge-token');
        config()->set('services.forge.server_id', '10');
        config()->set('services.forge.site_id', '99');

        Site::factory()->create([
            'name' => 'SSL Missing Site',
            'domains' => ['ssl-missing.example'],
            'is_active' => true,
        ]);

        Http::fake([
            'https://forge.example.test/api/orgs/acme/servers/10/sites/99/domains?*' => Http::response([
                'data' => [['id' => '1', 'type' => 'domainRecords', 'attributes' => ['name' => 'ssl-missing.example']]],
            ], 200),
            'https://forge.example.test/api/orgs/acme/servers/10/sites/99/certificates?*' => Http::response([
                'data' => [],
            ], 200),
        ]);

        $this->actingAs($admin)
            ->get('/admin/sites')
            ->assertSuccessful()
            ->assertSee('SSL', false)
            ->assertSee('Missing', false)
            ->assertSee('Retry SSL', false);
    }

    public function test_the_sites_list_reads_forge_once_and_then_from_cache(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create(['is_admin' => true]);

        config()->set('services.forge.base_url', 'https://forge.example.test/api/orgs/acme');
        config()->set('services.forge.token', 'forge-token');
        config()->set('services.forge.server_id', '10');
        config()->set('services.forge.site_id', '99');

        Site::factory()->count(3)->create(['is_active' => true]);

        Http::fake([
            'https://forge.example.test/api/orgs/acme/servers/10/sites/99/domains?*' => Http::response(['data' => []], 200),
            'https://forge.example.test/api/orgs/acme/servers/10/sites/99/certificates?*' => Http::response(['data' => []], 200),
        ]);

        $this->actingAs($admin)->get('/admin/sites')->assertSuccessful();
        $this->actingAs($admin)->get('/admin/sites')->assertSuccessful();

        Http::assertSentCount(2);
    }

    public function test_refresh_forge_status_rereads_forge(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        config()->set('services.forge.base_url', 'https://forge.example.test/api/orgs/acme');
        config()->set('services.forge.token', 'forge-token');
        config()->set('services.forge.server_id', '10');
        config()->set('services.forge.site_id', '99');

        Cache::put('forge-domain-snapshot', ['domains' => [], 'certificates' => [], 'pending_certificates' => []], 3600);

        Livewire::test(ListSites::class)
            ->callAction('refreshForgeStatus')
            ->assertNotified('Forge status refreshed');

        $this->assertNull(Cache::get('forge-domain-snapshot'));
    }
}
