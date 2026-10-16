<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Forge\ForgeDomainProvisioner;
use App\Services\Forge\ForgeSslStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Checks back on a site whose certificate Forge is still issuing, until it
 * lands or fails, so "In progress" doesn't sit in the admin for a week
 * waiting on the scheduled check.
 */
class CheckSiteForgeStatus implements ShouldQueue
{
    use Queueable;

    public const INTERVAL_MINUTES = 2;

    /** About half an hour of checking; past that the weekly check takes over. */
    public const MAX_CHECKS = 15;

    public int $tries = 1;

    public function __construct(public int $siteId, public int $check = 1) {}

    public function handle(ForgeDomainProvisioner $provisioner): void
    {
        $site = Site::find($this->siteId);

        if ($site === null || ! $provisioner->isConfigured()) {
            return;
        }

        try {
            $provisioner->recordStatuses(collect([$site]), fresh: true, errors: array_filter([$site->getKey() => $site->forge_error]));
        } catch (Throwable $exception) {
            report($exception);
        }

        if ($site->refresh()->forge_ssl_status === ForgeSslStatus::InProgress && $this->check < self::MAX_CHECKS) {
            static::dispatch($this->siteId, $this->check + 1)->delay(now()->addMinutes(self::INTERVAL_MINUTES));
        }
    }
}
