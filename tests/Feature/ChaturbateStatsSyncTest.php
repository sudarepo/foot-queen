<?php

namespace Tests\Feature;

use App\Models\ChaturbateStatsDay;
use App\Services\ChaturbateAffiliateStatsSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChaturbateStatsSyncTest extends TestCase
{
    use RefreshDatabase;

    private function fakeCredentials(): void
    {
        config([
            'chaturbate-stats.username' => 'chaturbate32312',
            'chaturbate-stats.token' => 'test-token',
        ]);
    }

    private function fakeApi(array $stats): void
    {
        $this->fakeCredentials();

        Http::fake([
            'chaturbate.com/affiliates/apistats/*' => Http::response(['stats' => $stats]),
        ]);
    }

    private function revshareProgram(?array $rows = null): array
    {
        return [
            'program' => 'Revshare: 20% of Money Spent + $50 per broadcaster + 5% Referred Affiliate Income',
            'columns' => ['Date', 'Raw Hits', 'Engaged Hits', 'Free Registrations', 'Referred Broadcasters ($50.00)', 'Total Money Spent', 'Payout'],
            'rows' => $rows ?? [
                ['2026-08-01', 5115, 2, 5, 0, 479.89, 95.978],
                ['2026-08-02', 6799, 0, 4, 0, 596.91, 119.382],
            ],
            'totals' => ['Raw Hits' => 11914, 'Payout' => 215.36],
        ];
    }

    private function dailyPayoutRequestProgram(?array $rows = null): array
    {
        return [
            'program' => 'Daily Payout Request',
            'columns' => ['Date', 'Payout'],
            'rows' => $rows ?? [['2026-08-07', -859.94]],
            'totals' => ['Payout' => -859.94],
        ];
    }

    public function test_it_parses_program_rows_into_normalized_stats_days(): void
    {
        $this->fakeApi([$this->revshareProgram()]);

        $count = app(ChaturbateAffiliateStatsSyncService::class)->sync();

        $this->assertSame(2, $count);
        $this->assertDatabaseCount('chaturbate_stats_days', 2);

        $row = ChaturbateStatsDay::query()->where('date', '2026-08-01')->firstOrFail();
        $this->assertSame('95.9780', (string) $row->payout);
        $this->assertSame(5115, $row->data['Raw Hits']);
        $this->assertFalse($row->is_ledger);
    }

    public function test_it_marks_settlement_programs_as_ledger_rows(): void
    {
        $this->fakeApi([
            $this->revshareProgram([['2026-08-01', 5115, 2, 5, 0, 479.89, 95.978]]),
            $this->dailyPayoutRequestProgram(),
        ]);

        app(ChaturbateAffiliateStatsSyncService::class)->sync();

        $revenue = ChaturbateStatsDay::query()->where('program', 'Revshare: 20% of Money Spent + $50 per broadcaster + 5% Referred Affiliate Income')->firstOrFail();
        $ledger = ChaturbateStatsDay::query()->where('program', 'Daily Payout Request')->firstOrFail();

        $this->assertFalse($revenue->is_ledger);
        $this->assertTrue($ledger->is_ledger);
        $this->assertSame('-859.9400', (string) $ledger->payout);
    }

    public function test_it_treats_an_empty_total_as_null_not_zero(): void
    {
        $this->fakeApi([
            [
                'program' => '10 Tokens Per Registration + 500 Per Broadcaster',
                'columns' => ['Date', 'Raw Hits', 'Payout'],
                'rows' => [['2026-08-01', '', '']],
                'totals' => ['Raw Hits' => '', 'Payout' => ''],
            ],
        ]);

        app(ChaturbateAffiliateStatsSyncService::class)->sync();

        $row = ChaturbateStatsDay::query()->firstOrFail();
        $this->assertNull($row->payout);
    }

    public function test_it_skips_the_fetch_when_credentials_are_unset(): void
    {
        config(['chaturbate-stats.username' => null, 'chaturbate-stats.token' => null]);
        Http::fake();

        $count = app(ChaturbateAffiliateStatsSyncService::class)->sync();

        Http::assertNothingSent();
        $this->assertSame(0, $count);
        $this->assertDatabaseCount('chaturbate_stats_days', 0);
    }

    public function test_it_logs_and_continues_when_the_api_request_fails(): void
    {
        $this->fakeCredentials();
        Http::fake([
            'chaturbate.com/affiliates/apistats/*' => Http::response('Server Error', 500),
        ]);

        $count = app(ChaturbateAffiliateStatsSyncService::class)->sync();

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('chaturbate_stats_days', 0);
    }

    public function test_re_running_the_sync_upserts_rather_than_duplicates(): void
    {
        $this->fakeApi([$this->revshareProgram()]);

        $sync = app(ChaturbateAffiliateStatsSyncService::class);
        $sync->sync();
        $sync->sync();

        $this->assertDatabaseCount('chaturbate_stats_days', 2);
    }
}
