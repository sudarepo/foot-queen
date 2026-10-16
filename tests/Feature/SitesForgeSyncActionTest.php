<?php

namespace Tests\Feature;

use App\Jobs\AddSiteToForge;
use App\Jobs\CheckSiteForgeStatus;
use App\Jobs\RequestSiteSsl;
use App\Jobs\SyncForgeDomainsJob;
use App\Models\Site;
use App\Models\User;
use App\Services\Forge\ForgeDomainProvisioner;
use App\Services\Forge\ForgeDomainStatus;
use App\Services\Forge\ForgeSslStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SitesForgeSyncActionTest extends TestCase
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

        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    public function test_the_queued_sync_reports_actions_taken_and_failures_to_the_admin(): void
    {
        Site::factory()->create([
            'domains' => ['new-domain.example', 'broken.example', 'already-there.example'],
            'is_active' => true,
        ]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'already-there.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['1' => 'installed'])),
            self::BASE.'/domains' => function (Request $request) {
                return $request['name'] === 'broken.example'
                    ? Http::response(['message' => 'Invalid domain'], 422)
                    : Http::response(['data' => ['id' => '55', 'type' => 'domainRecords']], 201);
            },
            self::BASE.'/domains/55/certificates' => Http::response(['data' => ['id' => '900']], 201),
        ]);

        SyncForgeDomainsJob::dispatchSync(notifyUserId: auth()->id());

        $notification = auth()->user()->notifications()->sole();

        $this->assertSame('Forge sync finished with 1 error', $notification->data['title']);
        $this->assertSame('<p><strong>Added 1 domain</strong><br>new-domain.example</p><br><p><strong>Requested SSL for 1 domain</strong><br>new-domain.example</p><br><p><strong>Failed</strong><br><strong>broken.example</strong>: could not add domain (HTTP 422 - Invalid domain)</p>', $notification->data['body']);
        $this->assertFalse(app(ForgeDomainProvisioner::class)->isSyncing(), 'The sync lock was not released.');
    }

    public function test_the_queued_sync_records_each_sites_status(): void
    {
        $done = Site::factory()->create(['domains' => ['done.example'], 'is_active' => true]);
        $issuing = Site::factory()->create(['domains' => ['issuing.example'], 'is_active' => true]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'done.example', '2' => 'issuing.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['1' => 'installed', '2' => 'installing'])),
        ]);

        SyncForgeDomainsJob::dispatchSync();

        $this->assertSame(ForgeDomainStatus::Provisioned, $done->refresh()->forge_domain_status);
        $this->assertSame(ForgeSslStatus::Installed, $done->forge_ssl_status);
        $this->assertSame(ForgeSslStatus::InProgress, $issuing->refresh()->forge_ssl_status);
        Queue::assertPushed(CheckSiteForgeStatus::class, fn (CheckSiteForgeStatus $job): bool => $job->siteId === $issuing->id);
    }

    public function test_the_queued_sync_says_when_forge_is_up_to_date(): void
    {
        Site::factory()->create([
            'domains' => ['already-there.example'],
            'is_active' => true,
        ]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'already-there.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['1' => 'installed'])),
        ]);

        SyncForgeDomainsJob::dispatchSync(notifyUserId: auth()->id());

        $this->assertSame('Forge is already up to date', auth()->user()->notifications()->sole()->data['title']);
    }

    public function test_a_queued_sync_that_finds_another_running_says_so(): void
    {
        Http::fake();
        $lock = Cache::lock('forge-domain-sync', 60);
        $lock->get();

        SyncForgeDomainsJob::dispatchSync(notifyUserId: auth()->id());

        $lock->release();
        $this->assertSame('Forge sync already running', auth()->user()->notifications()->sole()->data['title']);
        Http::assertNothingSent();
    }

    /**
     * @param  array<string, string>  $domains  domain record id => domain name
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
