<?php

namespace App\Console\Commands;

use App\Services\CamTagIndex;
use Illuminate\Console\Command;

class IndexCamTags extends Command
{
    protected $signature = 'cams:index-tags';

    protected $description = 'Rebuild the indexed cam tag rows (cam_tags) from scratch';

    public function handle(CamTagIndex $index): int
    {
        $this->info('Rebuilding cam_tags...');
        $index->rebuild();
        $this->info('Done.');

        return self::SUCCESS;
    }
}
