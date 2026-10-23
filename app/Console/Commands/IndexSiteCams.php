<?php

namespace App\Console\Commands;

use App\Services\SiteCamIndex;
use Illuminate\Console\Command;

class IndexSiteCams extends Command
{
    protected $signature = 'cams:index-sites';

    protected $description = 'Rebuild the precomputed site/cam tag matches (cam_site) from scratch';

    public function handle(SiteCamIndex $index): int
    {
        $this->info('Rebuilding cam_site...');
        $index->rebuild();
        $this->info('Done.');

        return self::SUCCESS;
    }
}
