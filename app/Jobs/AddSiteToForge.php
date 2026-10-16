<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Forge\ForgeDomainProvisioner;
use App\Services\Forge\ForgeDomainStatus;
use App\Services\Forge\ForgeSslStatus;
use App\Services\Forge\ForgeSyncInProgress;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Step one of setting a site up in Forge: add its domains.
 *
 * Queued when a site is created or its domains change (see Site::booted()),
 * or from the edit page's "Add to Forge". Only once Forge has accepted every
 * domain is the SSL step queued — a certificate can't be requested for a
 * domain Forge doesn't have.
 */
class AddSiteToForge implements ShouldBeUnique, ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    /** Room to wait for a bulk sync holding the Forge lock; real failures don't retry. */
    public int $tries = 10;

    public int $timeout = 600;

    public int $uniqueFor = 600;

    public function __construct(public int $siteId) {}

    /**
     * Queue a site's domains for Forge, showing it as in progress at once.
     */
    public static function queueFor(Site $site): void
    {
        $provisioner = app(ForgeDomainProvisioner::class);

        if (! $provisioner->isConfigured() || blank($site->domains)) {
            return;
        }

        $provisioner->markStatus($site, ForgeDomainStatus::InProgress, ForgeSslStatus::Queued);

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

        try {
            $result = $provisioner->sync(collect([$site]), requestCertificates: false, dryRun: false, requestDomains: true);
        } catch (ForgeSyncInProgress) {
            $this->release(120);

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->record($provisioner, $site, $exception->getMessage());

            return;
        }

        $failure = collect($result['operations'])->firstWhere('status', ForgeDomainProvisioner::STATUS_FAILED);

        $this->record($provisioner, $site, $failure === null ? null : "{$failure['domain']}: {$failure['error']}");

        $site->refresh();

        if ($site->forge_domain_status === ForgeDomainStatus::Provisioned && $site->forge_ssl_status !== ForgeSslStatus::Installed) {
            RequestSiteSsl::queueFor($site);
        }
    }

    /**
     * Forge being unreadable right after a write shouldn't fail the job —
     * the weekly check will record the status instead.
     */
    private function record(ForgeDomainProvisioner $provisioner, Site $site, ?string $error): void
    {
        try {
            $provisioner->recordStatuses(collect([$site]), fresh: true, errors: $error === null ? [] : [$site->getKey() => $error]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
