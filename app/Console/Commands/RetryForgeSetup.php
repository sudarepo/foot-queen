<?php

namespace App\Console\Commands;

use App\Jobs\AddSiteToForge;
use App\Jobs\RequestSiteSsl;
use App\Models\Site;
use App\Services\Forge\ForgeDomainProvisioner;
use App\Services\Forge\ForgeDomainStatus;
use App\Services\Forge\ForgeSslStatus;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('sites:retry-forge-setup')]
#[Description('Retry Forge setup for sites whose domain or SSL is stuck in progress or failed')]
class RetryForgeSetup extends Command
{
    /**
     * Sites retried per run. Every certificate Forge installs or fails
     * reloads nginx and PHP-FPM, so a backlog is worked off a few at a time
     * rather than in one burst that takes every site down.
     */
    public const BATCH_SIZE = 10;

    /**
     * Leave a site alone this long after its status was last read, so an
     * attempt that's still running — or just failed — isn't retried on top
     * of itself.
     */
    public const RETRY_AFTER_MINUTES = 50;

    /**
     * A refused certificate is retried far less often than a stalled one. It
     * is nearly always DNS that doesn't point here, which an hourly retry
     * won't fix — and every attempt still reloads nginx on the whole server.
     */
    public const RETRY_FAILED_SSL_AFTER_HOURS = 24;

    /**
     * Scheduled hourly, so a site can be added and forgotten: a Forge API
     * error, a certificate Let's Encrypt refused, or a status left
     * "In progress" when its follow-up checks ran out all get another go
     * until the site is set up. Each retry goes through the same jobs as the
     * edit page's "Add to Forge" / "Retry SSL".
     */
    public function handle(ForgeDomainProvisioner $provisioner): int
    {
        if (! $provisioner->isConfigured()) {
            $this->error('Forge is not configured. Set FORGE_API_TOKEN, FORGE_SERVER_ID and FORGE_SITE_ID.');

            return self::FAILURE;
        }

        $sites = Site::query()
            ->whereNotNull('domains')
            ->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $stuck): Builder => $stuck
                    ->where(fn (Builder $status): Builder => $status
                        ->whereIn('forge_domain_status', [ForgeDomainStatus::Failed, ForgeDomainStatus::InProgress])
                        ->orWhereIn('forge_ssl_status', [ForgeSslStatus::InProgress, ForgeSslStatus::Queued]))
                    ->where(fn (Builder $checked): Builder => $this->checkedBefore($checked, now()->subMinutes(self::RETRY_AFTER_MINUTES))))
                ->orWhere(fn (Builder $refused): Builder => $refused
                    ->where('forge_ssl_status', ForgeSslStatus::Failed)
                    ->where(fn (Builder $checked): Builder => $this->checkedBefore($checked, now()->subHours(self::RETRY_FAILED_SSL_AFTER_HOURS)))))
            ->orderByRaw('forge_checked_at is not null, forge_checked_at')
            ->limit(self::BATCH_SIZE)
            ->get();

        foreach ($sites as $site) {
            if ($site->forge_domain_status === ForgeDomainStatus::Provisioned) {
                RequestSiteSsl::queueFor($site);
                $this->line("- {$site->slug}: SSL retry queued");
            } else {
                AddSiteToForge::queueFor($site);
                $this->line("- {$site->slug}: Forge setup retry queued");
            }
        }

        $this->info("Queued {$sites->count()} retr".($sites->count() === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }

    private function checkedBefore(Builder $query, CarbonInterface $cutoff): Builder
    {
        return $query->whereNull('forge_checked_at')->orWhere('forge_checked_at', '<', $cutoff);
    }
}
