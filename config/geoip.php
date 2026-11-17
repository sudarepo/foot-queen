<?php

/**
 * Visitor country for analytics — see App\Services\CountryDetector.
 *
 * Cloudflare's CF-IPCountry header answers for proxied domains; everything
 * else is looked up in a local MaxMind GeoLite2 Country database, which
 * `php artisan geoip:update` downloads (and the scheduler refreshes weekly).
 * Without a license key the download is skipped and non-Cloudflare traffic
 * is simply recorded with no country.
 */
return [
    'maxmind_account_id' => env('MAXMIND_ACCOUNT_ID'),
    'maxmind_license_key' => env('MAXMIND_LICENSE_KEY'),
    'database_path' => env('GEOIP_DATABASE_PATH', storage_path('app/geoip/GeoLite2-Country.mmdb')),
];
