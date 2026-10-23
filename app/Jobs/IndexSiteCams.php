<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\SiteCamIndex;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-match one site against the whole cam pool after its tags change, or it
 * is created or restored (see Site::booted()). Queued because it scans every
 * cam — a few seconds that shouldn't hold up saving the form.
 */
class IndexSiteCams implements ShouldBeUnique, ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function __construct(public int $siteId) {}

    public function uniqueId(): string
    {
        return (string) $this->siteId;
    }

    public function handle(SiteCamIndex $index): void
    {
        $site = Site::withTrashed()->find($this->siteId);

        if ($site !== null) {
            $index->rebuildSite($site);
        }
    }
}
