<?php

namespace Tests\Feature;

use App\Models\Cam;
use App\Services\CamProfileService;
use App\Services\CamSyncService;
use App\Services\Providers\CamProviderInterface;
use App\Services\Providers\ChaturbateProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class CamProfileSyncTest extends TestCase
{
    use RefreshDatabase;

    private CamProfileService $profiles;

    protected function setUp(): void
    {
        parent::setUp();

        // The backfill paces itself with real sleeps between requests; faking
        // them keeps the suite fast while still recording that it paused.
        Sleep::fake();

        $this->profiles = app(CamProfileService::class);
    }

    /**
     * A trimmed `api/biocontext/{username}/` response, shaped like the real
     * one — including the affiliate markup that lives in every bio.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function biocontext(array $overrides = []): array
    {
        return array_merge([
            'follower_count' => 299355,
            'location' => 'Ukraine',
            'real_name' => 'Masha',
            'display_age' => 24,
            'display_birthday' => 'Jan. 5, 2002',
            'languages' => 'English, Українська',
            'body_type' => "5'4\"(165cm), 108 lbs",
            'smoke_drink' => 'No/no',
            'interested_in' => ['Women', 'Men'],
            'performer_has_fanclub' => true,
            'about_me' => '<p>Tip for requests — sign up <a href="https://chaturbate.com/in/?campaign=51VtT">HERE</a></p>',
            'social_medias' => [
                ['title_name' => 'OnlyFans - 1 Month', 'url' => 'https://onlyfans.com/someone'],
            ],
            'photo_sets' => [
                [
                    'id' => 24631017,
                    'name' => 'Sunset toes',
                    'cover_url' => 'https://static-pub.example.com/cover.jpg',
                    'tokens' => 200,
                    'is_video' => true,
                    'video_ready' => true,
                    'photo_count' => 0,
                    'num_videos' => 1,
                    'video_duration_in_seconds' => 227,
                    'fan_club_only' => false,
                    'user_can_access' => false,
                    'user_has_purchased' => false,
                ],
            ],
        ], $overrides);
    }

    public function test_it_stores_the_bio_attributes_and_photo_sets(): void
    {
        Http::fake(['chaturbate.com/api/biocontext/*' => Http::response($this->biocontext())]);

        $cam = Cam::factory()->create(['username' => 'anna', 'profile_fetched_at' => null]);

        $this->assertTrue($this->profiles->refresh($cam));

        $cam->refresh();
        $this->assertSame('Tip for requests — sign up HERE', $cam->bio);
        $this->assertStringNotContainsString('51VtT', $cam->bio, 'another affiliate id must never survive');
        $this->assertSame('Ukraine', $cam->profileAttribute('location'));
        $this->assertSame(299355, $cam->profileAttribute('follower_count'));
        $this->assertSame(['Women', 'Men'], $cam->profileAttribute('interested_in'));
        $this->assertNotNull($cam->profile_fetched_at);

        $this->assertCount(1, $cam->photo_sets);
        $this->assertSame('Sunset toes', $cam->photo_sets[0]['name']);
        $this->assertSame(227, $cam->photo_sets[0]['duration_seconds']);
        $this->assertTrue($cam->photo_sets[0]['is_video']);
    }

    public function test_it_requests_the_right_endpoint(): void
    {
        Http::fake(['chaturbate.com/*' => Http::response($this->biocontext())]);

        $this->profiles->refresh(Cam::factory()->create(['username' => 'anna']));

        Http::assertSent(fn ($request) => $request->url() === 'https://chaturbate.com/api/biocontext/anna/');
    }

    /**
     * These are paid links to OnlyFans and the like — traffic off both our
     * site and the platform we earn on.
     */
    public function test_it_does_not_store_social_media_links(): void
    {
        Http::fake(['chaturbate.com/*' => Http::response($this->biocontext())]);

        $cam = Cam::factory()->create(['username' => 'anna']);
        $this->profiles->refresh($cam);

        $this->assertArrayNotHasKey('social_medias', $cam->fresh()->profile_attributes);
    }

    /**
     * `user_can_access` describes the anonymous session our *server* made the
     * request with, so it would be wrong for any signed-in visitor.
     */
    public function test_it_does_not_store_per_session_access_flags(): void
    {
        Http::fake(['chaturbate.com/*' => Http::response($this->biocontext())]);

        $cam = Cam::factory()->create(['username' => 'anna']);
        $this->profiles->refresh($cam);

        $this->assertArrayNotHasKey('user_can_access', $cam->fresh()->photo_sets[0]);
        $this->assertArrayNotHasKey('user_has_purchased', $cam->fresh()->photo_sets[0]);
    }

    public function test_it_skips_photo_sets_with_no_cover_or_still_encoding(): void
    {
        Http::fake(['chaturbate.com/*' => Http::response($this->biocontext([
            'photo_sets' => [
                ['id' => 1, 'name' => 'No cover yet', 'cover_url' => '', 'is_video' => false],
                ['id' => 2, 'name' => 'Encoding', 'cover_url' => 'https://x.test/c.jpg', 'is_video' => true, 'video_ready' => false],
                ['id' => 3, 'name' => 'Ready', 'cover_url' => 'https://x.test/d.jpg', 'is_video' => true, 'video_ready' => true],
            ],
        ]))]);

        $cam = Cam::factory()->create(['username' => 'anna']);
        $this->profiles->refresh($cam);

        $sets = $cam->fresh()->photo_sets;
        $this->assertCount(1, $sets);
        $this->assertSame('Ready', $sets[0]['name']);
    }

    public function test_a_performer_with_no_bio_or_sets_is_still_marked_as_fetched(): void
    {
        Http::fake(['chaturbate.com/*' => Http::response(['about_me' => '', 'photo_sets' => []])]);

        $cam = Cam::factory()->create(['username' => 'anna', 'profile_fetched_at' => null]);
        $this->profiles->refresh($cam);

        $cam->refresh();
        $this->assertNull($cam->bio);
        $this->assertSame([], $cam->photo_sets);
        $this->assertNotNull($cam->profile_fetched_at);
    }

    /**
     * A deleted or renamed performer 404s. Stamping the row anyway stops
     * every subsequent page view from re-requesting a URL that is gone.
     */
    public function test_a_404_stamps_the_row_so_it_is_not_retried_on_every_view(): void
    {
        Http::fake(['chaturbate.com/*' => Http::response('', 404)]);

        $cam = Cam::factory()->create(['username' => 'anna', 'profile_fetched_at' => null]);

        $this->assertFalse($this->profiles->refresh($cam));
        $this->assertNotNull($cam->fresh()->profile_fetched_at);
    }

    public function test_a_server_error_leaves_the_row_alone_so_it_is_retried(): void
    {
        Http::fake(['chaturbate.com/*' => Http::response('', 503)]);

        $cam = Cam::factory()->create(['username' => 'anna', 'profile_fetched_at' => null]);

        $this->assertFalse($this->profiles->refresh($cam));
        $this->assertNull($cam->fresh()->profile_fetched_at);
    }

    public function test_a_connection_failure_does_not_throw(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $cam = Cam::factory()->create(['username' => 'anna', 'profile_fetched_at' => null]);

        $this->assertFalse($this->profiles->refresh($cam));
        $this->assertNull($cam->fresh()->profile_fetched_at);
    }

    public function test_only_chaturbate_cams_are_fetched(): void
    {
        Http::fake();

        $cam = Cam::factory()->create(['provider' => 'stripchat', 'profile_fetched_at' => null]);

        $this->assertFalse($this->profiles->isSupported($cam));
        $this->assertFalse($this->profiles->refresh($cam));
        $this->assertFalse($this->profiles->isStale($cam));
        Http::assertNothingSent();
    }

    public function test_a_profile_goes_stale_after_a_week(): void
    {
        $fresh = Cam::factory()->create(['profile_fetched_at' => now()->subDays(3)]);
        $stale = Cam::factory()->create(['profile_fetched_at' => now()->subDays(8)]);
        $never = Cam::factory()->create(['profile_fetched_at' => null]);

        $this->assertFalse($this->profiles->isStale($fresh));
        $this->assertTrue($this->profiles->isStale($stale));
        $this->assertTrue($this->profiles->isStale($never));

        $this->assertFalse($this->profiles->hasNeverBeenFetched($fresh));
        $this->assertTrue($this->profiles->hasNeverBeenFetched($never));
    }

    public function test_the_backfill_takes_never_fetched_cams_first(): void
    {
        Http::fake(['chaturbate.com/*' => Http::response($this->biocontext())]);

        $stale = Cam::factory()->create(['username' => 'stale', 'profile_fetched_at' => now()->subDays(30)]);
        $never = Cam::factory()->create(['username' => 'never', 'profile_fetched_at' => null]);

        $this->profiles->refreshStale(limit: 1);

        $this->assertNotNull($never->fresh()->bio);
        $this->assertNull($stale->fresh()->bio);
    }

    public function test_the_backfill_skips_fresh_and_offline_cams(): void
    {
        Http::fake(['chaturbate.com/*' => Http::response($this->biocontext())]);

        Cam::factory()->create(['profile_fetched_at' => now()->subHour()]);
        Cam::factory()->create(['is_online' => false, 'profile_fetched_at' => null]);

        $result = $this->profiles->refreshStale(limit: 10);

        $this->assertSame(['attempted' => 0, 'updated' => 0, 'throttled' => false], $result);
        Http::assertNothingSent();
    }

    /* ----------  Rate limiting  ---------- */

    /**
     * Measured against the live API: an unpaced loop got ~43 requests through
     * before every one after that came back 429. So the backfill leaves a gap
     * between requests rather than sprinting into the cap.
     */
    public function test_the_backfill_paces_its_requests(): void
    {
        Http::fake(['chaturbate.com/*' => Http::response($this->biocontext())]);

        Cam::factory()->count(3)->create(['profile_fetched_at' => null]);

        $this->profiles->refreshStale(limit: 3);

        // Between the three requests, not before the first.
        Sleep::assertSleptTimes(2);
    }

    /**
     * A wall of 429s means the bucket is empty; pushing through it updates
     * nothing and starves the first-view fetches of their share too.
     */
    public function test_the_backfill_gives_up_once_chaturbate_starts_refusing(): void
    {
        Http::fake(['chaturbate.com/*' => Http::response('', 429)]);

        Cam::factory()->count(20)->create(['profile_fetched_at' => null]);

        $result = $this->profiles->refreshStale(limit: 20);

        $this->assertTrue($result['throttled']);
        $this->assertSame(3, $result['attempted'], 'should stop after three consecutive throttles');
        $this->assertSame(0, $result['updated']);
        Http::assertSentCount(3);
    }

    /**
     * The occasional 429 is normal even at a sustainable rate, so one in the
     * middle of a batch is stepped over rather than ending the run.
     */
    public function test_an_isolated_throttle_does_not_end_the_run(): void
    {
        Http::fake(['chaturbate.com/*' => Http::sequence()
            ->push('', 429)
            ->push($this->biocontext())
            ->push($this->biocontext()),
        ]);

        Cam::factory()->count(3)->create(['profile_fetched_at' => null]);

        $result = $this->profiles->refreshStale(limit: 3);

        $this->assertFalse($result['throttled']);
        $this->assertSame(3, $result['attempted']);
        $this->assertSame(2, $result['updated']);
    }

    /**
     * A throttled performer must stay unstamped — it hasn't been checked at
     * all, so leaving the row alone is what puts it first in line next run.
     */
    public function test_a_throttled_performer_is_not_marked_as_fetched(): void
    {
        Http::fake(['chaturbate.com/*' => Http::response('', 429)]);

        $cam = Cam::factory()->create(['profile_fetched_at' => null]);

        $this->assertFalse($this->profiles->refresh($cam));
        $this->assertNull($cam->fresh()->profile_fetched_at);
    }

    /**
     * The backfill and the first-view fetch draw on the same bucket at
     * Chaturbate's end, so they share one limiter here — otherwise a busy
     * backfill would spend the requests page views need.
     */
    public function test_the_limiter_is_shared_and_stops_calls_before_they_are_made(): void
    {
        Http::fake(['chaturbate.com/*' => Http::response($this->biocontext())]);

        // Drain the per-minute allowance.
        Cam::factory()->count(25)->create(['profile_fetched_at' => null]);
        $this->profiles->refreshStale(limit: 25);
        Http::assertSentCount(25);

        // A page view arriving now makes no request at all.
        $cam = Cam::factory()->create(['username' => 'anna', 'profile_fetched_at' => null]);
        $this->assertFalse($this->profiles->refresh($cam));

        Http::assertSentCount(25);
        $this->assertNull($cam->fresh()->profile_fetched_at);
    }

    public function test_the_backfill_command_reports_what_it_did(): void
    {
        Http::fake(['chaturbate.com/*' => Http::response($this->biocontext())]);

        Cam::factory()->count(3)->create(['profile_fetched_at' => null]);

        $this->artisan('cams:sync-profiles', ['--limit' => 2])
            ->expectsOutputToContain('attempted: 2')
            ->expectsOutputToContain('updated:   2')
            ->assertSuccessful();

        $this->assertSame(2, Cam::whereNotNull('profile_fetched_at')->count());
    }

    /**
     * The five-minute room sync must not blank out profile data it doesn't
     * know about — it upserts a fixed column list, and these aren't on it.
     * Worth pinning down, because the failure mode is silent: bios would
     * just quietly disappear five minutes after every backfill.
     */
    public function test_the_room_sync_does_not_clobber_profile_data(): void
    {
        Http::fake(['chaturbate.com/*' => Http::response($this->biocontext())]);

        $cam = Cam::factory()->create(['username' => 'anna', 'viewers' => 10]);
        $this->profiles->refresh($cam);

        $provider = new class implements CamProviderInterface
        {
            public array $cams = [];

            public function getName(): string
            {
                return 'chaturbate';
            }

            public function fetchCams(): array
            {
                return $this->cams;
            }
        };

        $provider->cams = [[
            'provider' => 'chaturbate',
            'external_id' => $cam->external_id,
            'username' => 'anna',
            'gender' => 'female',
            'age' => 24,
            'hair_color' => 'blonde',
            'body_type' => 'slim',
            'categories' => ['feet'],
            'viewers' => 99,
            'thumbnail_url' => 'https://x.test/t.jpg',
            'room_url' => $cam->room_url,
            'is_hd' => true,
            'is_new' => false,
            'is_online' => true,
        ]];

        $this->app->instance(ChaturbateProvider::class, $provider);
        app(CamSyncService::class)->syncAll();

        $cam->refresh();
        $this->assertSame(99, $cam->viewers, 'the room sync should still update live room state');
        $this->assertNotNull($cam->bio);
        $this->assertNotEmpty($cam->photo_sets);
        $this->assertNotNull($cam->profile_fetched_at);
    }
}
