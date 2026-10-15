<?php

namespace Tests\Feature;

use App\Mcp\Servers\AdminServer;
use App\Mcp\Tools\Ads\CreateAd;
use App\Mcp\Tools\Ads\GetAd;
use App\Mcp\Tools\Ads\ListAds;
use App\Mcp\Tools\Ads\UpdateAd;
use App\Mcp\Tools\Cams\GetCam;
use App\Mcp\Tools\Cams\ListCams;
use App\Mcp\Tools\Operations\SyncForgeDomains;
use App\Mcp\Tools\Sites\CreateSite;
use App\Mcp\Tools\Sites\DeleteSite;
use App\Mcp\Tools\Sites\GetSite;
use App\Mcp\Tools\Sites\ListSites;
use App\Mcp\Tools\Sites\RestoreSite;
use App\Mcp\Tools\Sites\UpdateSite;
use App\Mcp\Tools\Stats\GetAdStats;
use App\Mcp\Tools\Stats\GetChaturbateRevenue;
use App\Mcp\Tools\Stats\GetNetworkOverview;
use App\Mcp\Tools\Stats\GetTrafficStats;
use App\Mcp\Tools\Users\CreateUser;
use App\Mcp\Tools\Users\DeleteUser;
use App\Mcp\Tools\Users\ListUsers;
use App\Mcp\Tools\Users\UpdateUser;
use App\Models\Ad;
use App\Models\Cam;
use App\Models\CamClickEvent;
use App\Models\ChaturbateStatsDay;
use App\Models\McpToken;
use App\Models\PageViewEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\Forge\ForgeDomainProvisioner;
use App\Services\HomepageLayout;
use App\Services\LegalPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The admin MCP server at /mcp/admin: who gets in, and what the tools do once
 * they're there.
 */
class AdminMcpServerTest extends TestCase
{
    use RefreshDatabase;

    /** 1×1 transparent PNG. */
    private const PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\0\0\x01\x01\0\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";

    /* ----------  Authentication  ---------- */

    public function test_a_request_without_a_token_is_rejected(): void
    {
        $this->postJson('/mcp/admin', $this->listToolsPayload())
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate');
    }

    public function test_an_unknown_token_is_rejected(): void
    {
        $this->withToken('fqmcp_not-a-real-token')
            ->postJson('/mcp/admin', $this->listToolsPayload())
            ->assertUnauthorized();
    }

    public function test_an_expired_token_is_rejected(): void
    {
        [$token, $plaintext] = McpToken::issue(User::factory()->create(), 'old', now()->subMinute());

        $this->withToken($plaintext)
            ->postJson('/mcp/admin', $this->listToolsPayload())
            ->assertUnauthorized();
    }

    /**
     * A token belongs to a person, not a permission — demote them and every
     * token they hold stops working without anyone having to revoke it.
     */
    public function test_a_token_belonging_to_a_non_admin_is_forbidden(): void
    {
        $site = Site::query()->where('is_default', true)->firstOrFail();
        [, $plaintext] = McpToken::issue(User::factory()->siteManager($site)->create(), 'manager');

        $this->withToken($plaintext)
            ->postJson('/mcp/admin', $this->listToolsPayload())
            ->assertForbidden();
    }

    public function test_an_admin_token_lists_the_tools_and_records_its_use(): void
    {
        [$token, $plaintext] = McpToken::issue(User::factory()->create(), 'claude');

        $this->withToken($plaintext)
            ->postJson('/mcp/admin', $this->listToolsPayload())
            ->assertOk()
            ->assertJsonFragment(['name' => 'update-site'])
            ->assertJsonFragment(['name' => 'get-traffic-stats']);

        $this->assertNotNull($token->refresh()->last_used_at);
    }

    public function test_only_a_hash_of_the_token_is_stored(): void
    {
        [$token, $plaintext] = McpToken::issue(User::factory()->create(), 'claude');

        $this->assertStringStartsWith(McpToken::PREFIX, $plaintext);
        $this->assertSame(hash('sha256', $plaintext), $token->getRawOriginal('token'));
        $this->assertDatabaseMissing('mcp_tokens', ['token' => $plaintext]);
    }

    public function test_tools_are_not_available_to_a_non_admin_even_inside_the_server(): void
    {
        $site = Site::query()->where('is_default', true)->firstOrFail();
        $manager = User::factory()->siteManager($site)->create();

        AdminServer::actingAs($manager)
            ->tool(UpdateSite::class, ['site' => $site->id, 'name' => 'Hijacked'])
            ->assertHasErrors();

        $this->assertNotSame('Hijacked', $site->refresh()->name);
    }

