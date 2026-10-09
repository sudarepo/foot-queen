<?php

namespace App\Filament\Resources\Sites\Tables;

use App\Filament\Resources\Sites\ForgeSyncNotification;
use App\Models\Cam;
use App\Models\Site;
use App\Services\DeviceDetector;
use App\Services\Forge\ForgeDomainProvisioner;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

class SitesTable
{
    private const FORGE_STATUS_PROVISIONED = 'Provisioned';

    private const FORGE_STATUS_MISSING = 'Missing';

    private const FORGE_STATUS_NOT_CONFIGURED = 'Not configured';

    private const FORGE_STATUS_UNKNOWN = 'Unknown';

    private const FORGE_STATUS_NO_DOMAIN = 'No domain';

    private const FORGE_SSL_STATUS_PROVISIONED = 'Provisioned';

    private const FORGE_SSL_STATUS_MISSING = 'Missing';

    private const FORGE_SSL_STATUS_NOT_CONFIGURED = 'Not configured';

    private const FORGE_SSL_STATUS_UNKNOWN = 'Unknown';

    private const FORGE_SSL_STATUS_NO_DOMAIN = 'No domain';

    /**
     * @var array{configured: bool, available: bool, domains: array<int, string>}|null
     */
    private static ?array $forgeDomainState = null;

