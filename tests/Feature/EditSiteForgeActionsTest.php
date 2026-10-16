<?php

namespace Tests\Feature;

use App\Filament\Resources\Sites\Pages\EditSite;
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

class EditSiteForgeActionsTest extends TestCase
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

    public function test_add_to_forge_queues_provisioning_for_a_site_missing_from_forge(): void
    {
        $site = $this->siteWithStatus(ForgeDomainStatus::Missing, ForgeSslStatus::Missing);
        Http::fake();

        Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
            ->assertActionVisible('provisionInForge')
            ->assertActionHasLabel('provisionInForge', 'Add to Forge')
            ->callAction('provisionInForge')
            ->assertNotified('Forge setup started');

        Queue::assertPushed(AddSiteToForge::class, fn (AddSiteToForge $job): bool => $job->siteId === $site->id);
        Queue::assertNotPushed(RequestSiteSsl::class);
        $this->assertSame(ForgeDomainStatus::InProgress, $site->refresh()->forge_domain_status);
        $this->assertSame(ForgeSslStatus::Queued, $site->forge_ssl_status);
        Http::assertNothingSent();
    }

    public function test_retry_ssl_is_offered_when_the_domain_is_in_forge_but_ssl_failed(): void
    {
        $site = $this->siteWithStatus(ForgeDomainStatus::Provisioned, ForgeSslStatus::Failed);

        Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
            ->assertActionVisible('provisionInForge')
            ->assertActionHasLabel('provisionInForge', 'Retry SSL')
            ->callAction('provisionInForge');

        Queue::assertPushed(RequestSiteSsl::class, fn (RequestSiteSsl $job): bool => $job->siteId === $site->id);
        // Only the push from creating the site; Retry SSL doesn't re-add the domain.
        Queue::assertPushed(AddSiteToForge::class, 1);
        $this->assertSame(ForgeSslStatus::Queued, $site->refresh()->forge_ssl_status);
    }

    public function test_nothing_to_redo_while_ssl_is_queued(): void
    {
        $site = $this->siteWithStatus(ForgeDomainStatus::Provisioned, ForgeSslStatus::Queued);

        Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
            ->assertActionHidden('provisionInForge');
    }

    public function test_nothing_to_redo_when_the_site_is_fully_provisioned(): void
    {
        $site = $this->siteWithStatus(ForgeDomainStatus::Provisioned, ForgeSslStatus::Installed);

        Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
            ->assertActionHidden('provisionInForge')
            ->assertActionVisible('checkForgeStatus');
    }

    public function test_nothing_to_redo_while_provisioning_is_in_progress(): void
    {
        $site = $this->siteWithStatus(ForgeDomainStatus::Provisioned, ForgeSslStatus::InProgress);

        Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
            ->assertActionHidden('provisionInForge');
    }

    public function test_opening_the_edit_page_does_not_call_forge(): void
    {
        $site = $this->siteWithStatus(ForgeDomainStatus::Missing, ForgeSslStatus::Missing);
        Http::fake();

        Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_recheck_reads_forge_and_records_the_status(): void
    {
        $site = $this->siteWithStatus(ForgeDomainStatus::Missing, ForgeSslStatus::Missing, ['done.example']);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'done.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['1' => 'installed'])),
        ]);

        Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
            ->callAction('checkForgeStatus')
            ->assertNotified('Forge: Provisioned · SSL: Installed');

        $site->refresh();
        $this->assertSame(ForgeDomainStatus::Provisioned, $site->forge_domain_status);
        $this->assertSame(ForgeSslStatus::Installed, $site->forge_ssl_status);
        $this->assertNotNull($site->forge_checked_at);
    }

    public function test_no_forge_actions_are_shown_when_forge_is_not_configured(): void
    {
        config()->set('services.forge.token', null);

        $site = $this->siteWithStatus(ForgeDomainStatus::Missing, ForgeSslStatus::Missing);
        Http::fake();

        Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
            ->assertActionHidden('provisionInForge')
            ->assertActionHidden('checkForgeStatus');

        Http::assertNothingSent();
    }

    /**
     * @param  array<int, string>  $domains
     */
    private function siteWithStatus(ForgeDomainStatus $domain, ForgeSslStatus $ssl, array $domains = ['site.example']): Site
    {
        $site = Site::factory()->create(['domains' => $domains]);
        Site::query()->whereKey($site->id)->update(['forge_domain_status' => $domain->value, 'forge_ssl_status' => $ssl->value]);

        return $site->refresh();
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
