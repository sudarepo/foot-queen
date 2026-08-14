<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ChaturbateAffiliateStatsSyncService
{
    public function __construct(private ChaturbateAffiliateStatsClient $client) {}

    /**
     * Re-fetch and re-upsert every row the API returns, not just "today" —
     * affiliate networks commonly revise prior days (fraud reversals,
     * recalculated payouts), there's no confirmed date-range param to fetch
     * incrementally, and the volume is trivial either way.
     *
     * Returns the number of day/program rows upserted.
     */
    public function sync(): int
    {
        $rows = $this->client->fetchStats();
        $now = now();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('chaturbate_stats_days')->upsert(
                array_map(fn (array $row) => [
                    'date' => $row['date'],
                    'program' => $row['program'],
                    'payout' => $row['payout'],
                    'is_ledger' => $row['is_ledger'],
                    'data' => json_encode($row['data']),
                    'updated_at' => $now,
                    'created_at' => $now,
                ], $chunk),
                ['date', 'program'],
                ['payout', 'is_ledger', 'data', 'updated_at'],
            );
        }

        return count($rows);
    }
}
