<?php

namespace App\Services;

use App\Models\Cam;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

/**
 * Fetches the parts of a Chaturbate room that the online-rooms feed doesn't
 * carry: the Bio tab and the Pics & Vids tab.
 *
 * Both come from one endpoint — `api/biocontext/{username}/` — which returns
 * them as JSON for logged-out visitors, so there's nothing to scrape. It's a
 * request per performer though, which is why this is deliberately kept out of
 * the five-minute `cams:sync`: profiles change on the order of weeks, room
 * state changes constantly. See SyncCamProfiles for the backfill, and
 * CamController::show() for the first-view fetch.
 */
class CamProfileService
{
    private const ENDPOINT = 'https://chaturbate.com/api/biocontext/';

    /**
     * How long a fetched profile is treated as current. Bios and photo sets
     * are near-static; a week keeps the backfill to a trickle.
     */
    private const TTL_DAYS = 7;

    /**
     * Only Chaturbate exposes this endpoint. Other providers (when they
     * exist) will need their own fetch path, so rows from them are skipped
     * rather than failing.
     */
    private const SUPPORTED_PROVIDER = 'chaturbate';

    /**
     * Chaturbate rate-limits this endpoint and is stingy about it. Measured
     * against the live API: an unpaced loop got ~43 requests through before
     * everything after that came back 429, and even a steady 30/min drew the
     * occasional one. So the ceiling is treated as ~25/min and shared across
     * *both* callers — the backfill and the first-view fetch — because they
     * draw on the same bucket and would otherwise starve each other.
     */
    private const REQUESTS_PER_MINUTE = 25;

    private const RATE_LIMIT_KEY = 'chaturbate-profile-fetch';

    /**
     * Gap between requests in a backfill run. Slightly slower than the
     * limiter allows, so a batch paces itself rather than sprinting into
     * the cap and stalling there.
     */
    private const PACING_SECONDS = 3;

    /**
     * Consecutive throttles that end a run early. One is noise; a run of
     * them means the bucket is empty and every further request this batch
     * would make is wasted.
     */
    private const MAX_CONSECUTIVE_THROTTLES = 3;

    public function __construct(private BioSanitizer $sanitizer) {}

    public function isSupported(Cam $cam): bool
    {
        return $cam->provider === self::SUPPORTED_PROVIDER;
    }

    public function hasNeverBeenFetched(Cam $cam): bool
    {
        return $this->isSupported($cam) && $cam->profile_fetched_at === null;
    }

    public function isStale(Cam $cam): bool
    {
        if (! $this->isSupported($cam)) {
            return false;
        }

        return $cam->profile_fetched_at === null
            || $cam->profile_fetched_at->lt(now()->subDays(self::TTL_DAYS));
    }

    /**
     * Fetch and persist one performer's profile.
     *
     * Never throws: a profile is an enhancement to a page whose main content
     * is the live cam, so a failed fetch degrades the page rather than
     * breaking the request that triggered it.
     */
    public function refresh(Cam $cam): bool
    {
        return $this->attempt($cam)->isSuccessful();
    }

    /**
     * The fetch itself, reporting *why* it ended as it did so the backfill
     * can tell a performer with no profile from Chaturbate refusing us.
     */
    private function attempt(Cam $cam): ProfileFetchOutcome
    {
        if (! $this->isSupported($cam)) {
            return ProfileFetchOutcome::Unsupported;
        }

        // Declining to make the call is itself a throttle: better to leave
        // the row for the next pass than to spend a request we know the
        // other caller needs more.
        if (RateLimiter::tooManyAttempts(self::RATE_LIMIT_KEY, self::REQUESTS_PER_MINUTE)) {
            return ProfileFetchOutcome::Throttled;
        }

        RateLimiter::hit(self::RATE_LIMIT_KEY, 60);

        try {
            $response = Http::timeout(10)
                ->withHeaders(['Accept' => 'application/json'])
                ->get(self::ENDPOINT.$cam->username.'/');
        } catch (\Throwable $e) {
            Log::warning('Chaturbate profile fetch failed', [
                'username' => $cam->username,
                'error' => $e->getMessage(),
            ]);

            return ProfileFetchOutcome::Failed;
        }

        // Their limiter, not ours. Deliberately not stamped: this performer
        // hasn't been checked at all, so leaving the row untouched is what
        // puts it back at the front of the next run.
        if ($response->status() === 429) {
            return ProfileFetchOutcome::Throttled;
        }

        // A deleted or renamed performer 404s. Stamping the row anyway stops
        // every subsequent page view from re-requesting a URL that is gone —
        // the TTL will retry it in a week, by which point the row has usually
        // dropped out of the listing entirely.
        if ($response->status() === 404) {
            $cam->forceFill(['profile_fetched_at' => now()])->save();

            return ProfileFetchOutcome::NotFound;
        }

        if ($response->failed()) {
            Log::warning('Chaturbate profile fetch failed', [
                'username' => $cam->username,
                'status' => $response->status(),
            ]);

            return ProfileFetchOutcome::Failed;
        }

        $data = $response->json();

        if (! is_array($data)) {
            return ProfileFetchOutcome::Failed;
        }

        $cam->forceFill([
            'bio' => $this->sanitizer->sanitize($data['about_me'] ?? null),
            'profile_attributes' => $this->extractAttributes($data),
            'photo_sets' => $this->extractPhotoSets($data),
            'profile_fetched_at' => now(),
        ])->save();

        return ProfileFetchOutcome::Updated;
    }

