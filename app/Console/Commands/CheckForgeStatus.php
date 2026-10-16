<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Forge\ForgeDomainProvisioner;
use App\Services\Forge\ForgeDomainStatus;
use App\Services\Forge\ForgeSslStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

#[Signature('sites:check-forge-status')]
#[Description('Re-read every site\'s domain and SSL status from Forge and store it on the site')]
class CheckForgeStatus extends Command
{
    /**
     * Read-only against Forge: picks up anything changed there directly (a
     * domain removed, a certificate renewed or expired) that the app's own
     * provisioning didn't record. Scheduled weekly.
     */
    public function handle(ForgeDomainProvisioner $provisioner): int
    {
        if (! $provisioner->isConfigured()) {
            $this->error('Forge is not configured. Set FORGE_API_TOKEN, FORGE_SERVER_ID and FORGE_SITE_ID.');

            return self::FAILURE;
        }

        $sites = Site::query()->get();

        try {
            $provisioner->recordStatuses($sites, fresh: true, errors: $sites->pluck('forge_error', 'id')->filter()->all());
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $sites = Site::query()->get();

        $this->info('Domains: '.$this->counts($sites->pluck('forge_domain_status')->map(fn (ForgeDomainStatus $status): string => $status->label())));
        $this->info('SSL: '.$this->counts($sites->pluck('forge_ssl_status')->map(fn (ForgeSslStatus $status): string => $status->label())));

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, string>  $labels
     */
    private function counts(Collection $labels): string
    {
        return $labels->countBy()->map(fn (int $count, string $label): string => "{$count} {$label}")->implode(', ');
    }
}
