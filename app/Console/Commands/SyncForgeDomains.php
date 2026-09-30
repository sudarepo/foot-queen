<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Forge\ForgeDomainProvisioner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('sites:sync-forge-domains
    {--site= : Restrict sync to one site id or slug}
    {--with-inactive : Include inactive sites}
    {--skip-certificates : Add missing domains only, without requesting SSL certs}
    {--dry-run : Report what would happen without creating Forge resources}')]
#[Description('Sync site domains to Laravel Forge and optionally request Let\'s Encrypt certificates')]
class SyncForgeDomains extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ForgeDomainProvisioner $provisioner): int
    {
        if (! $provisioner->isConfigured()) {
            $this->error('Forge is not configured. Set FORGE_API_TOKEN, FORGE_SERVER_ID and FORGE_SITE_ID.');

            return self::FAILURE;
        }

        $query = Site::query();

        if (! (bool) $this->option('with-inactive')) {
            $query->where('is_active', true);
        }

        $siteOption = $this->option('site');

        if (is_string($siteOption) && filled($siteOption)) {
            $query->where(function (Builder $sites) use ($siteOption): void {
                if (ctype_digit($siteOption)) {
                    $sites->orWhereKey((int) $siteOption);
                }

                $sites->orWhere('slug', $siteOption);
            });
        }

        $sites = $query->get();

        if ($sites->isEmpty()) {
            $this->warn('No sites matched the selected filters.');

            return self::SUCCESS;
        }

        $requestCertificates = ! (bool) $this->option('skip-certificates');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = $provisioner->sync($sites, requestCertificates: $requestCertificates, dryRun: $dryRun);
        } catch (\Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($result['operations'] as $operation) {
            $this->line("- {$operation}");
        }

        $this->newLine();
        $this->info(sprintf(
            'Summary: %d domain adds, %d existing domains skipped, %d certificate requests, %d existing certificates skipped, %d failures.',
            $result['added'],
            $result['skipped_existing_domain'],
            $result['requested_certificates'],
            $result['skipped_existing_certificate'],
            $result['failures'],
        ));

        if ($result['failures'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