    /**
     * @var array{configured: bool, available: bool, certificates: array<int, string>}|null
     */
    private static ?array $forgeCertificateState = null;

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Site $record) => $record->slug),

                TextColumn::make('domains')
                    ->badge()
                    ->placeholder('No domain yet')
                    ->listWithLineBreaks()
                    ->limitList(3),

                TextColumn::make('forge_status')
                    ->label('Forge')
                    ->badge()
                    ->state(fn (Site $record): string => self::forgeStatusFor($record))
                    ->color(fn (string $state): string => match ($state) {
                        self::FORGE_STATUS_PROVISIONED => 'success',
                        self::FORGE_STATUS_MISSING => 'warning',
                        self::FORGE_STATUS_UNKNOWN => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('forge_ssl_status')
                    ->label('SSL')
                    ->badge()
                    ->state(fn (Site $record): string => self::forgeSslStatusFor($record))
                    ->color(fn (string $state): string => match ($state) {
                        self::FORGE_SSL_STATUS_PROVISIONED => 'success',
                        self::FORGE_SSL_STATUS_MISSING => 'warning',
                        self::FORGE_SSL_STATUS_UNKNOWN => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('tags')
                    ->label('Keywords')
                    ->badge()
                    ->placeholder('Everything')
                    ->limitList(4),

                /**
                 * How many performers this site's tags actually match right
                 * now — the fastest way to tell a working niche from a
                 * typo'd tag that quietly matches nothing.
                 */
                TextColumn::make('live_cams')
                    ->label('Live now')
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'success' : 'danger')
                    ->state(fn (Site $record) => Cam::online()->forSite($record)->count('*')),

                /**
                 * Which sites are still splitting their traffic and which
                 * have settled — the thing you'd otherwise have to open
                 * every record to find out.
                 */
                TextColumn::make('home_layout')
                    ->label('Homepage')
                    ->badge()
                    ->listWithLineBreaks()
                    ->state(fn (Site $record) => [
                        'Desktop: '.$record->homeLayout(DeviceDetector::DESKTOP)->label(),
                        'Mobile: '.$record->homeLayout(DeviceDetector::MOBILE)->label(),
                    ])
                    ->color('gray'),

                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->recordActions([
                Action::make('retrySsl')
                    ->label('Retry SSL')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->visible(fn (Site $record): bool => (bool) Auth::user()?->isAdmin() && self::forgeSslStatusFor($record) === self::FORGE_SSL_STATUS_MISSING)
                    ->requiresConfirmation()
                    ->modalHeading('Retry SSL certificate request')
                    ->modalDescription('Requests Let\'s Encrypt certificates again for this site without re-adding its domains in Forge.')
                    ->action(function (Site $record): void {
                        try {
                            $result = app(ForgeDomainProvisioner::class)->requestCertificates(collect([$record]), dryRun: false);
                        } catch (Throwable $exception) {
                            report($exception);

                            Notification::make()
                                ->title('SSL retry failed')
                                ->body(e($exception->getMessage()))
                                ->danger()
                                ->send();

                            return;
                        }

                        ForgeSyncNotification::make($result, dryRun: false)->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function forgeStatusFor(Site $site): string
    {
        $domains = self::siteDomains($site);

        if ($domains === []) {
            return self::FORGE_STATUS_NO_DOMAIN;
        }

        $state = self::forgeDomainState();

        if ($state['configured'] === false) {
            return self::FORGE_STATUS_NOT_CONFIGURED;
        }

        if ($state['available'] === false) {
            return self::FORGE_STATUS_UNKNOWN;
        }

        return collect($domains)
            ->contains(fn (string $domain): bool => in_array($domain, $state['domains'], true))
            ? self::FORGE_STATUS_PROVISIONED
            : self::FORGE_STATUS_MISSING;
    }

    /**
     * @return array{configured: bool, available: bool, domains: array<int, string>}
     */
    private static function forgeDomainState(): array
    {
        if (self::$forgeDomainState !== null) {
            return self::$forgeDomainState;
        }

        /** @var ForgeDomainProvisioner $provisioner */
        $provisioner = app(ForgeDomainProvisioner::class);

        if (! $provisioner->isConfigured()) {
            return self::$forgeDomainState = [
                'configured' => false,
                'available' => false,
                'domains' => [],
            ];
        }

        try {
            return self::$forgeDomainState = [
                'configured' => true,
                'available' => true,
                'domains' => $provisioner->existingDomains()->all(),
            ];
        } catch (Throwable) {
            return self::$forgeDomainState = [
                'configured' => true,
                'available' => false,
                'domains' => [],
            ];
        }
    }

    /**
     * @return array{configured: bool, available: bool, certificates: array<int, string>}
     */
    private static function forgeCertificateState(): array
    {
        if (self::$forgeCertificateState !== null) {
            return self::$forgeCertificateState;
        }

        /** @var ForgeDomainProvisioner $provisioner */
        $provisioner = app(ForgeDomainProvisioner::class);

        if (! $provisioner->isConfigured()) {
            return self::$forgeCertificateState = [
                'configured' => false,
                'available' => false,
                'certificates' => [],
            ];
        }

        try {
            return self::$forgeCertificateState = [
                'configured' => true,
                'available' => true,
                'certificates' => $provisioner->existingCertificates()->all(),
            ];
        } catch (Throwable) {
            return self::$forgeCertificateState = [
                'configured' => true,
                'available' => false,
                'certificates' => [],
            ];
        }
    }

    /**
     * @return array<int, string>
     */
    private static function siteDomains(Site $site): array
    {
        return collect($site->domains ?? [])
            ->map(fn (mixed $domain): ?string => is_string($domain) ? Str::lower(trim($domain)) : null)
            ->filter(fn (?string $domain): bool => filled($domain))
            ->values()
            ->all();
    }

    private static function forgeSslStatusFor(Site $site): string
    {
        $domains = self::siteDomains($site);

        if ($domains === []) {
            return self::FORGE_SSL_STATUS_NO_DOMAIN;
        }

        $state = self::forgeCertificateState();

        if ($state['configured'] === false) {
            return self::FORGE_SSL_STATUS_NOT_CONFIGURED;
        }

        if ($state['available'] === false) {
            return self::FORGE_SSL_STATUS_UNKNOWN;
        }

        return collect($domains)
            ->contains(fn (string $domain): bool => in_array($domain, $state['certificates'], true))
            ? self::FORGE_SSL_STATUS_PROVISIONED
            : self::FORGE_SSL_STATUS_MISSING;
    }
}
