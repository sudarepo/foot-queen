<?php

namespace App\Filament\Widgets;

use App\Models\ChaturbateStatsDay;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ChaturbateStatsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    /**
     * Deliberately ignores $this->pageFilters['site_id'] / ['device'] —
     * Chaturbate's affiliate Stats API can't be broken down by site or
     * tracking sub-id, so this is always the whole account regardless of
     * what's picked in the page's Site filter. See StatsDashboard's
     * "What these numbers mean" section for the caveat shown to admins.
     */
    protected function getStats(): array
    {
        $from = $this->pageFilters['from'] ?? null;
        $until = $this->pageFilters['until'] ?? null;

        $rows = ChaturbateStatsDay::query()
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($until, fn ($q) => $q->whereDate('date', '<=', $until))
            ->get();

        // Settlement rows (payout requests, adjustments, cashed-out tokens)
        // are money moving out, not money earned — excluded from the
        // revenue total so a withdrawal doesn't silently deflate it.
        $revenueRows = $rows->where('is_ledger', false);

        $payout = $revenueRows->sum('payout');
        $rawHits = $revenueRows->sum(fn (ChaturbateStatsDay $row) => (int) ($row->data['Raw Hits'] ?? 0));
        $engagedHits = $revenueRows->sum(fn (ChaturbateStatsDay $row) => (int) ($row->data['Engaged Hits'] ?? 0));
        $freeRegistrations = $revenueRows->sum(fn (ChaturbateStatsDay $row) => (int) ($row->data['Free Registrations'] ?? 0));

        return [
            Stat::make('Chaturbate revenue', '$'.number_format((float) $payout, 2))
                ->description('Across every site — settlement rows excluded'),
            Stat::make('Raw hits', number_format($rawHits))
                ->description('Outbound clicks Chaturbate recorded'),
            Stat::make('Engaged hits', number_format($engagedHits))
                ->description('Visits Chaturbate counted as engaged'),
            Stat::make('Free registrations', number_format($freeRegistrations))
                ->description('Signups attributed to our traffic'),
        ];
    }
}
