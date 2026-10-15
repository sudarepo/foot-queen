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
 * snippet it goes into have to come out of the page ready to paste, and stay
 * there for the next time they set up a computer.
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

        $token = $admin->mcpTokens()->sole();
        $plaintext = $token->secret;

        $this->assertSame('Claude on my laptop', $token->name);
        $this->assertSame(McpToken::hash($plaintext), $token->getRawOriginal('token'));
        $this->assertEqualsWithDelta(now()->addDays(90)->timestamp, $token->expires_at->timestamp, 5);

        $page
            ->assertSet('keyId', $token->id)
            ->assertSee('"AUTH_HEADER": "Bearer '.$plaintext.'"')
            ->assertSee('mcp-remote')
            ->assertSee(url('/mcp/admin'))
            ->assertSee('Using foot-queen, give me an overview of the network.');
    }

    /**
     * The whole point of keeping an encrypted copy: closing the tab before
     * finishing setup, or setting up a second computer next week, must not
     * mean starting over.
     */
    public function test_the_setup_steps_are_still_there_on_a_later_visit(): void
    {
        $admin = User::factory()->create();
        [, $plaintext] = McpToken::issue($admin, 'Laptop');

        $this->actingAs($admin)
            ->get('/admin/ai-assistant')
            ->assertSuccessful()
            ->assertSee('Setting up Claude with your key')
            ->assertSee($plaintext);
    }

    public function test_the_key_is_stored_encrypted_not_in_plain_text(): void
    {
        [$token, $plaintext] = McpToken::issue(User::factory()->create(), 'Laptop');

        $this->assertNotSame($plaintext, $token->getRawOriginal('secret'));
        $this->assertSame($plaintext, $token->refresh()->secret);
        $this->assertArrayNotHasKey('secret', $token->toArray());
    }

    public function test_setup_steps_can_be_shown_for_an_older_key(): void
    {
        $admin = User::factory()->create();
        [$older, $olderPlaintext] = McpToken::issue($admin, 'Old laptop');
        McpToken::issue($admin, 'New laptop');
        $this->actingAs($admin);

        Livewire::test(AiAssistant::class)
            ->assertSee('New laptop')
            ->callAction(TestAction::make('showSetup')->table($older))
            ->assertSet('keyId', $older->id)
            ->assertSee('"AUTH_HEADER": "Bearer '.$olderPlaintext.'"');
    }

    public function test_an_admin_only_sees_their_own_keys(): void
    {
        $admin = User::factory()->create();
        [$mine] = McpToken::issue($admin, 'Mine');
        [$theirs, $theirPlaintext] = McpToken::issue(User::factory()->create(), 'Someone elses');
        $this->actingAs($admin);

        Livewire::test(AiAssistant::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs])
            ->assertDontSee($theirPlaintext);
    }

    /**
     * The selected key travels in the URL (?key=…), so it's the one input an
     * admin could tamper with to reach someone else's key.
     */
    public function test_another_admins_key_cannot_be_opened_through_the_url(): void
    {
        $admin = User::factory()->create();
        [$theirs, $theirPlaintext] = McpToken::issue(User::factory()->create(), 'Someone elses');

        $this->actingAs($admin)
            ->get('/admin/ai-assistant?key='.$theirs->id)
            ->assertSuccessful()
            ->assertDontSee($theirPlaintext)
            ->assertDontSee('Setting up Claude with your key');
    }

    public function test_a_key_created_on_the_page_works_against_the_server(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        Livewire::test(AiAssistant::class)
            ->callAction('createKey', data: ['name' => 'Claude', 'expires' => 'never']);

        $this->withToken($admin->mcpTokens()->sole()->secret)
            ->postJson('/mcp/admin', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []])
            ->assertOk();
    }

    public function test_a_key_can_be_revoked_from_the_table(): void
    {
        $admin = User::factory()->create();
        [$token] = McpToken::issue($admin, 'Old laptop');
        $this->actingAs($admin);

        Livewire::test(AiAssistant::class)
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
