<?php

namespace Tests\Feature;

use App\Filament\Pages\AiAssistant;
use App\Models\McpToken;
use App\Models\Site;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The panel page that hands out MCP keys (App\Filament\Pages\AiAssistant) —
 * built for someone who will never open a terminal, so the key and every
 * snippet it goes into have to come out of the page ready to paste.
 */
class AiAssistantPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_open_the_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin/ai-assistant')
            ->assertSuccessful()
            ->assertSee('Create a connection key');
    }

    public function test_a_site_manager_cannot_open_the_page(): void
    {
        $site = Site::query()->where('is_default', true)->firstOrFail();

        $this->actingAs(User::factory()->siteManager($site)->create())
            ->get('/admin/ai-assistant')
            ->assertForbidden();
    }

    public function test_creating_a_key_shows_ready_to_paste_setup_with_the_key_filled_in(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $page = Livewire::test(AiAssistant::class)
            ->callAction('createKey', data: ['name' => 'Claude on my laptop', 'expires' => '90'])
            ->assertHasNoActionErrors();

        $plaintext = $page->get('newKey');
        $token = $admin->mcpTokens()->sole();

        $this->assertSame('Claude on my laptop', $token->name);
        $this->assertSame(McpToken::hash($plaintext), $token->getRawOriginal('token'));
        $this->assertEqualsWithDelta(now()->addDays(90)->timestamp, $token->expires_at->timestamp, 5);

        $page
            ->assertSee('"AUTH_HEADER": "Bearer '.$plaintext.'"')
            ->assertSee('mcp-remote')
            ->assertSee(url('/mcp/admin'))
            ->assertSee('Using foot-queen, give me an overview of the network.');
    }

    /**
     * The key is only stored hashed; once the page is left it's gone, and a
     * fresh visit must not show it (or anything that looks like it).
     */
    public function test_the_key_is_not_shown_again_on_a_fresh_visit(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(AiAssistant::class)
            ->callAction('createKey', data: ['name' => 'Claude', 'expires' => 'never']);

        $this->get('/admin/ai-assistant')
            ->assertSuccessful()
            ->assertSee('What this does')
            ->assertDontSee(McpToken::PREFIX);
    }

    public function test_a_key_created_on_the_page_works_against_the_server(): void
    {
        $this->actingAs(User::factory()->create());

        $plaintext = Livewire::test(AiAssistant::class)
            ->callAction('createKey', data: ['name' => 'Claude', 'expires' => 'never'])
            ->get('newKey');

        $this->withToken($plaintext)
            ->postJson('/mcp/admin', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []])
            ->assertOk();
    }

    public function test_a_key_can_be_revoked_from_the_table(): void
    {
        $this->actingAs(User::factory()->create());
        [$token] = McpToken::issue(User::factory()->create(), 'Old laptop');

        Livewire::test(AiAssistant::class)
            ->assertCanSeeTableRecords([$token])
            ->callAction(TestAction::make('revoke')->table($token));

        $this->assertModelMissing($token);
    }

    public function test_a_key_needs_a_name(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(AiAssistant::class)
            ->callAction('createKey', data: ['name' => ''])
            ->assertHasActionErrors(['name' => 'required']);

        $this->assertDatabaseCount('mcp_tokens', 0);
    }
}
