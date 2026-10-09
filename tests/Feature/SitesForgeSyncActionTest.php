<?php

namespace Tests\Feature;

use App\Filament\Resources\Sites\Pages\ListSites;
use App\Models\Site;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SitesForgeSyncActionTest extends TestCase
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

    public function test_dry_run_notification_lists_only_pending_changes(): void
    {
        Site::factory()->create([
            'domains' => ['new-domain.example', 'already-there.example'],
            'is_active' => true,
        ]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'already-there.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['1' => 'installed'])),
        ]);

        Livewire::test(ListSites::class)
            ->callAction('syncForgeDomains', data: ['dry_run' => true])
            ->assertNotified(
                Notification::make()
                    ->title('Forge dry run — nothing was changed yet')
                    ->body('<p><strong>Would add 1 domain</strong><br>new-domain.example</p><br><p><strong>Would request SSL for 1 domain</strong><br>new-domain.example</p>')
                    ->persistent()
                    ->info(),
            );

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_sync_notification_lists_actions_taken_and_failures(): void
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

        Livewire::test(ListSites::class)
            ->callAction('syncForgeDomains', data: ['dry_run' => false])
            ->assertNotified(
                Notification::make()
                    ->title('Forge sync finished with 1 error')
                    ->body('<p><strong>Added 1 domain</strong><br>new-domain.example</p><br><p><strong>Requested SSL for 1 domain</strong><br>new-domain.example</p><br><p><strong>Failed</strong><br><strong>broken.example</strong>: could not add domain (HTTP 422 - Invalid domain)</p>')
                    ->persistent()
                    ->danger(),
            );
    }

    public function test_sync_notification_says_when_forge_is_up_to_date(): void
    {
        Site::factory()->create([
            'domains' => ['already-there.example'],
            'is_active' => true,
        ]);

        Http::fake([
            self::BASE.'/domains?*' => Http::response($this->domainsPayload(['1' => 'already-there.example'])),
            self::BASE.'/certificates?*' => Http::response($this->certificatesPayload(['1' => 'installed'])),
        ]);

        Livewire::test(ListSites::class)
            ->callAction('syncForgeDomains', data: ['dry_run' => false])
            ->assertNotified('Forge is already up to date');
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
