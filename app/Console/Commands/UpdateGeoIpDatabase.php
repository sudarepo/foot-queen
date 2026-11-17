<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PharData;
use Throwable;

#[Signature('geoip:update')]
#[Description('Download the latest MaxMind GeoLite2 Country database used to geolocate visitors')]
class UpdateGeoIpDatabase extends Command
{
    private const DOWNLOAD_URL = 'https://download.maxmind.com/geoip/databases/GeoLite2-Country/download?suffix=tar.gz';

    /**
     * MaxMind ships the database inside a dated folder in a tarball. It's
     * unpacked in a scratch directory and moved into place in one rename, so
     * a request never opens a half-written file.
     */
    public function handle(): int
    {
        $accountId = config('geoip.maxmind_account_id');
        $licenseKey = config('geoip.maxmind_license_key');
        $destination = (string) config('geoip.database_path');

        if (blank($accountId) || blank($licenseKey)) {
            $this->warn('MAXMIND_ACCOUNT_ID / MAXMIND_LICENSE_KEY are not set; skipping. Only Cloudflare-proxied traffic will get a country.');

            return self::SUCCESS;
        }

        $workDirectory = storage_path('app/geoip/tmp-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($workDirectory);

        try {
            $archive = $workDirectory.'/GeoLite2-Country.tar.gz';

            Http::withBasicAuth((string) $accountId, (string) $licenseKey)
                ->timeout(120)
                ->sink($archive)
                ->get(self::DOWNLOAD_URL)
                ->throw();

            (new PharData($archive))->extractTo($workDirectory);

            $database = collect(File::allFiles($workDirectory))
                ->first(fn ($file): bool => $file->getFilename() === 'GeoLite2-Country.mmdb');

            if ($database === null) {
                $this->error('The download did not contain GeoLite2-Country.mmdb.');

                return self::FAILURE;
            }

            File::ensureDirectoryExists(dirname($destination));
            File::move($database->getPathname(), $destination);

            $this->info("GeoLite2 Country database updated at {$destination}.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('GeoIP update failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            File::deleteDirectory($workDirectory);
        }
    }
}
