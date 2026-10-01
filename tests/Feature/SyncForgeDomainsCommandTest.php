<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Services\Forge\ForgeDomainProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncForgeDomainsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://forge.example.test/api/orgs/acme/servers/10/sites/99';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.forge.base_url', 'https://forge.example.test/api/orgs/acme');
        config()->set('services.forge.token', 'forge-token');
        config()->set('services.forge.server_id', '10');
        config()->set('services.forge.site_id', '99');
    }

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
        Site::factory()->create([
            'name' => 'New Site',
            'domains' => ['new-domain.example'],
            'is_active' => true,
        ]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'already-there.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['1' => 'installed'])),
        ]);

        $this->artisan('sites:sync-forge-domains', ['--dry-run' => true])
            ->expectsOutputToContain('would add domain in Forge')
            ->expectsOutputToContain("would request Let's Encrypt certificate")
            ->expectsOutputToContain('Summary: 1 domain adds')
            ->assertExitCode(0);

        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_it_adds_missing_domains_and_requests_certificates(): void
    {
        Site::factory()->create([
            'name' => 'Live Site',
            'domains' => ['live-domain.example'],
            'is_active' => true,
        ]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload([])),
            self::BASE.'/domains' => Http::response(['data' => ['id' => '55', 'type' => 'domainRecords', 'attributes' => ['name' => 'live-domain.example']]], 201),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload([])),
            self::BASE.'/domains/55/certificates' => Http::response(['data' => ['id' => '900', 'type' => 'certificates']], 201),
        ]);

        $this->artisan('sites:sync-forge-domains')
            ->expectsOutputToContain('domain added in Forge')
            ->expectsOutputToContain('certificate request submitted')
            ->assertExitCode(0);

        Http::assertSentCount(4);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::BASE.'/domains'
            && $request['name'] === 'live-domain.example'
            && $request['allow_wildcard_subdomains'] === false
            && $request['www_redirect_type'] === 'from-www');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::BASE.'/domains/55/certificates'
            && $request['type'] === 'letsencrypt'
            && $request['letsencrypt']['verification_method'] === 'http-01');
    }

    public function test_it_can_retry_certificates_without_adding_domains(): void
    {
        Site::factory()->create([
            'slug' => 'retry-site',
            'name' => 'Retry Site',
            'domains' => ['retry-domain.example'],
            'is_active' => true,
        ]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['7' => 'retry-domain.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['7' => 'failed'])),
            self::BASE.'/domains/7/certificates' => Http::response(['data' => ['id' => '901', 'type' => 'certificates']], 201),
        ]);

        $this->artisan('sites:sync-forge-domains', [
            '--site' => 'retry-site',
            '--certificates-only' => true,
        ])
            ->expectsOutputToContain('certificate request submitted')
            ->assertExitCode(0);

        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::BASE.'/domains');
    }

    public function test_certificate_retry_fails_when_the_domain_is_not_in_forge(): void
    {
        Site::factory()->create([
            'slug' => 'orphan-site',
            'domains' => ['orphan.example'],
            'is_active' => true,
        ]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload([])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload([])),
        ]);

        $this->artisan('sites:sync-forge-domains', [
            '--site' => 'orphan-site',
            '--certificates-only' => true,
        ])
            ->expectsOutputToContain('domain is not in Forge')
            ->assertExitCode(1);
    }

    public function test_it_throws_when_forge_404s_while_checking_existing_resources(): void
    {
        Http::fake([
            self::BASE.'/domains?*' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read existing Forge domains');

        app(ForgeDomainProvisioner::class)->existingDomains();
    }

    public function test_it_throws_when_the_response_is_not_in_the_org_api_format(): void
    {
        Http::fake([
            self::BASE.'/domains?*' => Http::response(['domains' => [['domain' => 'legacy.example']]]),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unexpected response format');

        app(ForgeDomainProvisioner::class)->existingDomains();
    }

    public function test_it_reads_domains_and_installed_certificates_from_the_org_api(): void
    {
        $site = Site::factory()->create([
            'name' => 'Existing Site',
            'domains' => ['Existing.example.com'],
            'is_active' => true,
        ]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload([
                '1' => 'existing.example.com',
                '2' => 'no-ssl.example.com',
            ])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload([
                '1' => 'installed',
                '2' => 'failed',
            ])),
        ]);

        $provisioner = app(ForgeDomainProvisioner::class);

        $this->assertSame(['existing.example.com', 'no-ssl.example.com'], $provisioner->existingDomains()->all());
        $this->assertSame(['existing.example.com'], $provisioner->existingCertificates()->all());

        $result = $provisioner->sync(collect([$site]), true, false);

        $this->assertSame(0, $result['added']);
        $this->assertSame(1, $result['skipped_existing_domain']);
        $this->assertSame(0, $result['requested_certificates']);
        $this->assertSame(1, $result['skipped_existing_certificate']);
        $this->assertSame(0, $result['failures']);
    }

    public function test_it_follows_cursor_pagination(): void
    {
        Http::fake([
            self::BASE.'/domains?*' => Http::sequence()
                ->push($this->domainsPayload(['1' => 'page-one.example'], nextCursor: 'abc'))
                ->push($this->domainsPayload(['2' => 'page-two.example'])),
        ]);

        $this->assertSame(
            ['page-one.example', 'page-two.example'],
            app(ForgeDomainProvisioner::class)->existingDomains()->all(),
        );

        Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'page[cursor]=abc'));
    }

    /**
     * @param  array<string, string>  $domains  domain record id => name
     * @return array<string, mixed>
     */
    private function domainsPayload(array $domains, ?string $nextCursor = null): array
    {
        return [
            'data' => collect($domains)->map(fn (string $name, string|int $id): array => [
                'id' => (string) $id,
                'type' => 'domainRecords',
                'attributes' => ['name' => $name, 'type' => 'alias', 'status' => 'enabled'],
            ])->values()->all(),
            'links' => [],
            'meta' => ['per_page' => 100, 'next_cursor' => $nextCursor, 'prev_cursor' => null],
        ];
    }

    /**
     * @param  array<string, string>  $certificates  domain record id => certificate status
     * @return array<string, mixed>
     */
    private function certificatesPayload(array $certificates): array
    {
        return [
            'data' => collect($certificates)->map(fn (string $status, string|int $domainId): array => [
                'id' => "cert-{$domainId}",
                'type' => 'certificates',
                'attributes' => ['type' => 'letsencrypt', 'status' => $status, 'active' => $status === 'installed'],
                'links' => ['self' => ['href' => self::BASE."/domains/{$domainId}/certificates/cert-{$domainId}"]],
            ])->values()->all(),
        ];
    }
}
