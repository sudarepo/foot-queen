<?php

namespace Tests\Feature;

use App\Jobs\AddSiteToForge;
use App\Jobs\CheckSiteForgeStatus;
use App\Jobs\RequestSiteSsl;
use App\Models\Site;
use App\Services\Forge\CloudflareDetector;
use App\Services\Forge\ForgeDomainProvisioner;
use App\Services\Forge\ForgeDomainStatus;
use App\Services\Forge\ForgeSslStatus;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A site is set up in Forge when it's created or its domains change, and its
 * status is recorded on the row — the admin reads that, never Forge.
 */
class ForgeSiteProvisioningTest extends TestCase
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

    /* ----------  When provisioning is queued  ---------- */

    public function test_creating_a_site_queues_its_provisioning(): void
    {
        Queue::fake([AddSiteToForge::class, RequestSiteSsl::class]);

        $site = Site::factory()->create(['domains' => ['new.example']]);

        Queue::assertPushed(AddSiteToForge::class, fn (AddSiteToForge $job): bool => $job->siteId === $site->id);
        Queue::assertNotPushed(RequestSiteSsl::class);
        $this->assertSame(ForgeDomainStatus::InProgress, $site->refresh()->forge_domain_status);
        $this->assertSame(ForgeSslStatus::Queued, $site->forge_ssl_status);
    }

    public function test_changing_a_sites_domains_queues_provisioning_again(): void
    {
        Queue::fake([AddSiteToForge::class, RequestSiteSsl::class]);
        $site = Site::factory()->create(['domains' => ['old.example']]);

        // The first job has run and released its uniqueness lock.
        Cache::lock(UniqueLock::getKey(new AddSiteToForge($site->id)))->forceRelease();

        $site->update(['domains' => ['new.example']]);

        Queue::assertPushed(AddSiteToForge::class, 2);
    }

    /**
     * The job reads the site's domains when it runs, so a second change
     * while the first is still queued is already covered by it.
     */
    public function test_a_change_while_provisioning_is_still_queued_does_not_queue_a_duplicate(): void
    {
        Queue::fake([AddSiteToForge::class, RequestSiteSsl::class]);
        $site = Site::factory()->create(['domains' => ['old.example']]);

        $site->update(['domains' => ['new.example']]);

        Queue::assertPushed(AddSiteToForge::class, 1);
    }

    public function test_an_edit_that_leaves_the_domains_alone_does_not(): void
    {
        Queue::fake([AddSiteToForge::class, RequestSiteSsl::class]);
        $site = Site::factory()->create(['domains' => ['same.example']]);

        $site->update(['name' => 'Renamed']);

        Queue::assertPushed(AddSiteToForge::class, 1);
    }

    public function test_nothing_is_queued_when_forge_is_not_configured(): void
    {
        config()->set('services.forge.token', null);
        Queue::fake([AddSiteToForge::class, RequestSiteSsl::class]);

        $site = Site::factory()->create(['domains' => ['new.example']]);

        Queue::assertNothingPushed();
        $this->assertSame(ForgeDomainStatus::Unknown, $site->refresh()->forge_domain_status);
    }

    /* ----------  The provisioning job  ---------- */

    public function test_adding_the_domain_queues_the_ssl_step_once_forge_has_it(): void
    {
        Queue::fake([AddSiteToForge::class, RequestSiteSsl::class, CheckSiteForgeStatus::class]);
        $site = Site::factory()->create(['domains' => ['new.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::sequence()
                ->push($this->domainsPayload([]))
                ->push($this->domainsPayload(['55' => 'new.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload([])),
            self::BASE.'/domains' => Http::response(['data' => ['id' => '55', 'type' => 'domainRecords']], 201),
        ]);

        (new AddSiteToForge($site->id))->handle(app(ForgeDomainProvisioner::class));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === self::BASE.'/domains');
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/certificates'));

        $site->refresh();
        $this->assertSame(ForgeDomainStatus::Provisioned, $site->forge_domain_status);
        $this->assertSame(ForgeSslStatus::Queued, $site->forge_ssl_status);
        Queue::assertPushed(RequestSiteSsl::class, fn (RequestSiteSsl $job): bool => $job->siteId === $site->id);
    }

    public function test_a_domain_forge_refuses_is_recorded_as_failed_and_ssl_is_never_requested(): void
    {
        Queue::fake([AddSiteToForge::class, RequestSiteSsl::class, CheckSiteForgeStatus::class]);
        $site = Site::factory()->create(['domains' => ['bad.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload([])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload([])),
            self::BASE.'/domains' => Http::response(['message' => 'Invalid domain'], 422),
        ]);

        (new AddSiteToForge($site->id))->handle(app(ForgeDomainProvisioner::class));

        $site->refresh();
        $this->assertSame(ForgeDomainStatus::Failed, $site->forge_domain_status);
        $this->assertStringContainsString('Invalid domain', (string) $site->forge_error);
        Queue::assertNotPushed(RequestSiteSsl::class);
    }

    public function test_a_site_already_in_forge_with_ssl_needs_no_ssl_step(): void
    {
        Queue::fake([AddSiteToForge::class, RequestSiteSsl::class, CheckSiteForgeStatus::class]);
        $site = Site::factory()->create(['domains' => ['done.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'done.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['1' => 'installed'])),
        ]);

        (new AddSiteToForge($site->id))->handle(app(ForgeDomainProvisioner::class));

        $this->assertSame(ForgeSslStatus::Installed, $site->refresh()->forge_ssl_status);
        Queue::assertNotPushed(RequestSiteSsl::class);
    }

    public function test_the_ssl_step_requests_the_certificate_and_keeps_checking_while_it_issues(): void
    {
        Queue::fake([AddSiteToForge::class, RequestSiteSsl::class, CheckSiteForgeStatus::class]);
        $site = Site::factory()->create(['domains' => ['new.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['55' => 'new.example'])),
            self::BASE.'/certificates?*' => Http::sequence()
                ->push($this->certificatesPayload([]))
                ->push($this->certificatesPayload(['55' => 'installing'])),
            self::BASE.'/domains/55/certificates' => Http::response(['data' => ['id' => '900']], 201),
        ]);

        (new RequestSiteSsl($site->id))->handle(app(ForgeDomainProvisioner::class));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === self::BASE.'/domains/55/certificates');
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === self::BASE.'/domains');

        $this->assertSame(ForgeSslStatus::InProgress, $site->refresh()->forge_ssl_status);
        Queue::assertPushed(CheckSiteForgeStatus::class, fn (CheckSiteForgeStatus $job): bool => $job->siteId === $site->id);
    }

    public function test_the_ssl_step_fails_cleanly_when_the_domain_is_not_in_forge(): void
    {
        Queue::fake([AddSiteToForge::class, RequestSiteSsl::class, CheckSiteForgeStatus::class]);
        $site = Site::factory()->create(['domains' => ['orphan.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload([])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload([])),
        ]);

        (new RequestSiteSsl($site->id))->handle(app(ForgeDomainProvisioner::class));

        $site->refresh();
        $this->assertSame(ForgeSslStatus::Missing, $site->forge_ssl_status);
        $this->assertStringContainsString('domain is not in Forge', (string) $site->forge_error);
        Queue::assertNotPushed(CheckSiteForgeStatus::class);
    }

    public function test_the_job_waits_for_a_bulk_sync_holding_the_lock(): void
    {
        Queue::fake([AddSiteToForge::class, RequestSiteSsl::class]);
        $site = Site::factory()->create(['domains' => ['waiting.example']]);
        Http::fake();

        $lock = Cache::lock('forge-domain-sync', 60);
        $lock->get();

        $job = (new AddSiteToForge($site->id))->withFakeQueueInteractions();
        $job->handle(app(ForgeDomainProvisioner::class));
        $lock->release();

        $job->assertReleased(delay: 120);
        Http::assertNothingSent();
    }

    /* ----------  Follow-up checks  ---------- */

    public function test_the_follow_up_check_stops_once_the_certificate_is_installed(): void
    {
        Queue::fake([AddSiteToForge::class, RequestSiteSsl::class, CheckSiteForgeStatus::class]);
        $site = Site::factory()->create(['domains' => ['done.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'done.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['1' => 'installed'])),
        ]);

        (new CheckSiteForgeStatus($site->id))->handle(app(ForgeDomainProvisioner::class));

        $this->assertSame(ForgeSslStatus::Installed, $site->refresh()->forge_ssl_status);
        Queue::assertNotPushed(CheckSiteForgeStatus::class);
    }

    public function test_the_follow_up_check_gives_up_after_its_last_attempt(): void
    {
        Queue::fake([AddSiteToForge::class, RequestSiteSsl::class, CheckSiteForgeStatus::class]);
        $site = Site::factory()->create(['domains' => ['slow.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'slow.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['1' => 'installing'])),
        ]);

        (new CheckSiteForgeStatus($site->id, check: 3))->handle(app(ForgeDomainProvisioner::class));
        Queue::assertPushed(CheckSiteForgeStatus::class, fn (CheckSiteForgeStatus $job): bool => $job->check === 4);

        (new CheckSiteForgeStatus($site->id, check: CheckSiteForgeStatus::MAX_CHECKS))->handle(app(ForgeDomainProvisioner::class));
        Queue::assertPushed(CheckSiteForgeStatus::class, 1);
    }

    /* ----------  How a status is decided  ---------- */

    public function test_a_site_is_only_provisioned_when_every_domain_is_in_forge(): void
    {
        Queue::fake([AddSiteToForge::class, RequestSiteSsl::class]);
        $site = Site::factory()->create(['domains' => ['here.example', 'not-yet.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'here.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['1' => 'installed'])),
        ]);

        app(ForgeDomainProvisioner::class)->recordStatuses(collect([$site]), fresh: true);

        $site->refresh();
        $this->assertSame(ForgeDomainStatus::Missing, $site->forge_domain_status);
        $this->assertSame(ForgeSslStatus::Missing, $site->forge_ssl_status);
    }

    public function test_a_refused_certificate_is_recorded_as_failed(): void
    {
        Queue::fake([AddSiteToForge::class, RequestSiteSsl::class]);
        $site = Site::factory()->create(['domains' => ['refused.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'refused.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['1' => 'failed'])),
        ]);

        app(ForgeDomainProvisioner::class)->recordStatuses(collect([$site]), fresh: true);

        $site->refresh();
        $this->assertSame(ForgeDomainStatus::Provisioned, $site->forge_domain_status);
        $this->assertSame(ForgeSslStatus::Failed, $site->forge_ssl_status);
    }

    /* ----------  Cloudflare  ---------- */

    public function test_a_domain_is_proxied_only_when_every_address_is_cloudflares(): void
    {
        $detector = new CloudflareDetector(fn (string $domain): array => match ($domain) {
            'proxied.example' => ['104.18.207.112', '104.18.208.112'],
            'direct.example' => ['208.122.217.32'],
            'mixed.example' => ['104.18.207.112', '208.122.217.32'],
            default => [],
        });

        $this->assertTrue($detector->isProxied('proxied.example'));
        $this->assertFalse($detector->isProxied('direct.example'));
        $this->assertFalse($detector->isProxied('mixed.example'));
        $this->assertFalse($detector->isProxied('unresolvable.example'));
    }

    public function test_a_bulk_sync_requests_no_certificate_for_a_domain_behind_cloudflare(): void
    {
        Queue::fake([AddSiteToForge::class, RequestSiteSsl::class, CheckSiteForgeStatus::class]);
        $this->behindCloudflare(['proxied.example']);
        $site = Site::factory()->create(['domains' => ['proxied.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'proxied.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload([])),
        ]);

        $result = app(ForgeDomainProvisioner::class)->sync(collect([$site]), requestCertificates: true, dryRun: false);

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertSame(1, $result['skipped_cloudflare']);
    }

    public function test_the_ssl_step_requests_a_certificate_for_a_domain_behind_cloudflare(): void
    {
        Queue::fake([AddSiteToForge::class, RequestSiteSsl::class, CheckSiteForgeStatus::class]);
        $this->behindCloudflare(['proxied.example']);
        $site = Site::factory()->create(['domains' => ['proxied.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'proxied.example'])),
            self::BASE.'/certificates?*' => Http::sequence()
                ->push($this->certificatesPayload(['1' => 'failed']))
                ->push($this->certificatesPayload(['1' => 'installing'])),
            self::BASE.'/domains/1/certificates' => Http::response(['data' => ['id' => '900']], 201),
        ]);

        (new RequestSiteSsl($site->id))->handle(app(ForgeDomainProvisioner::class));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === self::BASE.'/domains/1/certificates');
        $this->assertSame(ForgeSslStatus::InProgress, $site->refresh()->forge_ssl_status);
        Queue::assertPushed(CheckSiteForgeStatus::class);
    }

    public function test_a_new_site_behind_cloudflare_still_queues_the_ssl_step(): void
    {
        Queue::fake([AddSiteToForge::class, RequestSiteSsl::class, CheckSiteForgeStatus::class]);
        $this->behindCloudflare(['proxied.example']);
        $site = Site::factory()->create(['domains' => ['proxied.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::sequence()
                ->push($this->domainsPayload([]))
                ->push($this->domainsPayload(['55' => 'proxied.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload([])),
            self::BASE.'/domains' => Http::response(['data' => ['id' => '55', 'type' => 'domainRecords']], 201),
        ]);

        (new AddSiteToForge($site->id))->handle(app(ForgeDomainProvisioner::class));

        $this->assertFalse(ForgeSslStatus::Cloudflare->isSecured());
        Queue::assertPushed(RequestSiteSsl::class, fn (RequestSiteSsl $job): bool => $job->siteId === $site->id);
    }

    public function test_a_domain_behind_cloudflare_with_no_certificate_reads_as_cloudflare(): void
    {
        Queue::fake([AddSiteToForge::class]);
        $this->behindCloudflare(['proxied.example']);
        $site = Site::factory()->create(['domains' => ['proxied.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'proxied.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload([])),
        ]);

        app(ForgeDomainProvisioner::class)->recordStatuses(collect([$site]), fresh: true);

        $this->assertSame(ForgeSslStatus::Cloudflare, $site->refresh()->forge_ssl_status);
    }

    public function test_a_failed_certificate_behind_cloudflare_reads_as_failed(): void
    {
        Queue::fake([AddSiteToForge::class]);
        $this->behindCloudflare(['proxied.example']);
        $site = Site::factory()->create(['domains' => ['proxied.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'proxied.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['1' => 'failed'])),
        ]);

        app(ForgeDomainProvisioner::class)->recordStatuses(collect([$site]), fresh: true);

        $this->assertSame(ForgeSslStatus::Failed, $site->refresh()->forge_ssl_status);
    }

    public function test_an_installed_certificate_still_reads_as_installed_behind_cloudflare(): void
    {
        Queue::fake([AddSiteToForge::class]);
        $this->behindCloudflare(['proxied.example']);
        $site = Site::factory()->create(['domains' => ['proxied.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'proxied.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['1' => 'installed'])),
        ]);

        app(ForgeDomainProvisioner::class)->recordStatuses(collect([$site]), fresh: true);

        $this->assertSame(ForgeSslStatus::Installed, $site->refresh()->forge_ssl_status);
    }

    /**
     * @param  array<int, string>  $domains
     */
    private function behindCloudflare(array $domains): void
    {
        $this->app->instance(CloudflareDetector::class, new CloudflareDetector(
            fn (string $domain): array => in_array($domain, $domains, true) ? ['104.18.207.112'] : ['208.122.217.32'],
        ));
    }

    /* ----------  Weekly check  ---------- */

    public function test_the_weekly_check_records_every_sites_status(): void
    {
        Queue::fake([AddSiteToForge::class, RequestSiteSsl::class]);
        $done = Site::factory()->create(['domains' => ['done.example']]);
        $gone = Site::factory()->create(['domains' => ['removed-in-forge.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'done.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['1' => 'installed'])),
        ]);

        $this->artisan('sites:check-forge-status')
            ->expectsOutputToContain('Domains:')
            ->assertSuccessful();

        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
        $this->assertSame(ForgeSslStatus::Installed, $done->refresh()->forge_ssl_status);
        $this->assertSame(ForgeDomainStatus::Missing, $gone->refresh()->forge_domain_status);
    }

    public function test_the_weekly_check_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('sites:check-forge-status');
    }

    /**
     * @param  array<string, string>  $domains  domain record id => name
     * @return array<string, mixed>
     */
    private function domainsPayload(array $domains): array
    {
        return [
            'data' => collect($domains)->map(fn (string $name, string|int $id): array => [
                'id' => (string) $id,
                'type' => 'domainRecords',
                'attributes' => ['name' => $name],
            ])->values()->all(),
            'meta' => ['next_cursor' => null],
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
                'attributes' => ['status' => $status, 'active' => $status === 'installed'],
                'links' => ['self' => ['href' => self::BASE."/domains/{$domainId}/certificates/cert-{$domainId}"]],
            ])->values()->all(),
        ];
    }
}
