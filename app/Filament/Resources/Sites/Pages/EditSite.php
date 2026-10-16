<?php

namespace App\Filament\Resources\Sites\Pages;

use App\Filament\Resources\Sites\SiteResource;
use App\Jobs\AddSiteToForge;
use App\Jobs\RequestSiteSsl;
use App\Models\Site;
use App\Services\Forge\ForgeDomainProvisioner;
use App\Services\Forge\ForgeDomainStatus;
use App\Services\Forge\ForgeSslStatus;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Forge is set up automatically when a site is created or its domains change
 * (see Site::booted()). The actions here are for when that went wrong: redo
 * this one site, or re-read its status from Forge.
 */
class EditSite extends EditRecord
{
    protected static string $resource = SiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('provisionInForge')
                ->label(fn (): string => $this->domainIsInForge() ? 'Retry SSL' : 'Add to Forge')
                ->icon(Heroicon::OutlinedServerStack)
                ->color('gray')
                ->visible(fn (): bool => $this->canManageForge() && $this->needsProvisioning())
                ->requiresConfirmation()
                ->modalHeading(fn (): string => $this->domainIsInForge() ? 'Request SSL again' : 'Add this site to Forge')
                ->modalDescription(fn (): string => $this->domainIsInForge()
                    ? 'Queues a new Let\'s Encrypt request for '.implode(', ', $this->site()->domains ?? []).'.'
                    : 'Queues '.implode(', ', $this->site()->domains ?? []).' to be added to Forge; SSL is requested automatically once the domain is in.')
                ->action(function (): void {
                    $this->domainIsInForge()
                        ? RequestSiteSsl::queueFor($this->site())
                        : AddSiteToForge::queueFor($this->site());

                    Notification::make()
                        ->title('Forge setup started')
                        ->body('It starts within a minute. The Forge and SSL status update as it goes.')
                        ->success()
                        ->send();
                }),
            Action::make('checkForgeStatus')
                ->label('Re-check Forge')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (): bool => $this->canManageForge())
                ->action(function (): void {
                    try {
                        app(ForgeDomainProvisioner::class)->recordStatuses(
                            collect([$this->site()]),
                            fresh: true,
                            errors: array_filter([$this->site()->getKey() => $this->site()->forge_error]),
                        );
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()->title('Could not read Forge')->body(e($exception->getMessage()))->danger()->send();

                        return;
                    }

                    $site = $this->site()->refresh();

                    Notification::make()
                        ->title('Forge: '.$site->forge_domain_status->label().' · SSL: '.$site->forge_ssl_status->label())
                        ->success()
                        ->send();
                }),
            DeleteAction::make(),
        ];
    }

    public function getSubheading(): ?string
    {
        if (! $this->canManageForge()) {
            return null;
        }

        $site = $this->site();
        $checked = $site->forge_checked_at === null ? 'never checked' : 'checked '.$site->forge_checked_at->diffForHumans();

        return "Forge: {$site->forge_domain_status->label()} · SSL: {$site->forge_ssl_status->label()} ({$checked})"
            .($site->forge_error ? " — {$site->forge_error}" : '');
    }

    private function site(): Site
    {
        /** @var Site $site */
        $site = $this->getRecord();

        return $site;
    }

    private function canManageForge(): bool
    {
        return (bool) Auth::user()?->isAdmin() && app(ForgeDomainProvisioner::class)->isConfigured();
    }

    private function domainIsInForge(): bool
    {
        return $this->site()->forge_domain_status === ForgeDomainStatus::Provisioned;
    }

    private function needsProvisioning(): bool
    {
        $site = $this->site();

        $busy = $site->forge_domain_status === ForgeDomainStatus::InProgress
            || in_array($site->forge_ssl_status, [ForgeSslStatus::Queued, ForgeSslStatus::InProgress], true);

        if (blank($site->domains) || $busy) {
            return false;
        }

        return $site->forge_domain_status !== ForgeDomainStatus::Provisioned
            || ! $site->forge_ssl_status->isSecured();
    }
}
