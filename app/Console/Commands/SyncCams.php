<?php

namespace App\Console\Commands;

use App\Services\CamSyncService;
use Illuminate\Console\Command;

class SyncCams extends Command
{
    protected $signature = 'cams:sync';
    protected $description = 'Fetch and sync live cams from all enabled providers';

    public function handle(CamSyncService $sync): int
    {
        $this->info('Starting cam sync...');
        $results = $sync->syncAll();

        foreach ($results as $provider => $count) {
            $this->line("  {$provider}: {$count} cams");
        }

        $this->info('Done.');
        return self::SUCCESS;
    }
}
