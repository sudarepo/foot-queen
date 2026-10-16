<?php

namespace App\Services\Forge;

use Closure;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Whether a domain is proxied through Cloudflare, judged by where its DNS
 * points.
 *
 * Cloudflare serves HTTPS for those with its own edge certificate, so they
 * work without one from Let's Encrypt — and Let's Encrypt can't issue one
 * here for them anyway. Every request for one fails, and every failed
 * attempt is a wasted request and a reload, so the sync skips them.
 */
class CloudflareDetector
{
    /**
     * Cloudflare's published IPv4 ranges (https://www.cloudflare.com/ips-v4).
     * They change rarely; a new one missing here only means a domain behind
     * it reads as "Failed" until it's added.
     *
     * @var array<int, string>
     */
    public const RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    ];

    /** @var array<string, bool> */
    private array $proxied = [];

    /**
     * @param  (Closure(string): array<int, string>)|null  $resolver  domain => IPv4 addresses; DNS by default
     */
    public function __construct(private ?Closure $resolver = null) {}

    /**
     * True when the domain resolves, and only to Cloudflare addresses. A
     * domain that doesn't resolve, or points anywhere else, is not proxied.
     */
    public function isProxied(string $domain): bool
    {
        return $this->proxied[$domain] ??= $this->check($domain);
    }

    private function check(string $domain): bool
    {
        $addresses = $this->resolver !== null
            ? ($this->resolver)($domain)
            : (gethostbynamel($domain) ?: []);

        return $addresses !== []
            && collect($addresses)->every(fn (string $address): bool => IpUtils::checkIp($address, self::RANGES));
    }
}
