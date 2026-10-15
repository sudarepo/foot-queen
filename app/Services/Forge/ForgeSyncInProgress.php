<?php

namespace App\Services\Forge;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * A second Forge sync started while one was still running. Not an error
 * worth logging — the first sync is doing the work — just one to tell the
 * person who clicked.
 */
class ForgeSyncInProgress extends RuntimeException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('Another Forge sync is already running. Wait for it to finish — it is handling these domains already.');
    }
}
