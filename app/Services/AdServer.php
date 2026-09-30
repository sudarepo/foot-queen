<?php

namespace App\Services;

use App\Models\Ad;
use App\Models\Site;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class AdServer
{
    /**
     * @var array<string, array<string, string>|null>
     */
    private array $resolved = [];

    /**
     * Resolve one ad for the current site and placement.
     *
     * @return array{ad: Ad, impressionUrl: string, clickUrl: string, token: string}|null
     */
    public function resolve(AdPlacement $placement, ?Site $site = null): ?array
    {
        $site ??= app(Site::class);
        $cacheKey = $site->getKey().':'.$placement->value;

        if (array_key_exists($cacheKey, $this->resolved)) {
            return $this->resolved[$cacheKey];
        }

        $ad = Ad::query()
            ->activeForPlacement($placement, $site)
            ->orderByRaw('case when site_id = ? then 1 when site_id is null then 0 else -1 end desc', [$site->getKey()])
            ->orderByDesc('weight')
            ->orderByDesc('id')
            ->first();

        if ($ad === null) {
            return $this->resolved[$cacheKey] = null;
        }

        $token = (string) Str::uuid();

        return $this->resolved[$cacheKey] = [
            'ad' => $ad,
            'token' => $token,
            'impressionUrl' => URL::temporarySignedRoute(
                'ads.impressions.store',
                now()->addDay(),
                [
                    'ad' => $ad->getKey(),
                    'placement' => $placement->value,
                    'token' => $token,
                ],
            ),
            'clickUrl' => URL::temporarySignedRoute(
                'ads.clicks.store',
                now()->addDay(),
                [
                    'ad' => $ad->getKey(),
                    'placement' => $placement->value,
                    'token' => $token,
                ],
            ),
        ];
    }
}
