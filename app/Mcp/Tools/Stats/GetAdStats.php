<?php

namespace App\Mcp\Tools\Stats;

use App\Mcp\Support\SiteLookup;
use App\Mcp\Tools\AdminTool;
use App\Models\Ad;
use App\Models\AdClick;
use App\Models\AdImpression;
use App\Services\CountryDetector;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Ad impressions, clicks and CTR per ad over a date range (default last 30 days), optionally for one site, ad or visitor country, with a per-country breakdown.')]
#[IsReadOnly]
class GetAdStats extends AdminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'site' => ['nullable'],
            'ad_id' => ['nullable', 'integer'],
            'country' => ['nullable', 'string', 'size:2'],
            'from' => ['nullable', 'date'],
            'until' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $site = SiteLookup::find($validated['site'] ?? null, withTrashed: true);

        if (filled($validated['site'] ?? null) && $site === null) {
            return Response::error("No site matches \"{$validated['site']}\".");
        }

        $from = $validated['from'] ?? now()->subDays(30)->toDateString();
        $until = $validated['until'] ?? now()->toDateString();
        $country = isset($validated['country']) ? strtoupper($validated['country']) : null;

        $scope = fn (Builder $query) => $query
            ->when($site, fn ($query) => $query->where('site_id', $site->id))
            ->when($validated['ad_id'] ?? null, fn ($query, int $adId) => $query->where('ad_id', $adId))
            ->when($country, fn ($query) => $query->where('country', $country))
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $until);

        $impressions = AdImpression::query()->tap($scope)->selectRaw('ad_id, count(*) as aggregate')->groupBy('ad_id')->pluck('aggregate', 'ad_id');
        $clicks = AdClick::query()->tap($scope)->selectRaw('ad_id, count(*) as aggregate')->groupBy('ad_id')->pluck('aggregate', 'ad_id');

        $impressionsByCountry = AdImpression::query()->tap($scope)->selectRaw('country, count(*) as aggregate')->groupBy('country')->pluck('aggregate', 'country');
        $clicksByCountry = AdClick::query()->tap($scope)->selectRaw('country, count(*) as aggregate')->groupBy('country')->pluck('aggregate', 'country');

        $byCountry = $impressionsByCountry->keys()->merge($clicksByCountry->keys())->unique()
            ->mapWithKeys(fn (?string $code) => [($code ?: 'unknown') => [
                'name' => CountryDetector::name($code ?: null),
                'impressions' => (int) ($impressionsByCountry[$code] ?? 0),
                'clicks' => (int) ($clicksByCountry[$code] ?? 0),
                'ctr_percent' => ($impressionsByCountry[$code] ?? 0) > 0 ? round(100 * ($clicksByCountry[$code] ?? 0) / $impressionsByCountry[$code], 2) : null,
            ]])
            ->sortByDesc('impressions')
            ->all();

        $ads = Ad::query()->whereIn('id', $impressions->keys()->merge($clicks->keys())->unique())->get()->keyBy('id');

        $rows = $ads->map(fn (Ad $ad): array => [
            'ad_id' => $ad->id,
            'advertiser' => $ad->advertiser,
            'campaign' => $ad->campaign,
            'creative' => $ad->creative,
            'placement' => $ad->placement,
            'impressions' => $impressions[$ad->id] ?? 0,
            'clicks' => $clicks[$ad->id] ?? 0,
            'ctr_percent' => ($impressions[$ad->id] ?? 0) > 0 ? round(100 * ($clicks[$ad->id] ?? 0) / $impressions[$ad->id], 2) : null,
        ])->sortByDesc('impressions')->values();

        return Response::json([
            'range' => ['from' => $from, 'until' => $until],
            'site' => $site?->only(['id', 'slug', 'name']) ?? 'all sites',
            'country' => $country ?? 'all countries',
            'totals' => [
                'impressions' => $rows->sum('impressions'),
                'clicks' => $rows->sum('clicks'),
            ],
            'ads' => $rows->all(),
            'by_country' => $byCountry,
            'notes' => 'Events before '.CountryDetector::TRACKING_SINCE.' have no country (by_country key "unknown").',
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('Site id or slug.'),
            'ad_id' => $schema->integer(),
            'country' => $schema->string()->description('ISO 3166-1 alpha-2 visitor country code, e.g. "US".'),
            'from' => $schema->string()->description('Start date YYYY-MM-DD (default 30 days ago).'),
            'until' => $schema->string()->description('End date YYYY-MM-DD, inclusive (default today).'),
        ];
    }
}