    /* ----------  Sites  ---------- */

    public function test_update_site_changes_only_the_fields_passed(): void
    {
        $site = Site::factory()->create(['name' => 'Old Name', 'tagline' => 'Keep me']);

        AdminServer::actingAs(User::factory()->create())
            ->tool(UpdateSite::class, [
                'site' => $site->slug,
                'name' => 'New Name',
                'domains' => ['Example.COM', 'www.example.com'],
                'home_layout_mobile' => 'feed',
            ])
            ->assertOk()
            ->assertSee('New Name');

        $site->refresh();

        $this->assertSame('New Name', $site->name);
        $this->assertSame('Keep me', $site->tagline);
        $this->assertSame(['example.com', 'www.example.com'], $site->domains);
        $this->assertSame(HomepageLayout::Feed, $site->home_layout_mobile);
    }

    /**
     * Host resolution is cached; an MCP edit has to invalidate it the same
     * way a panel edit does, or a new domain wouldn't resolve until the
     * cache was cleared by hand.
     */
    public function test_update_site_makes_a_new_domain_resolve_immediately(): void
    {
        $site = Site::factory()->create(['domains' => ['before.test']]);
        Site::registry();

        AdminServer::actingAs(User::factory()->create())
            ->tool(UpdateSite::class, ['site' => $site->id, 'domains' => ['after.test']])
            ->assertOk();

        $this->assertTrue(Site::resolveForHost('after.test')->is($site));
    }

    public function test_update_site_rejects_a_domain_with_a_scheme(): void
    {
        $site = Site::factory()->create();

        AdminServer::actingAs(User::factory()->create())
            ->tool(UpdateSite::class, ['site' => $site->id, 'domains' => ['https://example.com/']])
            ->assertHasErrors();
    }

    public function test_legal_page_overrides_merge_per_page_and_blank_text_restores_the_default(): void
    {
        $site = Site::factory()->create(['legal_pages' => [
            LegalPage::Privacy->value => ['title' => 'Our Privacy', 'body' => '<p>Custom privacy</p>'],
            LegalPage::Dmca->value => ['title' => null, 'body' => '<p>Custom DMCA</p>'],
        ]]);

        AdminServer::actingAs(User::factory()->create())
            ->tool(UpdateSite::class, ['site' => $site->id, 'legal_pages' => [
                LegalPage::Dmca->value => ['title' => '', 'body' => '<p></p>'],
            ]])
            ->assertOk();

        $site->refresh();

        $this->assertSame('<p>Custom privacy</p>', $site->legalOverride(LegalPage::Privacy, 'body'));
        $this->assertNull($site->legalOverride(LegalPage::Dmca, 'body'));
    }

