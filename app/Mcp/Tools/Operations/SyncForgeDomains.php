<?php

namespace App\Mcp\Tools\Operations;

use App\Jobs\SyncForgeDomainsJob;
use App\Mcp\Support\SiteLookup;
use App\Mcp\Tools\AdminTool;
use App\Models\Site;
use App\Services\Forge\ForgeDomainProvisioner;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Throwable;

#[Description('Add site domains to Laravel Forge and/or request Let\'s Encrypt certificates — the same as `php artisan sites:sync-forge-domains`. Defaults to a dry run; call again with dry_run=false to apply — the live sync is queued and runs in the background. DNS must already point at the server for SSL to succeed. Naming one site makes it an explicit retry, like the admin\'s "Retry SSL": it also requests certificates for domains behind Cloudflare and ones that failed in the last hour, which a sync of every site skips.')]
#[IsOpenWorld]
class SyncForgeDomains extends AdminTool
{
    public function handle(Request $request, ForgeDomainProvisioner $forge): Response
    {
        $validated = $request->validate([
            'site' => ['nullable'],
            'request_domains' => ['nullable', 'boolean'],
            'request_certificates' => ['nullable', 'boolean'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        if (! $forge->isConfigured()) {
            return Response::error('Forge is not configured on this server (FORGE_API_TOKEN, FORGE_SERVER_ID, FORGE_SITE_ID).');
        }

        if (filled($validated['site'] ?? null)) {
            $site = SiteLookup::find($validated['site']);

            if ($site === null) {
                return Response::error("No site matches \"{$validated['site']}\".");
            }

            $sites = collect([$site]);
        } else {
            $sites = Site::query()->where('is_active', true)->get();
        }

        $dryRun = $validated['dry_run'] ?? true;
        $isSingleSite = filled($validated['site'] ?? null);

        if (! $dryRun) {
            // Run from this request, the PHP-FPM reloads each new certificate
            // triggers would kill the sync part-way — see SyncForgeDomainsJob.
            if ($forge->isSyncing()) {
                return Response::error('A Forge sync is already running. Wait for it to finish; it is handling these domains already.');
            }

            SyncForgeDomainsJob::dispatch(
                siteIds: $sites->map(fn (Site $site): int => $site->getKey())->values()->all(),
                requestDomains: $validated['request_domains'] ?? true,
                requestCertificates: $validated['request_certificates'] ?? true,
                notifyUserId: $request->user()?->getKey(),
                retryRecentFailures: $isSingleSite,
            );

            return Response::json([
                'queued' => true,
                'sites' => $sites->count(),
                'message' => 'The sync runs in the background within a minute. Its result goes to the admin panel notifications; call get-forge-status afterwards to see what changed.',
            ]);
        }

        try {
            $result = $forge->sync(
                $sites,
                requestCertificates: $validated['request_certificates'] ?? true,
                dryRun: true,
                requestDomains: $validated['request_domains'] ?? true,
                retryRecentFailures: $isSingleSite,
            );
        } catch (Throwable $exception) {
            report($exception);

            return Response::error("Forge sync failed: {$exception->getMessage()}");
        }

        return Response::json(['dry_run' => true, ...$result]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('Site id or slug; omit for every active site.'),
            'request_domains' => $schema->boolean()->default(true)->description('Add missing domains to Forge.'),
            'request_certificates' => $schema->boolean()->default(true)->description('Request SSL for domains without one.'),
            'dry_run' => $schema->boolean()->default(true)->description('Report what would happen without changing Forge.'),
        ];
    }
}
