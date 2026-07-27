<?php

namespace App\Filament\Widgets;

use App\Models\CamClickEvent;
use App\Models\PageViewEvent;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ConversionStatsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    // Filament widgets lazy-load by default (a follow-up AJAX round-trip
    // after the initial page load). These queries are cheap aggregate
    // counts on a low-traffic admin page — not worth the pop-in delay.
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $from = $this->pageFilters['from'] ?? null;
        $until = $this->pageFilters['until'] ?? null;

        $views = PageViewEvent::query()
            ->when($from, fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($until, fn ($query) => $query->whereDate('created_at', '<=', $until))
            ->selectRaw('page, count(*) as aggregate')
            ->groupBy('page')
            ->pluck('aggregate', 'page');

        $clicks = CamClickEvent::query()
            ->when($from, fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($until, fn ($query) => $query->whereDate('created_at', '<=', $until))
            ->selectRaw('source_page, count(*) as aggregate')
            ->groupBy('source_page')
            ->pluck('aggregate', 'source_page');

        $variants = [
            'grid' => 'Grid (/)',
            'feed' => 'Feed (/feed)',
        ];

        $ctrByVariant = [];
        foreach (array_keys($variants) as $key) {
            $viewCount = (int) ($views[$key] ?? 0);
            $clickCount = (int) ($clicks[$key] ?? 0);
            $ctrByVariant[$key] = $viewCount > 0 ? round(100 * $clickCount / $viewCount, 2) : null;
        }

        $leader = collect($ctrByVariant)->filter(fn ($ctr) => $ctr !== null)->sort()->keys()->last();

        return collect($variants)->map(function (string $label, string $key) use ($views, $clicks, $ctrByVariant, $leader) {
            $viewCount = (int) ($views[$key] ?? 0);
            $clickCount = (int) ($clicks[$key] ?? 0);
            $ctr = $ctrByVariant[$key];

            return Stat::make($label, $ctr !== null ? "{$ctr}%" : '—')
                ->description(
                    $viewCount > 0
                        ? number_format($clickCount).' clicks / '.number_format($viewCount).' views'
                        : 'No views logged yet'
                )
                ->descriptionIcon($key === $leader ? Heroicon::OutlinedArrowTrendingUp : null)
                ->color($key === $leader ? 'success' : 'gray');
        })->values()->all();
    }
}
