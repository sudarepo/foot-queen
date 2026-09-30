<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SitesForgeSslRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sites_list_shows_ssl_missing_when_the_certificate_is_not_provisioned(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create(['is_admin' => true]);

        config()->set('services.forge.base_url', 'https://forge.example.test/api/v1');
        config()->set('services.forge.token', 'forge-token');
        config()->set('services.forge.server_id', '10');
        config()->set('services.forge.site_id', '99');

        Site::factory()->create([
            'name' => 'SSL Missing Site',
            'domains' => ['ssl-missing.example'],
            'is_active' => true,
        ]);

        Http::fake([
            'https://forge.example.test/api/v1/servers/10/sites/99/domains' => Http::response([
                'domains' => [['domain' => 'ssl-missing.example']],
            ], 200),
            'https://forge.example.test/api/v1/servers/10/sites/99/certificates' => Http::response([
                'certificates' => [],
            ], 200),
        ]);

        $this->actingAs($admin)
            ->get('/admin/sites')
            ->assertSuccessful()
            ->assertSee('SSL', false)
            ->assertSee('Missing', false)
            ->assertSee('Retry SSL', false);
    }
}
