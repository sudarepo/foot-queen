<?php

namespace Tests\Feature;

use App\Jobs\AddSiteToForge;
use App\Jobs\CheckSiteForgeStatus;
use App\Jobs\RequestSiteSsl;
use App\Models\Site;
use App\Services\Forge\ForgeDomainProvisioner;
use App\Services\Forge\ForgeSyncInProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class SyncForgeDomainsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://forge.example.test/api/orgs/acme/servers/10/sites/99';

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

    public function test_it_fails_when_forge_configuration_is_missing(): void
    {
        config()->set('services.forge.token', null);
        config()->set('services.forge.server_id', null);
        config()->set('services.forge.site_id', null);

        $this->artisan('sites:sync-forge-domains')
            ->expectsOutputToContain('Forge is not configured')
            ->assertExitCode(1);
    }

    public function test_dry_run_reports_no_changes_when_everything_is_in_forge(): void
    {
        Site::factory()->create([
            'name' => 'Existing Site',
            'domains' => ['already-there.example'],
            'is_active' => true,
        ]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'already-there.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['1' => 'installed'])),
        ]);

        $this->artisan('sites:sync-forge-domains', ['--dry-run' => true])
            ->doesntExpectOutputToContain('already present')
            ->expectsOutputToContain('Dry run: no changes would be made.')
            ->assertExitCode(0);
    }

    public function test_dry_run_reports_pending_domain_and_certificate_changes(): void
    {
        Site::factory()->create([
            'name' => 'New Site',
            'domains' => ['new-domain.example', 'already-there.example'],
            'is_active' => true,
        ]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'already-there.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['1' => 'installed'])),
        ]);

        $this->artisan('sites:sync-forge-domains', ['--dry-run' => true])
            ->expectsOutputToContain('new-domain.example: would add domain in Forge')
            ->expectsOutputToContain("new-domain.example: would request Let's Encrypt certificate")
            ->doesntExpectOutputToContain('already present')
            ->expectsOutputToContain('Dry run: 1 domain adds, 1 certificate requests would be made.')
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

        // Two reads, two writes, then a fresh read to record the new status.
        Http::assertSentCount(6);

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

    public function test_it_only_reports_actions_taken_and_not_resources_already_in_forge(): void
    {
        Site::factory()->create([
            'name' => 'Mixed Site',
            'domains' => ['new-domain.example', 'already-there.example'],
            'is_active' => true,
        ]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'already-there.example'])),
            self::BASE.'/domains' => Http::response(['data' => ['id' => '55', 'type' => 'domainRecords', 'attributes' => ['name' => 'new-domain.example']]], 201),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['1' => 'installed'])),
            self::BASE.'/domains/55/certificates' => Http::response(['data' => ['id' => '900', 'type' => 'certificates']], 201),
        ]);

        $this->artisan('sites:sync-forge-domains')
            ->expectsOutputToContain('new-domain.example: domain added in Forge')
            ->expectsOutputToContain('new-domain.example: certificate request submitted')
            ->doesntExpectOutputToContain('already-there.example')
            ->doesntExpectOutputToContain('already present')
            ->expectsOutputToContain('Done: 1 domain adds, 1 certificate requests, 0 failures.')
            ->assertExitCode(0);
    }

    public function test_it_reports_when_forge_is_already_up_to_date(): void
    {
        Site::factory()->create([
            'name' => 'Existing Site',
            'domains' => ['already-there.example'],
            'is_active' => true,
        ]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'already-there.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['1' => 'installed'])),
        ]);

        $this->artisan('sites:sync-forge-domains')
            ->expectsOutputToContain('Forge is already up to date.')
            ->doesntExpectOutputToContain('already-there.example')
            ->assertExitCode(0);

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
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

        // Two reads, one write, then a fresh read to record the new status.
        Http::assertSentCount(5);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::BASE.'/domains');
    }

    public function test_a_site_can_be_picked_by_its_id(): void
    {
        $site = Site::factory()->create([
            'slug' => 'by-id-site',
            'domains' => ['by-id.example'],
            'is_active' => true,
        ]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['7' => 'by-id.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload([])),
            self::BASE.'/domains/7/certificates' => Http::response(['data' => ['id' => '901', 'type' => 'certificates']], 201),
        ]);

        $this->artisan('sites:sync-forge-domains', [
            '--site' => (string) $site->id,
            '--certificates-only' => true,
        ])
            ->expectsOutputToContain('by-id.example: certificate request submitted')
            ->assertExitCode(0);
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

    /**
     * Every certificate install reloads nginx and PHP-FPM, so a second sync
     * started mid-run must not touch Forge at all.
     */
    public function test_a_live_sync_refuses_to_start_while_another_is_running(): void
    {
        $site = Site::factory()->create(['domains' => ['busy.example']]);
        Http::fake();

        $lock = Cache::lock('forge-domain-sync', 60);
        $lock->get();

        try {
            app(ForgeDomainProvisioner::class)->sync(collect([$site]), requestCertificates: true, dryRun: false);
            $this->fail('A second sync was allowed to run.');
        } catch (ForgeSyncInProgress) {
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_the_command_reports_a_sync_already_running(): void
    {
        Site::factory()->create(['domains' => ['busy.example']]);
        Http::fake();

        $lock = Cache::lock('forge-domain-sync', 60);
        $lock->get();

        $this->artisan('sites:sync-forge-domains')
            ->expectsOutputToContain('Another Forge sync is already running')
            ->assertExitCode(1);

        $lock->release();
        Http::assertNothingSent();
    }

    public function test_a_dry_run_is_allowed_while_a_sync_is_running(): void
    {
        $site = Site::factory()->create(['domains' => ['busy.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload([])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload([])),
        ]);

        $lock = Cache::lock('forge-domain-sync', 60);
        $lock->get();

        $result = app(ForgeDomainProvisioner::class)->sync(collect([$site]), requestCertificates: true, dryRun: true);
        $lock->release();

        $this->assertSame(1, $result['added']);
    }

    public function test_the_lock_is_released_after_a_sync_so_the_next_one_can_run(): void
    {
        $site = Site::factory()->create(['domains' => ['done.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'done.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['1' => 'installed'])),
        ]);

        $provisioner = app(ForgeDomainProvisioner::class);
        $provisioner->sync(collect([$site]), requestCertificates: true, dryRun: false);

        $this->assertFalse($provisioner->isSyncing());
        $provisioner->sync(collect([$site]), requestCertificates: true, dryRun: false);
    }

    /**
     * Forge issues certificates asynchronously; one still installing from the
     * last sync is not missing, and requesting it again is another reload.
     */
    public function test_a_certificate_still_being_issued_is_not_requested_again(): void
    {
        $site = Site::factory()->create(['domains' => ['issuing.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'issuing.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['1' => 'installing'])),
        ]);

        $result = app(ForgeDomainProvisioner::class)->sync(collect([$site]), requestCertificates: true, dryRun: false);

        $this->assertSame(0, $result['requested_certificates']);
        $this->assertSame(1, $result['skipped_existing_certificate']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_a_bulk_sync_leaves_a_fresh_certificate_failure_alone(): void
    {
        $site = Site::factory()->create(['domains' => ['fresh-fail.example', 'old-fail.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'fresh-fail.example', '2' => 'old-fail.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(
                ['1' => 'failed', '2' => 'failed'],
                updatedAt: ['1' => now()->subMinutes(5)->toIso8601String(), '2' => now()->subHours(3)->toIso8601String()],
            )),
            self::BASE.'/domains/2/certificates' => Http::response(['data' => ['id' => '902']], 201),
        ]);

        $result = app(ForgeDomainProvisioner::class)->sync(collect([$site]), requestCertificates: true, dryRun: false);

        $this->assertSame(1, $result['skipped_recent_failure']);
        $this->assertSame(1, $result['requested_certificates']);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::BASE.'/domains/1/certificates');
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'/domains/2/certificates');
    }

    public function test_an_explicit_ssl_retry_ignores_the_failure_cooldown(): void
    {
        $site = Site::factory()->create(['domains' => ['fresh-fail.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'fresh-fail.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(
                ['1' => 'failed'],
                updatedAt: ['1' => now()->subMinutes(5)->toIso8601String()],
            )),
            self::BASE.'/domains/1/certificates' => Http::response(['data' => ['id' => '903']], 201),
        ]);

        $result = app(ForgeDomainProvisioner::class)->requestCertificates(collect([$site]), dryRun: false);

        $this->assertSame(1, $result['requested_certificates']);
    }

    /**
     * Forge throttles at about 60 requests a minute; a sync of every site
     * hits that, and must wait it out rather than fail every later domain.
     */
    public function test_a_rate_limited_request_waits_and_retries(): void
    {
        Sleep::fake();
        $site = Site::factory()->create(['domains' => ['throttled.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload([])),
            self::BASE.'/domains' => Http::sequence()
                ->push(['message' => 'Too many attempts. Please contact Forge support.'], 429, ['Retry-After' => '7'])
                ->push(['data' => ['id' => '55', 'type' => 'domainRecords']], 201),
        ]);

        $result = app(ForgeDomainProvisioner::class)->sync(collect([$site]), requestCertificates: false, dryRun: false);

        $this->assertSame(1, $result['added']);
        $this->assertSame(0, $result['failures']);
        Sleep::assertSleptTimes(1);
        Sleep::assertSequence([Sleep::for(8000)->milliseconds()]);
    }

    public function test_a_request_forge_rejects_is_not_retried(): void
    {
        Sleep::fake();
        $site = Site::factory()->create(['domains' => ['invalid.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload([])),
            self::BASE.'/domains' => Http::response(['message' => 'Invalid domain'], 422),
        ]);

        $result = app(ForgeDomainProvisioner::class)->sync(collect([$site]), requestCertificates: false, dryRun: false);

        $this->assertSame(1, $result['failures']);
        Http::assertSentCount(2);
        Sleep::assertNeverSlept();
    }

    /**
     * Provisioned domains and installed certificates don't change between
     * admin page loads; reading them live every time was several paginated
     * API calls per load.
     */
    public function test_the_forge_snapshot_is_cached(): void
    {
        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'cached.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['1' => 'installed'])),
        ]);

        $provisioner = app(ForgeDomainProvisioner::class);
        $first = $provisioner->snapshot();
        $second = $provisioner->snapshot();

        $this->assertSame(['cached.example'], $first['domains']);
        $this->assertSame(['cached.example'], $first['certificates']);
        $this->assertSame($first, $second);
        Http::assertSentCount(2);

        $this->travel(59)->minutes();
        $provisioner->snapshot();
        Http::assertSentCount(2);

        $this->travel(2)->minutes();
        $provisioner->snapshot();
        Http::assertSentCount(4);
    }

    public function test_the_snapshot_is_rechecked_soon_while_a_certificate_is_being_issued(): void
    {
        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'issuing.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['1' => 'installing'])),
        ]);

        $provisioner = app(ForgeDomainProvisioner::class);

        $this->assertSame(['issuing.example'], $provisioner->snapshot()['pending_certificates']);

        $this->travel(2)->minutes();
        $provisioner->snapshot();

        Http::assertSentCount(4);
    }

    public function test_a_live_sync_clears_the_snapshot(): void
    {
        $site = Site::factory()->create(['domains' => ['new.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload([])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload([])),
            self::BASE.'/domains' => Http::response(['data' => ['id' => '55', 'type' => 'domainRecords']], 201),
        ]);

        $provisioner = app(ForgeDomainProvisioner::class);
        $provisioner->snapshot();
        $provisioner->sync(collect([$site]), requestCertificates: false, dryRun: false);

        $this->assertNull(Cache::get('forge-domain-snapshot'));
    }

    public function test_a_failed_read_is_not_cached(): void
    {
        Http::fake([
            self::BASE.'/domains?*' => Http::sequence()
                ->push(['message' => 'Server Error'], 500)
                ->push(['message' => 'Server Error'], 500)
                ->push(['message' => 'Server Error'], 500)
                ->push(['message' => 'Server Error'], 500)
                ->push($this->domainsPayload(['1' => 'back.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload([])),
        ]);

        $provisioner = app(ForgeDomainProvisioner::class);

        try {
            $provisioner->snapshot();
            $this->fail('Expected Forge to be unreadable.');
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertSame(['back.example'], $provisioner->snapshot()['domains']);
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
     * @param  array<string, string>  $updatedAt  domain record id => ISO-8601 time of the last change
     * @return array<string, mixed>
     */
    private function certificatesPayload(array $certificates, array $updatedAt = []): array
    {
        return [
            'data' => collect($certificates)->map(fn (string $status, string|int $domainId): array => [
                'id' => "cert-{$domainId}",
                'type' => 'certificates',
                'attributes' => ['type' => 'letsencrypt', 'status' => $status, 'active' => $status === 'installed', 'updated_at' => $updatedAt[$domainId] ?? null],
                'links' => ['self' => ['href' => self::BASE."/domains/{$domainId}/certificates/cert-{$domainId}"]],
            ])->values()->all(),
        ];
    }
}
