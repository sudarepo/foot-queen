<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Services\Forge\ForgeDomainProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncForgeDomainsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fails_when_forge_configuration_is_missing(): void
    {
        config()->set('services.forge.token', null);
        config()->set('services.forge.server_id', null);
        config()->set('services.forge.site_id', null);

        $this->artisan('sites:sync-forge-domains')
            ->expectsOutputToContain('Forge is not configured')
            ->assertExitCode(1);
    }

    public function test_dry_run_reports_pending_domain_and_certificate_changes(): void
    {
        config()->set('services.forge.base_url', 'https://forge.example.test/api/v1');
        config()->set('services.forge.token', 'forge-token');
        config()->set('services.forge.server_id', '10');
        config()->set('services.forge.site_id', '99');

        Site::factory()->create([
            'name' => 'New Site',
            'domains' => ['new-domain.example'],
            'is_active' => true,
        ]);

        Http::fake([
            'https://forge.example.test/api/v1/servers/10/sites/99/domains' => Http::response([
                'domains' => [
                    ['domain' => 'already-there.example'],
                ],
            ], 200),
            'https://forge.example.test/api/v1/servers/10/sites/99/certificates' => Http::response([
                'certificates' => [
                    ['domain' => 'already-there.example'],
                ],
            ], 200),
        ]);

        $this->artisan('sites:sync-forge-domains', ['--dry-run' => true])
            ->expectsOutputToContain('would add domain in Forge')
            ->expectsOutputToContain("would request Let's Encrypt certificate")
            ->expectsOutputToContain('Summary: 1 domain adds')
            ->assertExitCode(0);

        Http::assertSentCount(2);
    }

    public function test_it_adds_missing_domains_and_requests_certificates(): void
    {
        config()->set('services.forge.base_url', 'https://forge.example.test/api/v1');
        config()->set('services.forge.token', 'forge-token');
        config()->set('services.forge.server_id', '10');
        config()->set('services.forge.site_id', '99');

        Site::factory()->create([
            'name' => 'Live Site',
            'domains' => ['live-domain.example'],
            'is_active' => true,
        ]);

        Http::fake([
            'https://forge.example.test/api/v1/servers/10/sites/99/domains' => Http::sequence()
                ->push(['domains' => []], 200)
                ->push(['domain' => ['domain' => 'live-domain.example']], 201),
            'https://forge.example.test/api/v1/servers/10/sites/99/certificates' => Http::response([
                'certificates' => [],
            ], 200),
            'https://forge.example.test/api/v1/servers/10/sites/99/certificates/letsencrypt' => Http::response([
                'certificate' => ['domain' => 'live-domain.example'],
            ], 201),
        ]);

        $this->artisan('sites:sync-forge-domains')
            ->expectsOutputToContain('domain added in Forge')
            ->expectsOutputToContain('certificate request submitted')
            ->assertExitCode(0);

        Http::assertSentCount(4);
    }

    public function test_it_can_retry_certificates_without_adding_domains(): void
    {
        config()->set('services.forge.base_url', 'https://forge.example.test/api/v1');
        config()->set('services.forge.token', 'forge-token');
        config()->set('services.forge.server_id', '10');
        config()->set('services.forge.site_id', '99');

        Site::factory()->create([
            'slug' => 'retry-site',
            'name' => 'Retry Site',
            'domains' => ['retry-domain.example'],
            'is_active' => true,
        ]);

        Http::fake([
            'https://forge.example.test/api/v1/servers/10/sites/99/certificates' => Http::response([
                'certificates' => [],
            ], 200),
            'https://forge.example.test/api/v1/servers/10/sites/99/certificates/letsencrypt' => Http::response([
                'certificate' => ['domain' => 'retry-domain.example'],
            ], 201),
        ]);

        $this->artisan('sites:sync-forge-domains', [
            '--site' => 'retry-site',
            '--certificates-only' => true,
        ])
            ->expectsOutputToContain('certificate request submitted')
            ->assertExitCode(0);

        Http::assertSentCount(2);
    }

    public function test_it_handles_404s_when_reading_existing_forge_resources(): void
    {
        config()->set('services.forge.base_url', 'https://forge.example.test/api/v1');
        config()->set('services.forge.token', 'forge-token');
        config()->set('services.forge.server_id', '10');
        config()->set('services.forge.site_id', '99');

        $site = Site::factory()->create([
            'name' => 'Missing site',
            'domains' => ['new-domain.example'],
            'is_active' => true,
        ]);

        Http::fake([
            'https://forge.example.test/api/v1/servers/10/sites/99/domains' => Http::sequence()
                ->push(['message' => 'Not Found'], 404)
                ->push(['message' => 'Not Found'], 404)
                ->push(['domain' => ['domain' => 'new-domain.example']], 201),
            'https://forge.example.test/api/v1/servers/10/sites/99/certificates' => Http::sequence()
                ->push(['message' => 'Not Found'], 404)
                ->push(['message' => 'Not Found'], 404),
            'https://forge.example.test/api/v1/servers/10/sites/99/certificates/letsencrypt' => Http::response([
                'certificate' => ['domain' => 'new-domain.example'],
            ], 201),
        ]);

        $result = app(ForgeDomainProvisioner::class)->sync(collect([$site]), true, false);

        $this->assertSame(1, $result['added']);
        $this->assertSame(1, $result['requested_certificates']);
        $this->assertSame(0, $result['failures']);
    }
}
