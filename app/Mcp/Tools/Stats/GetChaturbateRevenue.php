<?php

namespace App\Mcp\Tools\Stats;

use App\Mcp\Tools\AdminTool;
use App\Models\ChaturbateStatsDay;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Chaturbate affiliate revenue, hits and registrations by day and program (default last 30 days). Network-wide only — Chaturbate\'s stats API cannot be split by site. Settlement/payout rows are listed separately and excluded from revenue.')]
#[IsReadOnly]
class GetChaturbateRevenue extends AdminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'until' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = $validated['from'] ?? now()->subDays(30)->toDateString();
        $until = $validated['until'] ?? now()->toDateString();

        $rows = ChaturbateStatsDay::query()
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $until)
            ->orderBy('date')
            ->get();

        $revenueRows = $rows->where('is_ledger', false);
        $sumOf = fn (string $key) => $revenueRows->sum(fn (ChaturbateStatsDay $row) => (int) ($row->data[$key] ?? 0));

        return Response::json([
            'range' => ['from' => $from, 'until' => $until],
            'totals' => [
                'payout_usd' => round((float) $revenueRows->sum('payout'), 2),
                'raw_hits' => $sumOf('Raw Hits'),
                'engaged_hits' => $sumOf('Engaged Hits'),
                'free_registrations' => $sumOf('Free Registrations'),
            ],
            'by_day' => $revenueRows->groupBy(fn (ChaturbateStatsDay $row) => $row->date->toDateString())
                ->map(fn ($day) => round((float) $day->sum('payout'), 2))
                ->all(),
            'by_program' => $revenueRows->groupBy('program')
                ->map(fn ($program) => round((float) $program->sum('payout'), 2))
                ->all(),
            'settlements' => $rows->where('is_ledger', true)->map(fn (ChaturbateStatsDay $row): array => [
                'date' => $row->date->toDateString(),
                'program' => $row->program,
                'amount_usd' => (float) $row->payout,
            ])->values()->all(),
            'last_synced_at' => ChaturbateStatsDay::query()->max('updated_at'),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Start date YYYY-MM-DD (default 30 days ago).'),
            'until' => $schema->string()->description('End date YYYY-MM-DD, inclusive (default today).'),
        ];
    }
}
