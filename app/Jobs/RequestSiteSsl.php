<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Forge\ForgeDomainProvisioner;
use App\Services\Forge\ForgeSslStatus;
use App\Services\Forge\ForgeSyncInProgress;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Step two of setting a site up in Forge: request Let's Encrypt certificates
 * for its domains, which must already be in Forge (see AddSiteToForge).
 *
 * Run on the queue, not in a request: each certificate Forge installs reloads
 * PHP-FPM. Forge issues certificates asynchronously, so this hands over to
 * CheckSiteForgeStatus to watch for the result.
 */
class RequestSiteSsl implements ShouldBeUnique, ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    /** Room to wait for a bulk sync holding the Forge lock; real failures don't retry. */
    public int $tries = 10;

    public int $timeout = 600;

    public int $uniqueFor = 600;

    public function __construct(public int $siteId) {}

    public static function queueFor(Site $site): void
    {
        $provisioner = app(ForgeDomainProvisioner::class);

        if (! $provisioner->isConfigured() || blank($site->domains)) {
            return;
        }

        $provisioner->markStatus($site, ssl: ForgeSslStatus::Queued);

        static::dispatch($site->getKey());
    }

    public function uniqueId(): string
    {
        return (string) $this->siteId;
    }

    public function handle(ForgeDomainProvisioner $provisioner): void
    {
        $site = Site::find($this->siteId);

        if ($site === null || ! $provisioner->isConfigured()) {
            return;
        }

        $provisioner->markStatus($site, ssl: ForgeSslStatus::InProgress);

        try {
            // An explicit request for this one site: retry even a failure
            // from a minute ago, unlike a bulk sync.
            $result = $provisioner->requestCertificates(collect([$site]), dryRun: false);
        } catch (ForgeSyncInProgress) {
            $provisioner->markStatus($site, ssl: ForgeSslStatus::Queued);
            $this->release(120);

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->record($provisioner, $site, $exception->getMessage());

            return;
        }

        $failure = collect($result['operations'])->firstWhere('status', ForgeDomainProvisioner::STATUS_FAILED);

        $this->record($provisioner, $site, $failure === null ? null : "{$failure['domain']}: {$failure['error']}");

        if ($site->refresh()->forge_ssl_status === ForgeSslStatus::InProgress) {
            CheckSiteForgeStatus::dispatch($site->getKey())->delay(now()->addMinutes(CheckSiteForgeStatus::INTERVAL_MINUTES));
        }
    }

    private function record(ForgeDomainProvisioner $provisioner, Site $site, ?string $error): void
    {
        try {
            $provisioner->recordStatuses(collect([$site]), fresh: true, errors: $error === null ? [] : [$site->getKey() => $error]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
