<?php

namespace App\Services;

use App\Services\Forge\CloudflareDetector;
use GeoIp2\Database\Reader;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * Which country a visitor is in, as an ISO 3166-1 alpha-2 code, for
 * analytics.
 *
 * Some domains are proxied through Cloudflare and some point straight at the
 * server, so there are two sources. Cloudflare's own geolocation wins where
 * it exists; the rest are looked up in a local MaxMind GeoLite2 database.
 * Null means "don't know" — no database yet, a private address, a lookup
 * miss — and is stored as such rather than guessed.
 */
class CountryDetector
{
    /**
     * The date events started carrying a country. Earlier rows have none — the
     * dashboards say so next to the country filter.
     */
    public const TRACKING_SINCE = '2026-11-17';

    /**
     * Cloudflare's published IPv6 ranges (https://www.cloudflare.com/ips-v6);
     * the IPv4 ones live on CloudflareDetector.
     *
     * @var array<int, string>
     */
    private const CLOUDFLARE_IPV6_RANGES = [
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /**
     * Codes Cloudflare sends that aren't a country: XX (unknown) and T1
     * (Tor exit node).
     *
     * @var array<int, string>
     */
    private const NOT_A_COUNTRY = ['XX', 'T1'];

    private ?Reader $reader = null;

    private bool $readerOpened = false;

    public function __construct(private ?string $databasePath = null)
    {
        $this->databasePath ??= config('geoip.database_path');
    }

    public function detect(Request $request): ?string
    {
        $fromCloudflare = $this->isFromCloudflare($request);

        if ($fromCloudflare) {
            $code = $this->normalize($request->header('CF-IPCountry'));

            if ($code !== null) {
                return $code;
            }
        }

        /**
         * The app doesn't trust proxies, so behind Cloudflare $request->ip()
         * is Cloudflare's edge, not the visitor. CF-Connecting-IP is only
         * believed when the request really did come from Cloudflare —
         * anyone else could send it.
         */
        $ip = $fromCloudflare
            ? ($request->header('CF-Connecting-IP') ?: $request->ip())
            : $request->ip();

        return $ip !== null ? $this->lookup($ip) : null;
    }

    /**
     * The visitor's country per the local MaxMind database, or null if there
     * isn't one or it has no answer for this address.
     */
    public function lookup(string $ip): ?string
    {
        $reader = $this->reader();

        if ($reader === null) {
            return null;
        }

        try {
            return $this->normalize($reader->country($ip)->country->isoCode);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * CF-IPCountry is a plain request header, so on a domain that isn't
     * behind Cloudflare a visitor could set it to anything. Only requests
     * arriving from Cloudflare's own address ranges are allowed to speak
     * for the country.
     */
    private function isFromCloudflare(Request $request): bool
    {
        $remote = $request->server('REMOTE_ADDR');

        return is_string($remote)
            && IpUtils::checkIp($remote, [...CloudflareDetector::RANGES, ...self::CLOUDFLARE_IPV6_RANGES]);
    }

    private function normalize(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 && ! in_array($code, self::NOT_A_COUNTRY, true)
            ? $code
            : null;
    }

    /**
     * Opened lazily and once per instance: the database is a memory-mapped
     * file, and most Cloudflare requests never need it.
     */
    private function reader(): ?Reader
    {
        if (! $this->readerOpened) {
            $this->readerOpened = true;

            try {
                $this->reader = is_string($this->databasePath) && is_file($this->databasePath)
                    ? new Reader($this->databasePath)
                    : null;
            } catch (Throwable) {
                $this->reader = null;
            }
        }

        return $this->reader;
    }

    /**
     * Display name for a stored code ("US" → "United States"), falling back
     * to the code itself.
     */
    public static function name(?string $code): string
    {
        if ($code === null || $code === '') {
            return 'Unknown';
        }

        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayRegion('-'.$code, 'en');

            if (is_string($name) && $name !== '' && $name !== $code) {
                return $name;
            }
        }

        return $code;
    }

    /**
     * Select options for a set of stored codes, keyed by code and sorted by
     * display name.
     *
     * @param  iterable<int, ?string>  $codes
     * @return array<string, string>
     */
    public static function options(iterable $codes): array
    {
        return collect($codes)
            ->filter()
            ->unique()
            ->mapWithKeys(fn (string $code): array => [$code => self::name($code)])
            ->sort()
            ->all();
    }
}
