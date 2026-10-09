<?php

namespace App\Services\Forge;

use App\Models\Site;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Talks to the org-scoped Forge API (https://forge.laravel.com/api/orgs/{org}),
 * which returns JSON:API documents and manages certificates per domain record.
 */
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
        $domainIds = $this->fetchDomainRecords();
        $existingCertificates = $requestCertificates ? $this->certificateDomains($domainIds) : collect();

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
                    if ($domainIds->has($domain)) {
                        $summary['skipped_existing_domain']++;

                        if (! $dryRun) {
                            $summary['operations'][] = "{$domain}: already present in Forge";
                        }
                    } elseif ($dryRun) {
                        $summary['added']++;
                        $summary['operations'][] = "{$domain}: would add domain in Forge";
                    } else {
                        $response = $this->forge()->post($this->domainsEndpoint(), [
                            'name' => $domain,
                            'allow_wildcard_subdomains' => false,
                            'www_redirect_type' => 'from-www',
                        ]);

                        if ($response->failed()) {
                            $summary['failures']++;
                            $summary['operations'][] = "{$domain}: failed to add domain (".$this->responseMessage($response).')';

                            continue;
                        }

                        $summary['added']++;
                        $summary['operations'][] = "{$domain}: domain added in Forge";
                        $domainIds->put($domain, (string) $response->json('data.id'));
                    }
                }

                if (! $requestCertificates) {
                    continue;
                }

                if ($existingCertificates->contains($domain)) {
                    $summary['skipped_existing_certificate']++;

                    if (! $dryRun) {
                        $summary['operations'][] = "{$domain}: certificate already present in Forge";
                    }

                    continue;
                }

                if ($dryRun) {
                    $summary['requested_certificates']++;
                    $summary['operations'][] = "{$domain}: would request Let's Encrypt certificate";

                    continue;
                }

                $domainId = $domainIds->get($domain);

                if (blank($domainId)) {
                    $summary['failures']++;
                    $summary['operations'][] = "{$domain}: cannot request certificate, domain is not in Forge";

                    continue;
                }

                $response = $this->forge()->post($this->domainCertificatesEndpoint($domainId), [
                    'type' => 'letsencrypt',
                    'enable' => true,
                    'letsencrypt' => [
                        'verification_method' => 'http-01',
                        'key_type' => 'ecdsa',
                    ],
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
        return $this->fetchDomainRecords()->keys()->values();
    }

    /**
     * Domains that currently have an installed, active certificate.
     *
     * @return Collection<int, string>
     */
    public function existingCertificates(): Collection
    {
        return $this->certificateDomains($this->fetchDomainRecords());
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
     * @return Collection<string, string> domain name => Forge domain record id
     */
    private function fetchDomainRecords(): Collection
    {
        return $this->fetchAll($this->domainsEndpoint(), 'domains')
            ->filter(fn (array $row): bool => is_string($row['attributes']['name'] ?? null) && isset($row['id']))
            ->mapWithKeys(fn (array $row): array => [
                Str::lower(trim($row['attributes']['name'])) => (string) $row['id'],
            ]);
    }

    /**
     * Certificates only reference their domain through the `links.self` URL
     * (…/domains/{id}/certificates/{id}), so they are mapped back to names
     * through the domain records.
     *
     * @param  Collection<string, string>  $domainIds
     * @return Collection<int, string>
     */
    private function certificateDomains(Collection $domainIds): Collection
    {
        $namesById = $domainIds->flip();

        return $this->fetchAll($this->certificatesEndpoint(), 'certificates')
            ->filter(fn (array $row): bool => ($row['attributes']['status'] ?? null) === 'installed'
                && ($row['attributes']['active'] ?? false) === true)
            ->map(function (array $row) use ($namesById): ?string {
                $href = (string) ($row['links']['self']['href'] ?? '');

                if (preg_match('#/domains/([^/]+)/certificates/#', $href, $matches) !== 1) {
                    return null;
                }

                return $namesById->get($matches[1]);
            })
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * Follows Forge's cursor pagination and returns every JSON:API row.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function fetchAll(string $endpoint, string $resource): Collection
    {
        $rows = collect();
        $cursor = null;

        do {
            $query = ['page[size]' => 100];

            if ($cursor !== null) {
                $query['page[cursor]'] = $cursor;
            }

            $response = $this->forge()->get($endpoint, $query);

            if ($response->status() === 404) {
                throw new \RuntimeException("Unable to read existing Forge {$resource}: HTTP 404 - Forge site configuration may be wrong; check FORGE_API_URL, FORGE_SERVER_ID and FORGE_SITE_ID.");
            }

            if ($response->failed()) {
                throw new \RuntimeException("Unable to read existing Forge {$resource}: ".$this->responseMessage($response));
            }

            $data = $response->json('data');

            if (! is_array($data)) {
                throw new \RuntimeException("Unable to read existing Forge {$resource}: unexpected response format - FORGE_API_URL should look like https://forge.laravel.com/api/orgs/{organization}.");
            }

            $rows = $rows->concat(array_filter($data, 'is_array'));
            $cursor = $response->json('meta.next_cursor');
        } while (is_string($cursor) && $cursor !== '');

        return $rows->values();
    }

    private function forge(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.forge.base_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->withToken($this->token())
            ->timeout(30)
            ->retry(2, 200, null, false);
    }

    private function domainsEndpoint(): string
    {
        return sprintf('/servers/%s/sites/%s/domains', $this->serverId(), $this->siteId());
    }

    private function certificatesEndpoint(): string
    {
        return sprintf('/servers/%s/sites/%s/certificates', $this->serverId(), $this->siteId());
    }

    private function domainCertificatesEndpoint(string $domainId): string
    {
        return sprintf('/servers/%s/sites/%s/domains/%s/certificates', $this->serverId(), $this->siteId(), $domainId);
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
