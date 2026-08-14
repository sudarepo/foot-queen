<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChaturbateAffiliateStatsClient
{
    private const ENDPOINT = 'https://chaturbate.com/affiliates/apistats/';

    /**
     * Settlement/ledger rows aren't earned revenue (one, "Daily Payout
     * Request", reports a real negative payout — a withdrawal). Flagged at
     * fetch time so a "total revenue" query never has to string-match
     * program names to exclude them.
     */
    private const LEDGER_PROGRAMS = [
        'Daily Payout Request',
        'Payout Adjustment',
        'Returned Payout',
        'Cashed-Out Tokens',
    ];

    /**
     * Fetch and flatten every program's daily rows from Chaturbate's
     * affiliate Stats API.
     *
     * The API has no confirmed date-range or per-track breakdown (both
     * `group_by` and `track` query params are silently ignored) — it always
     * returns everything it has, account-wide. So there's nothing to page
     * or filter here; the caller re-syncs the full response each run.
     *
     * @return array<int, array{date: string, program: string, payout: ?float, is_ledger: bool, data: array<string, mixed>}>
     */
    public function fetchStats(): array
    {
        $username = config('chaturbate-stats.username');
        $token = config('chaturbate-stats.token');

        if (empty($username) || empty($token)) {
            Log::warning('Chaturbate stats credentials are not set; skipping fetch.');

            return [];
        }

        $response = Http::timeout(30)->get(self::ENDPOINT, [
            'username' => $username,
            'token' => $token,
        ]);

        if ($response->failed()) {
            Log::error('Chaturbate stats API failed', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);

            return [];
        }

        $programs = $response->json('stats') ?? [];
        $rows = [];

        foreach ($programs as $program) {
            foreach ($this->flattenProgram($program) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array<int, array{date: string, program: string, payout: ?float, is_ledger: bool, data: array<string, mixed>}>
     */
    private function flattenProgram(array $program): array
    {
        $name = $program['program'] ?? null;
        $columns = $program['columns'] ?? [];
        $rows = $program['rows'] ?? [];

        if (empty($name) || empty($columns)) {
            return [];
        }

        $payoutIndex = array_search('Payout', $columns, strict: true);
        $isLedger = in_array($name, self::LEDGER_PROGRAMS, strict: true);

        $flattened = [];

        foreach ($rows as $row) {
            $data = array_combine($columns, $row);
            $date = $data['Date'] ?? null;

            if (empty($date)) {
                continue;
            }

            // '' means Chaturbate reported no figure for this cell — a real
            // gap, not a $0.00 day, so it stays null rather than becoming 0.
            $payout = $payoutIndex === false ? null : $row[$payoutIndex];
            $payout = ($payout === '' || $payout === null) ? null : (float) $payout;

            $flattened[] = [
                'date' => $date,
                'program' => $name,
                'payout' => $payout,
                'is_ledger' => $isLedger,
                'data' => $data,
            ];
        }

        return $flattened;
    }
}
