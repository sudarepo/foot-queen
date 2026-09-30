<?php

namespace App\Services\Forge;

use App\Models\Site;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ForgeDomainProvisioner
{
    public function isConfigured(): bool
    {
        return filled($this->token())
            && filled($this->serverId())
            && filled($this->siteId());
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return array{added:int, skipped_existing_domain:int, requested_certificates:int, skipped_existing_certificate:int, failures:int, operations:array<int, string>}
     */
    public function sync(Collection $sites, bool $requestCertificates, bool $dryRun, bool $requestDomains = true): array
    {
        $existingDomains = $requestDomains ? $this->fetchExistingDomains() : collect();
        $existingCertificates = $requestCertificates ? $this->fetchExistingCertificates() : collect();

        $summary = [
            'added' => 0,
            'skipped_existing_domain' => 0,
            'requested_certificates' => 0,
            'skipped_existing_certificate' => 0,
            'failures' => 0,
            'operations' => [],
        ];

        /** @var Site $site */
        foreach ($sites as $site) {
            $domains = collect($site->domains ?? [])
                ->filter(fn (mixed $domain): bool => is_string($domain) && filled($domain))
                ->map(fn (string $domain): string => Str::lower(trim($domain)))
                ->unique()
                ->values();

            foreach ($domains as $domain) {
                if ($requestDomains) {
                    if ($existingDomains->contains($domain)) {
                        $summary['skipped_existing_domain']++;
                        $summary['operations'][] = "{$domain}: already present in Forge";
                    } else {
                        if ($dryRun) {
                            $summary['added']++;
                            $summary['operations'][] = "{$domain}: would add domain in Forge";
                        } else {
                            $response = $this->forge()->post($this->domainsEndpoint(), [
                                'domain' => $domain,
                            ]);

                            if ($response->failed()) {
                                $summary['failures']++;
                                $summary['operations'][] = "{$domain}: failed to add domain (".$this->responseMessage($response).')';

                                continue;
                            }

                            $summary['added']++;
                            $summary['operations'][] = "{$domain}: domain added in Forge";
                            $existingDomains->push($domain);
                        }
                    }
                }

                if (! $requestCertificates) {
                    continue;
                }

                if ($existingCertificates->contains($domain)) {
                    $summary['skipped_existing_certificate']++;
                    $summary['operations'][] = "{$domain}: certificate already present in Forge";

                    continue;
                }

                if ($dryRun) {
                    $summary['requested_certificates']++;
                    $summary['operations'][] = "{$domain}: would request Let's Encrypt certificate";

                    continue;
                }

                $response = $this->forge()->post($this->certificatesEndpoint(), [
                    'domains' => [$domain],
                ]);

                if ($response->failed()) {
                    $summary['failures']++;
                    $summary['operations'][] = "{$domain}: failed to request certificate (".$this->responseMessage($response).')';

                    continue;
                }

                $summary['requested_certificates']++;
                $summary['operations'][] = "{$domain}: certificate request submitted";
                $existingCertificates->push($domain);
            }
        }

        return $summary;
    }

    /**
     * @return Collection<int, string>
     */
    public function existingDomains(): Collection
    {
        return $this->fetchExistingDomains();
    }

    /**
     * @return Collection<int, string>
     */
    public function existingCertificates(): Collection
    {
        return $this->fetchExistingCertificates();
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return array{requested_certificates:int, skipped_existing_certificate:int, failures:int, operations:array<int, string>}
     */
    public function requestCertificates(Collection $sites, bool $dryRun): array
    {
        return $this->sync($sites, requestCertificates: true, dryRun: $dryRun, requestDomains: false);
    }

    /**
     * @return Collection<int, string>
     */
    private function fetchExistingDomains(): Collection
    {
        $response = $this->forge()->get($this->domainsEndpoint());

        if ($response->failed()) {
            throw new \RuntimeException('Unable to read existing Forge domains: '.$this->responseMessage($response));
        }

        $rows = $response->json('domains', []);

        return collect(is_array($rows) ? $rows : [])
            ->map(function (mixed $domain): ?string {
                if (is_string($domain)) {
                    return Str::lower(trim($domain));
                }

                if (! is_array($domain)) {
                    return null;
                }

                $value = $domain['name'] ?? $domain['domain'] ?? null;

                return is_string($value) && filled($value)
                    ? Str::lower(trim($value))
                    : null;
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, string>
     */
    private function fetchExistingCertificates(): Collection
    {
        $response = $this->forge()->get($this->allCertificatesEndpoint());

        if ($response->failed()) {
            throw new \RuntimeException('Unable to read existing Forge certificates: '.$this->responseMessage($response));
        }

        $rows = $response->json('certificates', []);

        return collect(is_array($rows) ? $rows : [])
            ->map(function (mixed $certificate): ?string {
                if (! is_array($certificate)) {
                    return null;
                }

                $value = $certificate['domain'] ?? $certificate['name'] ?? null;

                return is_string($value) && filled($value)
                    ? Str::lower(trim($value))
                    : null;
            })
            ->filter()
            ->values();
    }

    private function forge(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.forge.base_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->withToken($this->token())
            ->timeout(30)
            ->retry(2, 200);
    }

    private function domainsEndpoint(): string
    {
        return sprintf('/servers/%s/sites/%s/domains', $this->serverId(), $this->siteId());
    }

    private function allCertificatesEndpoint(): string
    {
        return sprintf('/servers/%s/sites/%s/certificates', $this->serverId(), $this->siteId());
    }

    private function certificatesEndpoint(): string
    {
        return sprintf('/servers/%s/sites/%s/certificates/letsencrypt', $this->serverId(), $this->siteId());
    }

    private function token(): string
    {
        return (string) config('services.forge.token');
    }

    private function serverId(): string
    {
        return (string) config('services.forge.server_id');
    }

    private function siteId(): string
    {
        return (string) config('services.forge.site_id');
    }

    private function responseMessage(Response $response): string
    {
        $message = $response->json('message')
            ?? $response->json('error')
            ?? $response->body();

        $message = is_string($message) ? trim($message) : '';

        if ($message === '') {
            return "HTTP {$response->status()}";
        }

        return "HTTP {$response->status()} - {$message}";
    }
}
