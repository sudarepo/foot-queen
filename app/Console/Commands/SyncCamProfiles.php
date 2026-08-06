<?php

namespace App\Console\Commands;

use App\Services\CamProfileService;
use Illuminate\Console\Command;

class SyncCamProfiles extends Command
{
    protected $signature = 'cams:sync-profiles {--limit=40 : How many performers to fetch this run}';

    protected $description = 'Backfill performer bios and photo sets for online cams';

    /**
     * Unlike `cams:sync`, this costs one rate-limited HTTP request per
     * performer, so it works through the backlog in paced batches on a
     * schedule instead of trying to cover everyone at once. Never-fetched
     * performers are always taken first, so a page that's actually reachable
     * from the listings fills in quickly.
     *
     * The default limit is sized to the schedule: 40 performers at ~3s apart
     * is about two minutes of a fifteen-minute window, which works through a
     * ~500-performer roster in a few hours against a seven-day freshness TTL.
     * See CamProfileService::refreshStale().
     */
    public function handle(CamProfileService $profiles): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $this->info("Refreshing up to {$limit} cam profiles...");

        $result = $profiles->refreshStale($limit);

        $this->line("  attempted: {$result['attempted']}");
        $this->line("  updated:   {$result['updated']}");

        if ($result['throttled']) {
            $this->warn('  Stopped early — Chaturbate rate limited us. The rest are first in line next run.');
        }

        return self::SUCCESS;
    }
}