    public function test_update_site_downloads_a_logo_from_a_url(): void
    {
        Storage::fake('public');
        Http::fake(['cdn.test/*' => Http::response(self::PNG)]);
        $site = Site::factory()->create();

        AdminServer::actingAs(User::factory()->create())
            ->tool(UpdateSite::class, ['site' => $site->id, 'logo_url' => 'https://cdn.test/logo.png'])
            ->assertOk();

        $path = $site->refresh()->logo_path;

        $this->assertStringStartsWith('sites/logos/', $path);
        $this->assertStringEndsWith('.png', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_a_url_that_is_not_an_image_is_refused(): void
    {
        Storage::fake('public');
        Http::fake(['cdn.test/*' => Http::response('<html>nope</html>')]);
        $site = Site::factory()->create();

        AdminServer::actingAs(User::factory()->create())
            ->tool(UpdateSite::class, ['site' => $site->id, 'logo_url' => 'https://cdn.test/logo.png'])
            ->assertHasErrors();

        $this->assertNull($site->refresh()->logo_path);
    }

    public function test_create_site_derives_the_slug_from_the_name(): void
    {
        AdminServer::actingAs(User::factory()->create())
            ->tool(CreateSite::class, ['name' => 'Toe Town', 'domains' => ['toetown.com'], 'tags' => ['feet']])
            ->assertOk();

        $site = Site::query()->where('slug', 'toe-town')->firstOrFail();

        $this->assertTrue($site->is_active);
        $this->assertSame(['feet'], $site->tags);
    }

    public function test_get_site_reports_every_setting(): void
    {
        $site = Site::factory()->create(['track_prefix' => 'zz']);

        AdminServer::actingAs(User::factory()->create())
            ->tool(GetSite::class, ['site' => $site->slug])
            ->assertOk()
            ->assertSee(['"track_prefix":"zz"', '"legal_pages"', '"/privacy-policy"']);
    }

    public function test_a_site_can_be_deleted_and_restored(): void
    {
        $site = Site::factory()->create();
        $admin = User::factory()->create();

        AdminServer::actingAs($admin)->tool(DeleteSite::class, ['site' => $site->id])->assertOk();
        $this->assertSoftDeleted($site);

        AdminServer::actingAs($admin)->tool(RestoreSite::class, ['site' => $site->id])->assertOk();
        $this->assertNotSoftDeleted($site);
    }

    /* ----------  Ads  ---------- */

    public function test_an_image_banner_without_an_image_is_refused(): void
    {
        AdminServer::actingAs(User::factory()->create())
            ->tool(CreateAd::class, [
                'placement' => 'homepage_header',
                'advertiser' => 'Acme',
                'campaign' => 'Fall',
                'creative' => 'v1',
                'destination_url' => 'https://acme.test',
            ])
            ->assertHasErrors(['image_url']);

        $this->assertDatabaseCount('ads', 0);
    }

    public function test_an_embed_ad_can_be_created_for_one_site_and_paused(): void
    {
        $site = Site::factory()->create();
        $admin = User::factory()->create();

        AdminServer::actingAs($admin)
            ->tool(CreateAd::class, [
                'site' => $site->slug,
                'placement' => 'homepage_header',
                'advertiser' => 'Acme',
                'campaign' => 'Fall',
                'creative' => 'v1',
                'embed_code' => '<iframe src="https://ads.test"></iframe>',
            ])
            ->assertOk();

        $ad = Ad::query()->sole();
        $this->assertTrue($ad->site->is($site));
        $this->assertTrue($ad->is_active);

        AdminServer::actingAs($admin)
            ->tool(UpdateAd::class, ['ad_id' => $ad->id, 'is_active' => false])
            ->assertOk();

        $this->assertFalse($ad->refresh()->is_active);
    }

    public function test_an_image_banner_downloads_its_image(): void
    {
        Storage::fake('public');
        Http::fake(['cdn.test/*' => Http::response(self::PNG)]);

        AdminServer::actingAs(User::factory()->create())
            ->tool(CreateAd::class, [
                'placement' => 'video_page_header',
                'advertiser' => 'Acme',
                'campaign' => 'Fall',
                'creative' => 'v1',
                'destination_url' => 'https://acme.test',
                'image_url' => 'https://cdn.test/banner.png',
            ])
            ->assertOk();

        Storage::disk('public')->assertExists(Ad::query()->sole()->image_path);
    }

    /* ----------  Users  ---------- */

    public function test_a_site_manager_can_be_created(): void
    {
        $site = Site::factory()->create();

        AdminServer::actingAs(User::factory()->create())
            ->tool(CreateUser::class, [
                'name' => 'Partner',
                'email' => 'partner@example.com',
                'password' => 'secret-password',
                'site_ids' => [$site->id],
            ])
            ->assertOk();

        $user = User::query()->where('email', 'partner@example.com')->sole();

        $this->assertFalse($user->isAdmin());
        $this->assertTrue($user->administers($site));
    }

    public function test_an_admin_cannot_demote_or_delete_themselves(): void
    {
        $admin = User::factory()->create();

        AdminServer::actingAs($admin)
            ->tool(UpdateUser::class, ['user_id' => $admin->id, 'is_admin' => false])
            ->assertHasErrors();

        AdminServer::actingAs($admin)
            ->tool(DeleteUser::class, ['user_id' => $admin->id])
            ->assertHasErrors();

        $this->assertTrue($admin->refresh()->isAdmin());
    }

    public function test_an_admin_can_demote_someone_else(): void
    {
        $other = User::factory()->create();

        AdminServer::actingAs(User::factory()->create())
            ->tool(UpdateUser::class, ['user_id' => $other->id, 'is_admin' => false])
            ->assertOk();

        $this->assertFalse($other->refresh()->isAdmin());
    }

    /* ----------  Stats  ---------- */

    public function test_traffic_stats_count_views_and_clicks_for_a_site(): void
    {
        $site = Site::factory()->create();
        $other = Site::factory()->create();

        PageViewEvent::query()->insert([
            ['page' => 'grid', 'site_id' => $site->id, 'device' => 'mobile', 'created_at' => now(), 'updated_at' => now()],
            ['page' => 'grid', 'site_id' => $site->id, 'device' => 'desktop', 'created_at' => now(), 'updated_at' => now()],
            ['page' => 'grid', 'site_id' => $other->id, 'device' => 'mobile', 'created_at' => now(), 'updated_at' => now()],
        ]);
        CamClickEvent::query()->insert([
            ['cam_id' => Cam::factory()->create()->id, 'source_page' => 'grid', 'site_id' => $site->id, 'device' => 'mobile', 'created_at' => now(), 'updated_at' => now()],
        ]);

        AdminServer::actingAs(User::factory()->create())
            ->tool(GetTrafficStats::class, ['site' => $site->slug])
            ->assertOk()
            ->assertSee(['"pageviews":2', '"outbound_clicks":1', '"ctr_percent":50']);
    }

    public function test_the_network_overview_runs(): void
    {
        AdminServer::actingAs(User::factory()->create())
            ->tool(GetNetworkOverview::class)
            ->assertOk()
            ->assertSee('"forge_configured"');
    }

    /**
     * Every read-only tool answers against a populated database, so a typo
     * in one can't hide until an agent happens to call it.
     */
    public function test_every_read_tool_responds(): void
    {
        $site = Site::factory()->create();
        $cam = Cam::factory()->create();
        $ad = Ad::query()->create([
            'placement' => 'homepage_header', 'advertiser' => 'Acme', 'campaign' => 'Fall', 'creative' => 'v1',
            'embed_code' => '<iframe></iframe>', 'is_active' => true, 'weight' => 1,
        ]);
        ChaturbateStatsDay::query()->create(['date' => now()->toDateString(), 'program' => 'revshare', 'payout' => 12.5, 'is_ledger' => false, 'data' => ['Raw Hits' => 10]]);

        $calls = [
            ListSites::class => ['status' => 'active'],
            ListAds::class => ['site' => $site->slug],
            GetAd::class => ['ad_id' => $ad->id],
            ListUsers::class => [],
            ListCams::class => ['site' => $site->id, 'online_only' => false],
            GetCam::class => ['cam' => $cam->username],
            GetAdStats::class => [],
            GetChaturbateRevenue::class => [],
        ];

        $admin = User::factory()->create();

        foreach ($calls as $tool => $arguments) {
            AdminServer::actingAs($admin)->tool($tool, $arguments)->assertOk();
        }

        AdminServer::actingAs($admin)
            ->tool(GetChaturbateRevenue::class)
            ->assertSee('"payout_usd":12.5');
    }

    public function test_forge_tools_explain_when_forge_is_not_configured(): void
    {
        $site = Site::factory()->create();

        $this->mock(ForgeDomainProvisioner::class, fn ($mock) => $mock->shouldReceive('isConfigured')->andReturnFalse());

        AdminServer::actingAs(User::factory()->create())
            ->tool(SyncForgeDomains::class, ['site' => $site->id])
            ->assertHasErrors(['Forge is not configured']);
    }

    /* ----------  Token commands  ---------- */

    public function test_the_issue_command_mints_a_token_for_an_admin(): void
    {
        $admin = User::factory()->create(['email' => 'boss@example.com']);

        $this->artisan('mcp:issue-token', ['email' => 'boss@example.com', '--name' => 'Claude', '--expires' => 30])
            ->expectsOutputToContain('Copy it now')
            ->assertSuccessful();

        $token = $admin->mcpTokens()->sole();
        $this->assertSame('Claude', $token->name);
        $this->assertTrue($token->expires_at->isFuture());
    }

    public function test_the_issue_command_refuses_a_non_admin(): void
    {
        $site = Site::query()->where('is_default', true)->firstOrFail();
        User::factory()->siteManager($site)->create(['email' => 'partner@example.com']);

        $this->artisan('mcp:issue-token', ['email' => 'partner@example.com'])->assertFailed();

        $this->assertDatabaseCount('mcp_tokens', 0);
    }

    public function test_a_revoked_token_stops_working(): void
    {
        [$token, $plaintext] = McpToken::issue(User::factory()->create(), 'claude');

        $this->artisan('mcp:revoke-token', ['id' => $token->id])->assertSuccessful();

        $this->withToken($plaintext)
            ->postJson('/mcp/admin', $this->listToolsPayload())
            ->assertUnauthorized();
    }

    /**
     * @return array{jsonrpc: string, id: int, method: string, params: array<string, mixed>}
     */
    private function listToolsPayload(): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []];
    }
}