    /**
     * Refresh the profiles most overdue for it, oldest (and never-fetched)
     * first, restricted to performers currently online — those are the ones
     * with pages people can reach from the listings.
     *
     * Paced rather than looped at full speed, and abandoned early once
     * Chaturbate starts refusing: a run that pushes through a wall of 429s
     * updates nothing and costs the first-view fetches their share of the
     * bucket too. Whatever it doesn't reach stays unstamped and is simply
     * first in line next run.
     *
     * @return array{attempted: int, updated: int, throttled: bool}
     */
    public function refreshStale(int $limit): array
    {
        $cams = Cam::query()
            ->where('provider', self::SUPPORTED_PROVIDER)
            ->online()
            ->where(function ($query) {
                $query->whereNull('profile_fetched_at')
                    ->orWhere('profile_fetched_at', '<', now()->subDays(self::TTL_DAYS));
            })
            // Nulls sort first on both SQLite and MySQL, so never-fetched rows
            // are picked up before merely-stale ones without a special case.
            ->orderBy('profile_fetched_at')
            ->orderByDesc('viewers')
            ->limit($limit)
            ->get();

        $updated = 0;
        $attempted = 0;
        $consecutiveThrottles = 0;

        foreach ($cams as $index => $cam) {
            if ($index > 0) {
                Sleep::for(self::PACING_SECONDS)->seconds();
            }

            $attempted++;
            $outcome = $this->attempt($cam);

            if ($outcome->isSuccessful()) {
                $updated++;
            }

            $consecutiveThrottles = $outcome === ProfileFetchOutcome::Throttled
                ? $consecutiveThrottles + 1
                : 0;

            if ($consecutiveThrottles >= self::MAX_CONSECUTIVE_THROTTLES) {
                Log::info('Cam profile backfill stopped early: rate limited', [
                    'attempted' => $attempted,
                    'updated' => $updated,
                ]);

                return ['attempted' => $attempted, 'updated' => $updated, 'throttled' => true];
            }
        }

        return ['attempted' => $attempted, 'updated' => $updated, 'throttled' => false];
    }

    /**
     * The scalar bio fields worth showing, renamed to match our own
     * vocabulary and with the empty strings the API uses for "not set"
     * normalized away so Blade can just check for null.
     *
     * `social_medias` is deliberately not kept: those are paid/free links to
     * OnlyFans, Fansly and the like — traffic we'd be sending off both our
     * site and the platform we earn on.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function extractAttributes(array $data): array
    {
        $attributes = [
            'real_name' => $data['real_name'] ?? null,
            'location' => $data['location'] ?? null,
            'languages' => $data['languages'] ?? null,
            'display_age' => $data['display_age'] ?? null,
            'birthday' => $data['display_birthday'] ?? null,
            'follower_count' => isset($data['follower_count']) ? (int) $data['follower_count'] : null,
            // Free-text on Chaturbate ("5'4\"(165cm), 108 lbs") — distinct
            // from our own normalized `body_type` column, which is derived
            // from room tags and is what the filters run on.
            'body_stats' => $data['body_type'] ?? null,
            'body_decorations' => $data['body_decorations'] ?? null,
            'smoke_drink' => $data['smoke_drink'] ?? null,
            'interested_in' => $data['interested_in'] ?? null,
            'last_broadcast' => $data['last_broadcast'] ?? null,
            'has_fanclub' => (bool) ($data['performer_has_fanclub'] ?? false),
        ];

        return array_filter(
            $attributes,
            fn ($value) => $value !== null && $value !== '' && $value !== [],
        );
    }

    /**
     * The Pics & Vids tab.
     *
     * The API's `user_can_access` / `user_has_purchased` flags describe the
     * *anonymous* session our server made the request with, so they'd be
     * wrong for any signed-in visitor — dropped rather than stored and
     * misread later. Sets still encoding (`video_ready` false) are skipped:
     * their cover isn't up yet.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function extractPhotoSets(array $data): array
    {
        $sets = [];

        foreach ($data['photo_sets'] ?? [] as $set) {
            if (empty($set['cover_url'])) {
                continue;
            }

            $isVideo = (bool) ($set['is_video'] ?? false);

            if ($isVideo && ! ($set['video_ready'] ?? true)) {
                continue;
            }

            $sets[] = [
                'id' => $set['id'] ?? null,
                'name' => $set['name'] ?? null,
                'cover_url' => $set['cover_url'],
                'tokens' => (int) ($set['tokens'] ?? 0),
                'is_video' => $isVideo,
                'fan_club_only' => (bool) ($set['fan_club_only'] ?? false),
                'photo_count' => (int) ($set['photo_count'] ?? 0),
                'num_videos' => (int) ($set['num_videos'] ?? 0),
                'duration_seconds' => (int) ($set['video_duration_in_seconds'] ?? 0),
            ];
        }

        return $sets;
    }
}
