<?php

namespace App\Filament\Resources\Sites\Pages;

use App\Filament\Resources\Sites\SiteResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ListSites extends ListRecords
{
    protected static string $resource = SiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importCsv')
                ->label('Import CSV')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->visible(fn (): bool => (bool) Auth::user()?->isAdmin())
                ->schema([
                    FileUpload::make('csv')
                        ->label('CSV file')
                        ->disk('local')
                        ->directory('imports/sites')
                        ->acceptedFileTypes([
                            'text/csv',
                            'text/plain',
                            'application/csv',
                            'application/vnd.ms-excel',
                        ])
                        ->required(),
                    Toggle::make('dry_run')
                        ->label('Dry run only')
                        ->default(true)
                        ->helperText('When on, rows are validated and reported but not inserted.'),
                    Toggle::make('sync_forge')
                        ->label('Also sync domains to Forge')
                        ->default(false)
                        ->helperText('After import, run Forge domain sync and SSL certificate requests.'),
                ])
                ->modalHeading('Import sites from CSV')
                ->modalDescription('Upload a CSV in the Site_Copy_By_Domain_v2 format and import new sites. Existing domains/slugs are skipped.')
                ->action(function (array $data): void {
                    $path = (string) ($data['csv'] ?? '');

                    if ($path === '') {
                        Notification::make()
                            ->title('Import failed')
                            ->body('No CSV file was uploaded.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $fullPath = Storage::disk('local')->path($path);

                    try {
                        $exitCode = Artisan::call('sites:import-csv', [
                            'path' => $fullPath,
                            '--dry-run' => (bool) ($data['dry_run'] ?? false),
                        ]);

                        $output = trim(Artisan::output());

                        if ($exitCode === 0 && (bool) ($data['sync_forge'] ?? false)) {
                            $forgeExitCode = Artisan::call('sites:sync-forge-domains', [
                                '--dry-run' => (bool) ($data['dry_run'] ?? false),
                            ]);

                            $forgeOutput = trim(Artisan::output());

                            $output = collect([$output, $forgeOutput])
                                ->filter(fn (string $part): bool => $part !== '')
                                ->implode("\n\n");

                            if ($forgeExitCode !== 0) {
                                $exitCode = $forgeExitCode;
                            }
                        }

                        $notification = Notification::make()
                            ->title($exitCode === 0 ? 'CSV processed' : 'Import failed')
                            ->body($output !== '' ? $output : null);

                        if ($exitCode === 0) {
                            $notification->success()->send();
                        } else {
                            $notification->danger()->send();
                        }
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title('Import failed')
                            ->body('An unexpected error occurred while importing the CSV.')
                            ->danger()
                            ->send();
                    } finally {
                        Storage::disk('local')->delete($path);
                    }
                }),
            Action::make('syncForgeDomains')
                ->label('Sync Domains to Forge')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('gray')
                ->visible(fn (): bool => (bool) Auth::user()?->isAdmin())
                ->schema([
                    Toggle::make('dry_run')
                        ->label('Dry run only')
                        ->default(true)
                        ->helperText('Preview what would be added in Forge without creating domains or certificates.'),
                    Toggle::make('skip_certificates')
                        ->label('Skip SSL certificate requests')
                        ->default(false)
                        ->helperText('Adds missing domains only, without requesting Let\'s Encrypt certificates.'),
                    Toggle::make('with_inactive')
                        ->label('Include inactive sites')
                        ->default(false),
                ])
                ->requiresConfirmation()
                ->modalHeading('Sync domains to Forge')
                ->modalDescription('Push site domains to Forge and optionally request Let\'s Encrypt certificates for them.')
                ->action(function (array $data): void {
                    try {
                        $exitCode = Artisan::call('sites:sync-forge-domains', [
                            '--dry-run' => (bool) ($data['dry_run'] ?? true),
                            '--skip-certificates' => (bool) ($data['skip_certificates'] ?? false),
                            '--with-inactive' => (bool) ($data['with_inactive'] ?? false),
                        ]);

                        $output = trim(Artisan::output());

                        $notification = Notification::make()
                            ->title($exitCode === 0 ? 'Forge sync finished' : 'Forge sync failed')
                            ->body($output !== '' ? $output : null);

                        if ($exitCode === 0) {
                            $notification->success()->send();
                        } else {
                            $notification->danger()->send();
                        }
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title('Forge sync failed')
                            ->body('An unexpected error occurred while syncing domains to Forge.')
                            ->danger()
                            ->send();
                    }
                }),
            CreateAction::make(),
        ];
    }
}
