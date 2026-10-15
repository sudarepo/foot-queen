<?php

namespace App\Services\Forge;

use App\Models\Site;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Talks to the org-scoped Forge API (https://forge.laravel.com/api/orgs/{org}),
 * which returns JSON:API documents and manages certificates per domain record.
 */
class ForgeDomainProvisioner
{
    public const ACTION_ADD_DOMAIN = 'add_domain';

    public const ACTION_REQUEST_CERTIFICATE = 'request_certificate';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    /**
     * Held for the whole of a live sync. Every certificate Forge installs
     * reloads nginx and PHP-FPM, so two syncs racing each other — a double
     * click, the panel and the MCP server at once — double the reloads and
     * take every site on the server down with them.
     */
    private const LOCK_KEY = 'forge-domain-sync';

    /** Long enough for a full sync of every site; released as soon as it ends. */
    private const LOCK_SECONDS = 900;

    /**
     * A failed certificate is usually DNS that doesn't point here yet, which
     * an immediate retry won't fix — it only costs another reload. Bulk syncs
     * leave a fresh failure alone for this long.
     */
    private const FAILED_CERTIFICATE_RETRY_AFTER_MINUTES = 60;

    public function isConfigured(): bool
    {
        return filled($this->token())
            && filled($this->serverId())
            && filled($this->siteId());
    }

    /**
     * Only actions taken (or, in a dry run, actions that would be taken) are
     * reported in `operations`; domains and certificates already in Forge are
     * counted in the `skipped_*` totals but otherwise left out.
     *
     * Safe to run repeatedly: a certificate Forge is still issuing counts as
     * present, a recent failure is left alone unless `$retryRecentFailures`,
     * and a live sync refuses to start while another is running.
     *
     * @param  Collection<int, Site>  $sites
     * @return array{added:int, skipped_existing_domain:int, requested_certificates:int, skipped_existing_certificate:int, skipped_recent_failure:int, failures:int, operations:array<int, array{domain:string, action:string, status:string, error:?string}>}
     *
     * @throws ForgeSyncInProgress
     */
    public function sync(Collection $sites, bool $requestCertificates, bool $dryRun, bool $requestDomains = true, bool $retryRecentFailures = false): array
    {
        if ($dryRun) {
            return $this->runSync($sites, $requestCertificates, $dryRun, $requestDomains, $retryRecentFailures);
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new ForgeSyncInProgress;
        }

        try {
            return $this->runSync($sites, $requestCertificates, $dryRun, $requestDomains, $retryRecentFailures);
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether a live sync is running right now.
     */
    public function isSyncing(): bool
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return true;
        }

        $lock->release();

        return false;
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return array{added:int, skipped_existing_domain:int, requested_certificates:int, skipped_existing_certificate:int, skipped_recent_failure:int, failures:int, operations:array<int, array{domain:string, action:string, status:string, error:?string}>}
     */
    private function runSync(Collection $sites, bool $requestCertificates, bool $dryRun, bool $requestDomains, bool $retryRecentFailures): array
    {
        $domainIds = $this->fetchDomainRecords();
        $certificates = $requestCertificates ? $this->latestCertificates($domainIds) : collect();

        $summary = [
            'added' => 0,
            'skipped_existing_domain' => 0,
            'requested_certificates' => 0,
            'skipped_existing_certificate' => 0,
            'skipped_recent_failure' => 0,
            'failures' => 0,
            'operations' => [],
        ];

        $succeeded = $dryRun ? self::STATUS_PLANNED : self::STATUS_DONE;

        /** @var Site $site */
        foreach ($sites as $site) {
            foreach ($this->siteDomains($site) as $domain) {
                if ($requestDomains) {
                    if ($domainIds->has($domain)) {
                        $summary['skipped_existing_domain']++;
                    } elseif ($dryRun) {
                        $summary['added']++;
                        $summary['operations'][] = $this->operation($domain, self::ACTION_ADD_DOMAIN, $succeeded);
                    } else {
                        $response = $this->forge()->post($this->domainsEndpoint(), [
                            'name' => $domain,
                            'allow_wildcard_subdomains' => false,
                            'www_redirect_type' => 'from-www',
                        ]);

                        if ($response->failed()) {
                            $summary['failures']++;
                            $summary['operations'][] = $this->operation($domain, self::ACTION_ADD_DOMAIN, self::STATUS_FAILED, $this->responseMessage($response));

                            continue;
                        }

                        $summary['added']++;
                        $summary['operations'][] = $this->operation($domain, self::ACTION_ADD_DOMAIN, $succeeded);
                        $domainIds->put($domain, (string) $response->json('data.id'));
                    }
                }

                if (! $requestCertificates) {
                    continue;
                }

                $certificate = $certificates->get($domain);

                if ($certificate !== null && $certificate['status'] !== self::STATUS_FAILED) {
                    $summary['skipped_existing_certificate']++;

                    continue;
                }

                if ($certificate !== null && ! $retryRecentFailures && $this->failedRecently($certificate)) {
                    $summary['skipped_recent_failure']++;

                    continue;
                }

                if ($dryRun) {
                    $summary['requested_certificates']++;
                    $summary['operations'][] = $this->operation($domain, self::ACTION_REQUEST_CERTIFICATE, $succeeded);

                    continue;
                }

                $domainId = $domainIds->get($domain);

                if (blank($domainId)) {
                    $summary['failures']++;
                    $summary['operations'][] = $this->operation($domain, self::ACTION_REQUEST_CERTIFICATE, self::STATUS_FAILED, 'domain is not in Forge');

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
                    $summary['operations'][] = $this->operation($domain, self::ACTION_REQUEST_CERTIFICATE, self::STATUS_FAILED, $this->responseMessage($response));

                    continue;
                }

                $summary['requested_certificates']++;
                $summary['operations'][] = $this->operation($domain, self::ACTION_REQUEST_CERTIFICATE, $succeeded);
                $certificates->put($domain, ['status' => 'requested', 'active' => false, 'updated_at' => now()]);
            }
        }

        return $summary;
    }

    /**
     * Which of the site's domains are not yet in Forge, and which domains are
     * in Forge but have no installed certificate.
     *
     * @return array{missing_domains:array<int, string>, missing_certificates:array<int, string>}
     */
    public function siteStatus(Site $site): array
    {
        $domainIds = $this->fetchDomainRecords();
        $certificates = $this->certificateDomains($domainIds);
        $domains = $this->siteDomains($site);

        return [
            'missing_domains' => $domains->reject(fn (string $domain): bool => $domainIds->has($domain))->values()->all(),
            'missing_certificates' => $domains
                ->filter(fn (string $domain): bool => $domainIds->has($domain) && ! $certificates->contains($domain))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return Collection<int, string>
     */
    private function siteDomains(Site $site): Collection
    {
        return collect($site->domains ?? [])
            ->filter(fn (mixed $domain): bool => is_string($domain) && filled($domain))
            ->map(fn (string $domain): string => Str::lower(trim($domain)))
            ->unique()
            ->values();
    }

    /**
     * @return array{domain:string, action:string, status:string, error:?string}
     */
    private function operation(string $domain, string $action, string $status, ?string $error = null): array
    {
        return [
            'domain' => $domain,
            'action' => $action,
            'status' => $status,
            'error' => $error,
        ];
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
     * @return array{added:int, skipped_existing_domain:int, requested_certificates:int, skipped_existing_certificate:int, skipped_recent_failure:int, failures:int, operations:array<int, array{domain:string, action:string, status:string, error:?string}>}
     */
    public function requestCertificates(Collection $sites, bool $dryRun): array
    {
        return $this->sync($sites, requestCertificates: true, dryRun: $dryRun, requestDomains: false, retryRecentFailures: true);
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
        return $this->latestCertificates($domainIds)
            ->filter(fn (array $certificate): bool => $certificate['status'] === 'installed' && $certificate['active'])
            ->keys()
            ->values();
    }

    /**
     * The most recent certificate on each domain, whatever its state —
     * installed, still being issued, or failed.
     *
     * @param  Collection<string, string>  $domainIds
     * @return Collection<string, array{status:string, active:bool, updated_at:?Carbon}>
     */
    private function latestCertificates(Collection $domainIds): Collection
    {
        $namesById = $domainIds->flip();

        return $this->fetchAll($this->certificatesEndpoint(), 'certificates')
            ->map(function (array $row) use ($namesById): ?array {
                $href = (string) ($row['links']['self']['href'] ?? '');

                if (preg_match('#/domains/([^/]+)/certificates/#', $href, $matches) !== 1) {
                    return null;
                }

                $domain = $namesById->get($matches[1]);

                if ($domain === null) {
                    return null;
                }

                $updatedAt = $row['attributes']['updated_at'] ?? $row['attributes']['created_at'] ?? null;

                return [
                    'domain' => $domain,
                    'status' => (string) ($row['attributes']['status'] ?? ''),
                    'active' => ($row['attributes']['active'] ?? false) === true,
                    'updated_at' => is_string($updatedAt) ? Carbon::parse($updatedAt) : null,
                ];
            })
            ->filter()
            ->groupBy('domain')
            ->map(function (Collection $rows): array {
                // A domain can have several: an old failure next to a newer
                // install. An active one wins; otherwise the latest attempt.
                $certificate = $rows->firstWhere('active', true)
                    ?? $rows->sortByDesc(fn (array $row) => $row['updated_at']?->getTimestamp() ?? 0)->first();

                return Arr::except($certificate, 'domain');
            });
    }

    /**
     * @param  array{status:string, active:bool, updated_at:?Carbon}  $certificate
     */
    private function failedRecently(array $certificate): bool
    {
        return $certificate['updated_at'] !== null
            && $certificate['updated_at']->gt(now()->subMinutes(self::FAILED_CERTIFICATE_RETRY_AFTER_MINUTES));
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
