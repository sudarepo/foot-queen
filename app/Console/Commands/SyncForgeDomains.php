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
    {--certificates-only : Request SSL certificates only, without adding domains}
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

        $certificatesOnly = (bool) $this->option('certificates-only');
        $requestCertificates = $certificatesOnly || ! (bool) $this->option('skip-certificates');
        $dryRun = (bool) $this->option('dry-run');
        $requestDomains = ! $certificatesOnly;

        try {
            $result = $provisioner->sync(
                $sites,
                requestCertificates: $requestCertificates,
                dryRun: $dryRun,
                requestDomains: $requestDomains,
            );
        } catch (\Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($result['operations'] === []) {
            $this->info($dryRun ? 'Dry run: no changes would be made.' : 'Forge is already up to date.');

            return self::SUCCESS;
        }

        foreach ($result['operations'] as $operation) {
            $this->line("- {$operation['domain']}: ".$this->describe($operation, $dryRun));
        }

        $this->newLine();
        $this->info(sprintf(
            $dryRun
                ? 'Dry run: %d domain adds, %d certificate requests would be made.'
                : 'Done: %d domain adds, %d certificate requests, %d failures.',
            $result['added'],
            $result['requested_certificates'],
            $result['failures'],
        ));

        if ($result['failures'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{domain:string, action:string, status:string, error:?string}  $operation
     */
    private function describe(array $operation, bool $dryRun): string
    {
        $isDomain = $operation['action'] === ForgeDomainProvisioner::ACTION_ADD_DOMAIN;

        if ($operation['status'] === ForgeDomainProvisioner::STATUS_FAILED) {
            return ($isDomain ? 'failed to add domain' : 'failed to request certificate')." ({$operation['error']})";
        }

        if ($dryRun) {
            return $isDomain ? 'would add domain in Forge' : "would request Let's Encrypt certificate";
        }

        return $isDomain ? 'domain added in Forge' : 'certificate request submitted';
    }
}
