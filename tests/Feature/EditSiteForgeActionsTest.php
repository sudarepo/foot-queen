<?php

namespace Tests\Feature;

use App\Filament\Resources\Sites\Pages\EditSite;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class EditSiteForgeActionsTest extends TestCase
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

        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    public function test_add_to_forge_is_shown_and_adds_only_the_domain_when_missing(): void
    {
        $site = Site::factory()->create(['domains' => ['new-domain.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload([])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload([])),
            self::BASE.'/domains' => Http::response(['data' => ['id' => '55', 'type' => 'domainRecords']], 201),
        ]);

        Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
            ->assertActionVisible('addToForge')
            ->assertActionHidden('requestSsl')
            ->callAction('addToForge')
            ->assertNotified('Forge sync finished');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::BASE.'/domains'
            && $request['name'] === 'new-domain.example');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/certificates')
            && $request->method() === 'POST');
    }

    public function test_request_ssl_is_shown_and_requests_only_the_certificate_when_domain_exists(): void
    {
        $site = Site::factory()->create(['domains' => ['no-ssl.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['7' => 'no-ssl.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['7' => 'failed'])),
            self::BASE.'/domains/7/certificates' => Http::response(['data' => ['id' => '901']], 201),
        ]);

        Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
            ->assertActionHidden('addToForge')
            ->assertActionVisible('requestSsl')
            ->callAction('requestSsl')
            ->assertNotified('Forge sync finished');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::BASE.'/domains/7/certificates');
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::BASE.'/domains');
    }

    public function test_no_forge_actions_are_shown_when_the_site_is_fully_provisioned(): void
    {
        $site = Site::factory()->create(['domains' => ['done.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'done.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['1' => 'installed'])),
        ]);

        Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
            ->assertActionHidden('addToForge')
            ->assertActionHidden('requestSsl');
    }

    public function test_no_forge_actions_are_shown_when_forge_is_unreachable(): void
    {
        $site = Site::factory()->create(['domains' => ['new-domain.example']]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response('Server error', 500),
        ]);

        Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
            ->assertActionHidden('addToForge')
            ->assertActionHidden('requestSsl');
    }

    public function test_no_forge_actions_are_shown_when_forge_is_not_configured(): void
    {
        config()->set('services.forge.token', null);

        $site = Site::factory()->create(['domains' => ['new-domain.example']]);

        Http::fake();

        Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
            ->assertActionHidden('addToForge')
            ->assertActionHidden('requestSsl');

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
