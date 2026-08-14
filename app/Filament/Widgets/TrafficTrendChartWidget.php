<?php

namespace App\Filament\Widgets;

use App\Models\PageViewEvent;
use Carbon\CarbonPeriod;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Facades\Auth;

class TrafficTrendChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Pageviews over time';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $siteId = $this->pageFilters['site_id'] ?? null;
        $siteIds = Auth::user()?->administeredSiteIds();
        $device = $this->pageFilters['device'] ?? null;
        $from = $this->pageFilters['from'] ?? now()->subDays(30)->toDateString();
        $until = $this->pageFilters['until'] ?? now()->toDateString();

        $counts = PageViewEvent::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->when($siteIds !== null, fn ($q) => $q->whereIn('site_id', $siteIds ?? []))
            ->when($device, fn ($q) => $q->where('device', $device))
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $until)
            ->selectRaw('DATE(created_at) as day, count(*) as aggregate')
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('aggregate', 'day');

        $period = CarbonPeriod::create($from, $until);
        $labels = [];
        $data = [];

        foreach ($period as $date) {
            $key = $date->toDateString();
            $labels[] = $date->format('M j');
            $data[] = (int) ($counts[$key] ?? 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Pageviews',
                    'data' => $data,
                    'borderColor' => '#e85d22',
                    'backgroundColor' => 'rgba(232, 93, 34, 0.1)',
                    'fill' => true,
                ],
            ],
            'labels' => $labels,
        ];
    }
}
