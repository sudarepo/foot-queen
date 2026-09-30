<?php

namespace App\Filament\Resources\Sites\Tables;

use App\Models\Cam;
use App\Models\Site;
use App\Services\DeviceDetector;
use App\Services\Forge\ForgeDomainProvisioner;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

class SitesTable
{
    private const FORGE_STATUS_PROVISIONED = 'Provisioned';

    private const FORGE_STATUS_MISSING = 'Missing';

    private const FORGE_STATUS_NOT_CONFIGURED = 'Not configured';

    private const FORGE_STATUS_UNKNOWN = 'Unknown';

    private const FORGE_STATUS_NO_DOMAIN = 'No domain';

    /**
     * @var array{configured: bool, available: bool, domains: array<int, string>}|null
     */
    private static ?array $forgeDomainState = null;

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
        $domain = self::primaryDomainFromSite($site);

        if ($domain === null) {
            return self::FORGE_STATUS_NO_DOMAIN;
        }

        $state = self::forgeDomainState();

        if ($state['configured'] === false) {
            return self::FORGE_STATUS_NOT_CONFIGURED;
        }

        if ($state['available'] === false) {
            return self::FORGE_STATUS_UNKNOWN;
        }

        return in_array($domain, $state['domains'], true)
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

    private static function primaryDomainFromSite(Site $site): ?string
    {
        $domain = Arr::first($site->domains ?? [], fn (mixed $domain): bool => is_string($domain) && filled($domain));

        return is_string($domain) ? Str::lower(trim($domain)) : null;
    }
}
