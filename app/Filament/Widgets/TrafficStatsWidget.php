<?php

namespace App\Filament\Widgets;

use App\Models\CamClickEvent;
use App\Models\PageViewEvent;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class TrafficStatsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    // Cheap aggregate counts on a low-traffic admin page — not worth the
    // lazy-load pop-in delay.
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $siteId = $this->pageFilters['site_id'] ?? null;
        $siteIds = Auth::user()?->administeredSiteIds();
        $device = $this->pageFilters['device'] ?? null;
        $from = $this->pageFilters['from'] ?? null;
        $until = $this->pageFilters['until'] ?? null;

        $scope = function (Builder $query) use ($siteId, $siteIds, $device, $from, $until) {
            $query
                ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
                ->when($siteIds !== null, fn ($q) => $q->whereIn('site_id', $siteIds ?? []))
                ->when($device, fn ($q) => $q->where('device', $device))
                ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
                ->when($until, fn ($q) => $q->whereDate('created_at', '<=', $until));
        };

        $views = PageViewEvent::query()->tap($scope);
        $totalViews = (clone $views)->count();
        $mobileViews = (clone $views)->where('device', 'mobile')->count();
        $desktopViews = (clone $views)->where('device', 'desktop')->count();

        $clicks = CamClickEvent::query()->tap($scope);
        $totalClicks = (clone $clicks)->count();

        $ctr = $totalViews > 0 ? round(100 * $totalClicks / $totalViews, 2) : null;

        return [
            Stat::make('Pageviews', number_format($totalViews))
                ->description(number_format($totalViews).' page loads in range'),
            Stat::make('Outbound clicks', number_format($totalClicks))
                ->description('Clicks through to a Chaturbate room'),
            Stat::make('CTR', $ctr !== null ? "{$ctr}%" : '—')
                ->description('Clicks ÷ views'),
            Stat::make('Mobile vs. desktop', $totalViews > 0
                ? number_format($mobileViews).' / '.number_format($desktopViews)
                : '— / —')
                ->description('Pageviews by device'),
        ];
    }
}
