<?php

namespace App\Console\Commands;

use App\Services\ChaturbateAffiliateStatsSyncService;
use Illuminate\Console\Command;

class SyncChaturbateStats extends Command
{
    protected $signature = 'chaturbate:sync-stats';

    protected $description = 'Fetch and store Chaturbate affiliate revenue stats';

    public function handle(ChaturbateAffiliateStatsSyncService $sync): int
    {
        $this->info('Syncing Chaturbate affiliate stats...');
        $count = $sync->sync();

        $this->info("Done. {$count} day/program rows synced.");

        return self::SUCCESS;
    }
}
