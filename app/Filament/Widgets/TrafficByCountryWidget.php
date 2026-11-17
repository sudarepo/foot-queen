<?php

namespace App\Filament\Widgets;

use App\Models\CamClickEvent;
use App\Models\PageViewEvent;
use App\Services\CountryDetector;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Where the traffic comes from: pageviews and outbound clicks for the top
 * countries in range, with each country's CTR in its label. Honours every
 * page filter, country included — filtered to one country it's a single bar,
 * which is still the right answer.
 */
class TrafficByCountryWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    /**
     * Past this many bars the labels stop being readable; the long tail is
     * folded into "Other" so the totals still add up.
     */
    public const TOP_COUNTRIES = 15;

    protected static bool $isLazy = false;

    protected ?string $heading = 'Traffic by country';

    protected ?string $description = 'Pageviews and outbound clicks per visitor country. Label shows CTR.';

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return ['indexAxis' => 'y'];
    }

    /**
     * @return array{datasets: array<int, array<string, mixed>>, labels: array<int, string>}
     */
    protected function getData(): array
    {
        $rows = self::breakdown($this->pageFilters ?? [], Auth::user()?->administeredSiteIds());

        return [
            'datasets' => [
                [
                    'label' => 'Pageviews',
                    'data' => array_column($rows, 'pageviews'),
                    'backgroundColor' => '#e85d22',
                ],
                [
                    'label' => 'Outbound clicks',
                    'data' => array_column($rows, 'clicks'),
                    'backgroundColor' => '#3b82f6',
                ],
            ],
            'labels' => array_map(
                fn (array $row): string => $row['label'].($row['ctr'] !== null ? " · {$row['ctr']}%" : ''),
                $rows,
            ),
        ];
    }

    /**
     * Pageviews, clicks and CTR per country, busiest first, with anything
     * past the top TOP_COUNTRIES folded into one "Other" row.
     *
     * @param  array<string, mixed>  $filters  the page's filters (site_id, device, country, from, until)
     * @param  array<int, int>|null  $siteIds  sites the viewer may see; null for an admin
     * @return array<int, array{country: ?string, label: string, pageviews: int, clicks: int, ctr: ?float}>
     */
    public static function breakdown(array $filters, ?array $siteIds): array
    {
        $scope = fn (Builder $query) => $query
            ->when($filters['site_id'] ?? null, fn ($q, $siteId) => $q->where('site_id', $siteId))
            ->when($siteIds !== null, fn ($q) => $q->whereIn('site_id', $siteIds ?? []))
            ->when($filters['device'] ?? null, fn ($q, $device) => $q->where('device', $device))
            ->when($filters['country'] ?? null, fn ($q, $country) => $q->where('country', $country))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['until'] ?? null, fn ($q, $until) => $q->whereDate('created_at', '<=', $until));

        $views = PageViewEvent::query()->tap($scope)
            ->selectRaw('country, count(*) as aggregate')->groupBy('country')->pluck('aggregate', 'country');
        $clicks = CamClickEvent::query()->tap($scope)
            ->selectRaw('country, count(*) as aggregate')->groupBy('country')->pluck('aggregate', 'country');

        $rows = $views->keys()->merge($clicks->keys())->unique()
            ->map(fn (?string $country): array => [
                'country' => $country ?: null,
                'pageviews' => (int) ($views[$country] ?? 0),
                'clicks' => (int) ($clicks[$country] ?? 0),
            ])
            ->sortByDesc('pageviews')
            ->values();

        $top = $rows->take(self::TOP_COUNTRIES);
        $rest = $rows->slice(self::TOP_COUNTRIES);

        if ($rest->isNotEmpty()) {
            $top->push([
                'country' => 'other',
                'pageviews' => $rest->sum('pageviews'),
                'clicks' => $rest->sum('clicks'),
            ]);
        }

        return $top->map(fn (array $row): array => [
            ...$row,
            'label' => $row['country'] === 'other' ? 'Other' : CountryDetector::name($row['country']),
            'ctr' => $row['pageviews'] > 0 ? round(100 * $row['clicks'] / $row['pageviews'], 2) : null,
        ])->all();
    }
}
