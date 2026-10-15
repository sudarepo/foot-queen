<?php

namespace App\Filament\Resources\Sites;

use App\Services\Forge\ForgeDomainProvisioner;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns a ForgeDomainProvisioner::sync() result into a readable notification:
 * changes grouped by kind, domains listed compactly, failures spelled out.
 */
class ForgeSyncNotification
{
    private const MAX_LISTED_DOMAINS = 8;

    /**
     * @param  array{failures:int, skipped_recent_failure?:int, operations:array<int, array{domain:string, action:string, status:string, error:?string}>}  $result
     */
    public static function make(array $result, bool $dryRun): Notification
    {
        $operations = collect($result['operations']);
        $failed = $operations->where('status', ForgeDomainProvisioner::STATUS_FAILED);
        $succeeded = $operations->where('status', '!=', ForgeDomainProvisioner::STATUS_FAILED);

        $sections = collect([
            self::domainSection(
                $succeeded->where('action', ForgeDomainProvisioner::ACTION_ADD_DOMAIN),
                $dryRun ? 'Would add :count' : 'Added :count',
            ),
            self::domainSection(
                $succeeded->where('action', ForgeDomainProvisioner::ACTION_REQUEST_CERTIFICATE),
                $dryRun ? 'Would request SSL for :count' : 'Requested SSL for :count',
            ),
            self::failureSection($failed),
            self::recentFailureSection($result['skipped_recent_failure'] ?? 0),
        ])->filter();

        $notification = Notification::make()
            ->title(self::title($operations->isEmpty(), $failed->count(), $dryRun))
            ->body($sections->isNotEmpty() ? $sections->implode('<br>') : null);

        if ($sections->isNotEmpty()) {
            $notification->persistent();
        }

        if ($failed->isNotEmpty()) {
            return $notification->danger();
        }

        return $dryRun && $operations->isNotEmpty() ? $notification->info() : $notification->success();
    }

    /**
     * Certificates that failed within the last hour aren't re-requested by a
     * bulk sync — each attempt reloads the web server, and a failure is
     * usually DNS that a retry won't fix.
     */
    private static function recentFailureSection(int $count): ?string
    {
        if ($count === 0) {
            return null;
        }

        return '<strong>Skipped SSL for '.$count.' '.Str::plural('domain', $count).'</strong> that failed in the last hour. Use Retry SSL on the site once its DNS points at this server.';
    }

    private static function title(bool $nothingChanged, int $failureCount, bool $dryRun): string
    {
        if ($failureCount > 0) {
            return 'Forge sync finished with '.$failureCount.' '.Str::plural('error', $failureCount);
        }

        if ($nothingChanged) {
            return 'Forge is already up to date';
        }

        return $dryRun ? 'Forge dry run — nothing was changed yet' : 'Forge sync finished';
    }

    /**
     * @param  Collection<int, array{domain:string, action:string, status:string, error:?string}>  $operations
     */
    private static function domainSection(Collection $operations, string $heading): ?string
    {
        if ($operations->isEmpty()) {
            return null;
        }

        $count = $operations->count();
        $domains = $operations->pluck('domain');
        $listed = $domains->take(self::MAX_LISTED_DOMAINS)->map(fn (string $domain): string => e($domain))->implode(', ');
        $remaining = $count - min($count, self::MAX_LISTED_DOMAINS);

        if ($remaining > 0) {
            $listed .= " and {$remaining} more";
        }

        $title = str_replace(':count', $count.' '.Str::plural('domain', $count), $heading);

        return '<p><strong>'.e($title).'</strong><br>'.$listed.'</p>';
    }

    /**
     * @param  Collection<int, array{domain:string, action:string, status:string, error:?string}>  $failures
     */
    private static function failureSection(Collection $failures): ?string
    {
        if ($failures->isEmpty()) {
            return null;
        }

        $lines = $failures->map(function (array $operation): string {
            $what = $operation['action'] === ForgeDomainProvisioner::ACTION_ADD_DOMAIN
                ? 'could not add domain'
                : 'could not request SSL';

            return '<strong>'.e($operation['domain']).'</strong>: '.$what
                .(filled($operation['error']) ? ' ('.e($operation['error']).')' : '');
        });

        return '<p><strong>Failed</strong><br>'.$lines->implode('<br>').'</p>';
    }
}
