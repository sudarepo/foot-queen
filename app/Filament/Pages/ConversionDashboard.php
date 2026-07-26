<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ConversionStatsWidget;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;

/**
 * Read-only view over the homepage grid-vs-feed A/B test — see
 * App\Services\HomepageAbTest and SEO-IMPROVEMENTS.md for how the underlying
 * page_view_events / cam_click_events data is collected. The actual numbers
 * are rendered by ConversionStatsWidget (a pre-styled Filament stat-card
 * widget) — this page is mostly just a home for it plus the manual sync
 * action, since hand-rolled Tailwind markup here wouldn't be styled at all
 * (this project has no Tailwind build scanning custom Filament views, only
 * Filament's own pre-compiled component CSS).
 */
class ConversionDashboard extends Page
{
    protected string $view = 'filament.pages.conversion-dashboard';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'A/B Test';

    protected static ?string $title = 'Grid vs. Feed';

    protected function getHeaderWidgets(): array
    {
        return [
            ConversionStatsWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncNow')
                ->label('Sync cams now')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Pulls the latest rooms from Chaturbate right now, instead of waiting for the next scheduled sync.')
                ->action(function () {
                    Artisan::call('cams:sync');

                    Notification::make()
                        ->title('Sync complete')
                        ->body(trim(Artisan::output()) ?: null)
                        ->success()
                        ->send();
                }),
        ];
    }
}
