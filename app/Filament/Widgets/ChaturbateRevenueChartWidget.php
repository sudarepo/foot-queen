<?php

namespace App\Filament\Widgets;

use App\Models\ChaturbateStatsDay;
use Carbon\CarbonPeriod;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class ChaturbateRevenueChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Chaturbate payout over time (account-wide)';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $from = $this->pageFilters['from'] ?? now()->subDays(30)->toDateString();
        $until = $this->pageFilters['until'] ?? now()->toDateString();

        // Excludes ledger (settlement) rows for the same reason
        // ChaturbateStatsWidget does — a payout request would otherwise
        // read as a revenue crash on the day it was requested.
        $payoutByDay = ChaturbateStatsDay::query()
            ->where('is_ledger', false)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $until)
            ->get()
            ->groupBy(fn (ChaturbateStatsDay $row) => $row->date->toDateString())
            ->map(fn ($rows) => (float) $rows->sum('payout'));

        $period = CarbonPeriod::create($from, $until);
        $labels = [];
        $data = [];

        foreach ($period as $date) {
            $key = $date->toDateString();
            $labels[] = $date->format('M j');
            $data[] = round($payoutByDay[$key] ?? 0, 2);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Payout ($)',
                    'data' => $data,
                    'borderColor' => '#2e7d32',
                    'backgroundColor' => 'rgba(46, 125, 50, 0.1)',
                    'fill' => true,
                ],
            ],
            'labels' => $labels,
        ];
    }
}
