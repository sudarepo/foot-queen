<?php

namespace App\Filament\Resources\Sites\Pages;

use App\Filament\Resources\Sites\ForgeSyncNotification;
use App\Filament\Resources\Sites\SiteResource;
use App\Models\Site;
use App\Services\Forge\ForgeDomainProvisioner;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Throwable;

class EditSite extends EditRecord
{
    protected static string $resource = SiteResource::class;

    /**
     * Forge status for this site, memoized for the current request only.
     *
     * @var array{missing_domains:array<int, string>, missing_certificates:array<int, string>}|null
     */
    private ?array $forgeStatus = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addToForge')
                ->label('Add to Forge')
                ->icon(Heroicon::OutlinedServerStack)
                ->color('gray')
                ->visible(fn (): bool => $this->canManageForge() && $this->forgeStatus()['missing_domains'] !== [])
                ->requiresConfirmation()
                ->modalHeading('Add domains to Forge')
                ->modalDescription(fn (): string => 'Adds '.implode(', ', $this->forgeStatus()['missing_domains']).' to Forge. SSL can be requested once the domains are added.')
                ->action(fn () => $this->syncWithForge(requestDomains: true, requestCertificates: false)),
            Action::make('requestSsl')
                ->label('Request SSL')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('gray')
                ->visible(fn (): bool => $this->canManageForge()
                    && $this->forgeStatus()['missing_domains'] === []
                    && $this->forgeStatus()['missing_certificates'] !== [])
                ->requiresConfirmation()
                ->modalHeading('Request SSL certificate')
                ->modalDescription(fn (): string => 'Requests a Let\'s Encrypt certificate in Forge for '.implode(', ', $this->forgeStatus()['missing_certificates']).'.')
                ->action(fn () => $this->syncWithForge(requestDomains: false, requestCertificates: true)),
            DeleteAction::make(),
        ];
    }

    private function canManageForge(): bool
    {
        return (bool) Auth::user()?->isAdmin() && app(ForgeDomainProvisioner::class)->isConfigured();
    }

    /**
     * Reports nothing missing when Forge can't be reached, so the buttons hide.
     *
     * @return array{missing_domains:array<int, string>, missing_certificates:array<int, string>}
     */
    private function forgeStatus(): array
    {
        if ($this->forgeStatus !== null) {
            return $this->forgeStatus;
        }

        /** @var Site $site */
        $site = $this->getRecord();

        try {
            return $this->forgeStatus = app(ForgeDomainProvisioner::class)->siteStatus($site);
        } catch (Throwable $exception) {
            report($exception);

            return $this->forgeStatus = ['missing_domains' => [], 'missing_certificates' => []];
        }
    }

    private function syncWithForge(bool $requestDomains, bool $requestCertificates): void
    {
        try {
            $result = app(ForgeDomainProvisioner::class)->sync(
                collect([$this->getRecord()]),
                requestCertificates: $requestCertificates,
                dryRun: false,
                requestDomains: $requestDomains,
            );
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Forge sync failed')
                ->body(e($exception->getMessage()))
                ->danger()
                ->send();

            return;
        }

        $this->forgeStatus = null;

        ForgeSyncNotification::make($result, dryRun: false)->send();
    }
}
