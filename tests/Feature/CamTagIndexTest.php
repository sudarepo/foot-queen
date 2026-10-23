<?php

namespace Tests\Feature;

use App\Models\Cam;
use App\Models\Site;
use App\Services\CamSyncService;
use App\Services\Providers\ChaturbateProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * cam_tags is what Cam::scopeForSite matches against, so it has to follow
 * every way a cam's tags can change — otherwise a site silently shows the
 * wrong performers, or none.
 */
class CamTagIndexTest extends TestCase
{
    use RefreshDatabase;

    private function footQueen(): Site
    {
        return Site::query()->where('slug', 'foot-queen')->firstOrFail();
    }

    /**
     * @return array<int, string>
     */
    private function usernamesFor(Site $site): array
    {
        return Cam::forSite($site)->orderBy('username')->pluck('username')->all();
    }

    public function test_a_site_created_after_its_cams_still_lists_them(): void
    {
        Cam::factory()->create(['username' => 'curvy', 'tags' => ['bbw']]);
        Cam::factory()->create(['username' => 'toes', 'tags' => ['feet']]);

        $site = Site::factory()->create(['tags' => ['bbw'], 'gender' => 'female']);

        $this->assertSame(['curvy'], $this->usernamesFor($site));
    }

    public function test_editing_a_sites_tags_swaps_which_performers_it_lists(): void
    {
        Cam::factory()->create(['username' => 'curvy', 'tags' => ['bbw']]);
        Cam::factory()->create(['username' => 'inked', 'tags' => ['tattoo']]);

        $site = Site::factory()->create(['tags' => ['bbw'], 'gender' => 'female']);
        $site->update(['tags' => ['tattoo']]);

        $this->assertSame(['inked'], $this->usernamesFor($site->fresh()));
    }

    public function test_a_cam_matching_several_site_tags_is_listed_once(): void
    {
        Cam::factory()->create(['username' => 'curvytoes', 'tags' => ['feet', 'toes', 'bbw']]);

        $this->assertSame(['curvytoes'], $this->usernamesFor($this->footQueen()));
        $this->assertSame(1, Cam::online()->forSite($this->footQueen())->count());
    }

    public function test_a_cam_whose_tags_change_moves_between_sites(): void
    {
        $bbw = Site::factory()->create(['tags' => ['bbw'], 'gender' => 'female']);
        $cam = Cam::factory()->create(['username' => 'anna', 'tags' => ['feet']]);

        $cam->update(['tags' => ['bbw']]);

        $this->assertSame(['anna'], $this->usernamesFor($bbw));
        $this->assertSame([], $this->usernamesFor($this->footQueen()));
    }

    public function test_a_site_without_tags_takes_its_whole_gender(): void
    {
        Cam::factory()->create(['username' => 'anna', 'tags' => ['feet']]);
        Cam::factory()->create(['username' => 'bob', 'tags' => ['feet'], 'gender' => 'male']);

        $site = Site::factory()->create(['tags' => [], 'gender' => 'female']);

        $this->assertSame(['anna'], $this->usernamesFor($site));
    }

    public function test_the_sync_indexes_the_cams_it_upserts(): void
    {
        $bbw = Site::factory()->create(['tags' => ['bbw'], 'gender' => 'female']);

        config([
            'cam-providers.enabled' => [ChaturbateProvider::class],
            'cam-providers.chaturbate.affiliate_id' => 'Vg4Qi',
        ]);

        Http::fake([
            'chaturbate.com/api/public/affiliates/onlinerooms/*' => Http::response([
                'count' => 1,
                'results' => [[
                    'username' => 'curvytoes',
                    'gender' => 'f',
                    'tags' => ['feet', 'bbw'],
                    'current_show' => 'public',
                    'num_users' => 10,
                    'chat_room_url_revshare' => 'https://chaturbate.com/curvytoes/',
                ]],
            ]),
        ]);

        app(CamSyncService::class)->syncAll();

        $this->assertSame(['curvytoes'], $this->usernamesFor($bbw));
        $this->assertSame(['curvytoes'], $this->usernamesFor($this->footQueen()));
    }

    public function test_the_rebuild_command_recreates_the_index_from_scratch(): void
    {
        $bbw = Site::factory()->create(['tags' => ['bbw'], 'gender' => 'female']);
        Cam::factory()->create(['username' => 'curvy', 'tags' => ['bbw']]);
        Cam::factory()->create(['username' => 'toes', 'tags' => ['feet']]);

        DB::table('cam_tags')->delete();

        $this->artisan('cams:index-tags')->assertSuccessful();

        $this->assertSame(['curvy'], $this->usernamesFor($bbw));
        $this->assertSame(['toes'], $this->usernamesFor($this->footQueen()));
    }

    public function test_the_online_count_is_cached_briefly(): void
    {
        $site = $this->footQueen();
        Cam::factory()->create(['tags' => ['feet']]);

        $this->assertSame(1, Cam::onlineCountFor($site));

        Cam::factory()->create(['tags' => ['feet']]);

        $this->assertSame(1, Cam::onlineCountFor($site));

        $this->travel(61)->seconds();

        $this->assertSame(2, Cam::onlineCountFor($site));
    }
}
