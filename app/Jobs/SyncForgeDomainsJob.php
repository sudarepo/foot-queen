<?php

namespace App\Jobs;

use App\Filament\Resources\Sites\ForgeSyncNotification;
use App\Models\Site;
use App\Models\User;
use App\Services\Forge\ForgeDomainProvisioner;
use App\Services\Forge\ForgeSslStatus;
use App\Services\Forge\ForgeSyncInProgress;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A live Forge sync, run by a queue worker instead of a web request.
 *
 * Every certificate Forge installs reloads PHP-FPM, which kills whatever
 * request FPM is serving — including the one running the sync, part-way
 * through its list of domains. A CLI worker isn't an FPM child, so it
 * survives the reloads it causes and finishes the job.
 *
 * Unique while queued, so clicking "Sync" twice queues one sync, not two.
 */
class SyncForgeDomainsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Matches the provisioner's lock: a full sync of every site fits inside it. */
    public int $timeout = 900;

    /** Retrying a half-done sync is just the next sync; don't do it automatically. */
    public int $tries = 1;

    public int $uniqueFor = 900;

    /**
     * @param  array<int, int>|null  $siteIds  null for every site (active only unless $withInactive)
     * @param  bool  $retryRecentFailures  an explicit request for one site: retry a fresh failure and try domains behind Cloudflare
     */
    public function __construct(
        public ?array $siteIds = null,
        public bool $withInactive = false,
        public bool $requestDomains = true,
        public bool $requestCertificates = true,
        public ?int $notifyUserId = null,
        public bool $retryRecentFailures = false,
    ) {}

    public function uniqueId(): string
    {
        return 'forge-domain-sync';
    }

    public function handle(ForgeDomainProvisioner $provisioner): void
    {
        $sites = Site::query()
            ->when($this->siteIds !== null, fn (Builder $query): Builder => $query->whereKey($this->siteIds))
            ->when($this->siteIds === null && ! $this->withInactive, fn (Builder $query): Builder => $query->where('is_active', true))
            ->get();

        try {
            $result = $provisioner->sync(
                $sites,
                requestCertificates: $this->requestCertificates,
                dryRun: false,
                requestDomains: $this->requestDomains,
                retryRecentFailures: $this->retryRecentFailures,
            );
        } catch (ForgeSyncInProgress $exception) {
            $this->notify(Notification::make()->title('Forge sync already running')->body(e($exception->getMessage()))->warning());

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->notify(Notification::make()->title('Forge sync failed')->body(e($exception->getMessage()))->danger());

            return;
        }

        Log::info('Forge sync finished', collect($result)->except('operations')->all());

        $this->recordStatuses($provisioner, $sites, $result);

        $this->notify(ForgeSyncNotification::make($result, dryRun: false));
    }

    /**
     * Store what the sync left behind on each site, and keep checking the
     * ones whose certificates Forge is still issuing.
     *
     * @param  Collection<int, Site>  $sites
     * @param  array{operations: array<int, array{domain: string, status: string, error: ?string}>}  $result
     */
    private function recordStatuses(ForgeDomainProvisioner $provisioner, Collection $sites, array $result): void
    {
        $failures = collect($result['operations'])->where('status', ForgeDomainProvisioner::STATUS_FAILED);

        $errors = $sites->mapWithKeys(function (Site $site) use ($failures): array {
            $failure = $failures->first(fn (array $operation): bool => in_array($operation['domain'], array_map('strtolower', $site->domains ?? []), true));

            return [$site->getKey() => $failure === null ? null : "{$failure['domain']}: {$failure['error']}"];
        })->filter()->all();

        try {
            $provisioner->recordStatuses($sites, fresh: true, errors: $errors);
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        Site::query()
            ->whereKey($sites->modelKeys())
            ->where('forge_ssl_status', ForgeSslStatus::InProgress)
            ->pluck('id')
            ->each(fn (int $siteId) => CheckSiteForgeStatus::dispatch($siteId)->delay(now()->addMinutes(CheckSiteForgeStatus::INTERVAL_MINUTES)));
    }

    private function notify(Notification $notification): void
    {
        $user = $this->notifyUserId !== null ? User::find($this->notifyUserId) : null;

        if ($user !== null) {
            $notification->sendToDatabase($user);
        }
    }
}
