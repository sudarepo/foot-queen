<?php

namespace App\Mcp\Tools\Stats;

use App\Mcp\Support\SiteLookup;
use App\Mcp\Tools\AdminTool;
use App\Models\CamClickEvent;
use App\Models\PageViewEvent;
use App\Models\Site;
use App\Services\DeviceDetector;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Pageviews, outbound clicks and CTR — the numbers behind the Stats and A/B Test dashboards. Broken down by page (grid "/", feed "/feed", profile…), device, site and day. Defaults to the last 30 days.')]
#[IsReadOnly]
class GetTrafficStats extends AdminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'site' => ['nullable'],
            'device' => ['nullable', 'in:'.DeviceDetector::MOBILE.','.DeviceDetector::DESKTOP],
            'from' => ['nullable', 'date'],
            'until' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $site = SiteLookup::find($validated['site'] ?? null, withTrashed: true);

        if (filled($validated['site'] ?? null) && $site === null) {
            return Response::error("No site matches \"{$validated['site']}\".");
        }

        $from = $validated['from'] ?? now()->subDays(30)->toDateString();
        $until = $validated['until'] ?? now()->toDateString();
        $device = $validated['device'] ?? null;

        $scope = fn (Builder $query) => $query
            ->when($site, fn ($query) => $query->where('site_id', $site->id))
            ->when($device, fn ($query) => $query->where('device', $device))
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $until);

        $views = PageViewEvent::query()->tap($scope);
        $clicks = CamClickEvent::query()->tap($scope);

        $totalViews = (clone $views)->count();
        $totalClicks = (clone $clicks)->count();

        $viewsByPage = (clone $views)->selectRaw('page, count(*) as aggregate')->groupBy('page')->pluck('aggregate', 'page');
        $clicksBySource = (clone $clicks)->selectRaw('source_page, count(*) as aggregate')->groupBy('source_page')->pluck('aggregate', 'source_page');

        return Response::json([
            'range' => ['from' => $from, 'until' => $until],
            'site' => $site?->only(['id', 'slug', 'name']) ?? 'all sites (pooled)',
            'device' => $device ?? 'all devices',
            'totals' => [
                'pageviews' => $totalViews,
                'outbound_clicks' => $totalClicks,
                'ctr_percent' => self::ctr($totalClicks, $totalViews),
            ],
            'by_page' => $viewsByPage->keys()->merge($clicksBySource->keys())->unique()->values()
                ->mapWithKeys(fn (?string $page) => [$page ?? 'unknown' => [
                    'pageviews' => (int) ($viewsByPage[$page] ?? 0),
                    'clicks' => (int) ($clicksBySource[$page] ?? 0),
                    'ctr_percent' => self::ctr((int) ($clicksBySource[$page] ?? 0), (int) ($viewsByPage[$page] ?? 0)),
                ]])->all(),
            'by_device' => (clone $views)->selectRaw('device, count(*) as aggregate')->groupBy('device')
                ->pluck('aggregate', 'device')->mapWithKeys(fn ($count, $key) => [$key ?: 'unrecorded' => (int) $count])->all(),
            'by_site' => $site ? null : self::bySite($views, $clicks),
            'by_day' => self::byDay($views, $clicks),
            'notes' => 'A/B test: compare by_page.grid vs by_page.feed CTR. profile CTR is the share of profile visitors who went on to Chaturbate. Events before '.DeviceDetector::TRACKING_SINCE.' have no device.',
        ]);
    }

    private static function ctr(int $clicks, int $views): ?float
    {
        return $views > 0 ? round(100 * $clicks / $views, 2) : null;
    }

    /**
     * @param  Builder<PageViewEvent>  $views
     * @param  Builder<CamClickEvent>  $clicks
     * @return array<string, array{pageviews:int, clicks:int, ctr_percent:?float}>
     */
    private static function bySite(Builder $views, Builder $clicks): array
    {
        $viewCounts = (clone $views)->selectRaw('site_id, count(*) as aggregate')->groupBy('site_id')->pluck('aggregate', 'site_id');
        $clickCounts = (clone $clicks)->selectRaw('site_id, count(*) as aggregate')->groupBy('site_id')->pluck('aggregate', 'site_id');
        $names = Site::withTrashed()->pluck('slug', 'id');

        return $viewCounts->keys()->merge($clickCounts->keys())->unique()
            ->mapWithKeys(fn ($siteId) => [($names[$siteId] ?? 'unattributed') => [
                'pageviews' => (int) ($viewCounts[$siteId] ?? 0),
                'clicks' => (int) ($clickCounts[$siteId] ?? 0),
                'ctr_percent' => self::ctr((int) ($clickCounts[$siteId] ?? 0), (int) ($viewCounts[$siteId] ?? 0)),
            ]])
            ->sortByDesc('pageviews')
            ->all();
    }

    /**
     * @param  Builder<PageViewEvent>  $views
     * @param  Builder<CamClickEvent>  $clicks
     * @return array<string, array{pageviews:int, clicks:int}>
     */
    private static function byDay(Builder $views, Builder $clicks): array
    {
        $viewCounts = (clone $views)->selectRaw('date(created_at) as day, count(*) as aggregate')->groupBy('day')->pluck('aggregate', 'day');
        $clickCounts = (clone $clicks)->selectRaw('date(created_at) as day, count(*) as aggregate')->groupBy('day')->pluck('aggregate', 'day');

        return $viewCounts->keys()->merge($clickCounts->keys())->unique()->sort()->values()
            ->mapWithKeys(fn (string $day) => [$day => [
                'pageviews' => (int) ($viewCounts[$day] ?? 0),
                'clicks' => (int) ($clickCounts[$day] ?? 0),
            ]])->all();
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('Site id or slug; omit to pool every site.'),
            'device' => $schema->string()->enum([DeviceDetector::MOBILE, DeviceDetector::DESKTOP]),
            'from' => $schema->string()->description('Start date YYYY-MM-DD (default 30 days ago).'),
            'until' => $schema->string()->description('End date YYYY-MM-DD, inclusive (default today).'),
        ];
    }
}
