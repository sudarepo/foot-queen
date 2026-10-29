<?php

namespace App\Console\Commands;

use App\Services\Forge\ForgeDomainProvisioner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Finder\Finder;
use Throwable;

#[Signature('sites:check-nginx-certificates')]
#[Description('Find nginx configs pointing at certificate files that don\'t exist, and have Forge remove those certificates')]
class CheckNginxCertificates extends Command
{
    /**
     * Leave a config alone this long after it was written, so a certificate
     * Forge is installing right now isn't mistaken for a broken one.
     */
    public const GRACE_SECONDS = 120;

    /**
     * Where Forge keeps a domain's certificate: …/domains/{domain id}/{certificate id}/server.crt.
     */
    private const FORGE_CERTIFICATE_PATH = '#/domains/([^/]+)/([^/]+)/server\.(?:crt|key)$#';

    /**
     * nginx won't start — or restart — while any config names a certificate
     * file that isn't there, and every site on the server shares that nginx.
     * Forge can leave exactly that behind (2026-10-23: a certificate it
     * reported "installed" whose files were never written), and nothing
     * notices until the next restart takes the whole server down.
     *
     * Run every minute on the server itself. Deleting the certificate in
     * Forge makes it rewrite the config without it, which is the fix done by
     * hand that night; the site's SSL then reads as missing and the hourly
     * retry asks for a fresh one.
     */
    public function handle(ForgeDomainProvisioner $provisioner): int
    {
        $missing = $this->missingCertificates();

        if ($missing->isEmpty()) {
            return self::SUCCESS;
        }

        $failed = false;

        foreach ($missing as $certificate) {
            $this->warn("{$certificate['config']} names {$certificate['path']}, which doesn't exist.");

            if ($certificate['domain_id'] === null || ! $provisioner->isConfigured()) {
                Log::error('nginx config names a missing certificate that Forge cannot remove; nginx will not restart until it is fixed by hand.', $certificate);
                $failed = true;

                continue;
            }

            try {
                $domain = $provisioner->deleteCertificate($certificate['domain_id'], $certificate['certificate_id']);
            } catch (Throwable $exception) {
                report($exception);
                $this->error($exception->getMessage());
                $failed = true;

                continue;
            }

            Log::warning("Removed Forge certificate {$certificate['certificate_id']} for {$domain}: nginx config named files that were never written.", $certificate);
            $this->info("Removed certificate {$certificate['certificate_id']} for {$domain}.");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * One entry per missing certificate, however many config lines name it.
     *
     * @return Collection<int, array{config: string, path: string, domain_id: ?string, certificate_id: ?string}>
     */
    private function missingCertificates(): Collection
    {
        return collect(config('services.forge.nginx_config_paths', []))
            ->filter(fn (string $directory): bool => File::isDirectory($directory))
            ->flatMap(fn (string $directory): array => iterator_to_array(
                Finder::create()->files()->in($directory)->ignoreUnreadableDirs(),
                preserve_keys: false,
            ))
            ->filter(fn (\SplFileInfo $file): bool => $file->isReadable() && $file->getMTime() < time() - self::GRACE_SECONDS)
            ->flatMap(function (\SplFileInfo $file): array {
                preg_match_all('/^\s*ssl_certificate(?:_key)?\s+([^;\s]+)\s*;/m', (string) file_get_contents($file->getPathname()), $matches);

                return collect($matches[1])
                    ->reject(fn (string $path): bool => file_exists($path))
                    ->map(function (string $path) use ($file): array {
                        $isForge = preg_match(self::FORGE_CERTIFICATE_PATH, $path, $ids) === 1;

                        return [
                            'config' => $file->getPathname(),
                            'path' => $path,
                            'domain_id' => $isForge ? $ids[1] : null,
                            'certificate_id' => $isForge ? $ids[2] : null,
                        ];
                    })
                    ->all();
            })
            ->unique(fn (array $certificate): string => $certificate['domain_id'] !== null
                ? "{$certificate['domain_id']}/{$certificate['certificate_id']}"
                : $certificate['path'])
            ->values();
    }
}
