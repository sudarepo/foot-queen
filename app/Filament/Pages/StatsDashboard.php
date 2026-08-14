<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ChaturbateRevenueChartWidget;
use App\Filament\Widgets\ChaturbateStatsWidget;
use App\Filament\Widgets\TrafficStatsWidget;
use App\Filament\Widgets\TrafficTrendChartWidget;
use App\Models\Site;
use App\Services\DeviceDetector;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

/**
 * General traffic + revenue overview — distinct from ConversionDashboard,
 * which is scoped specifically to the grid-vs-feed A/B test. This page
 * answers "how's the network doing," not "which layout wins."
 */
class StatsDashboard extends Page
{
    use HasFiltersForm;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?string $navigationLabel = 'Stats';

    protected static ?string $title = 'Stats';

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'md' => 4])
                ->columnSpanFull()
                ->schema([
                    /**
                     * Only the "Site traffic" section below actually reads
                     * this — Chaturbate's stats API can't be broken down by
                     * site (confirmed: it silently ignores group_by/track
                     * params), so the revenue section stays network-wide no
                     * matter what's picked here. That's called out in the
                     * explanatory section rather than left implicit.
                     */
                    Select::make('site_id')
                        ->label('Site')
                        ->options(fn () => Site::query()
                            ->administeredBy(Auth::user())
                            ->orderBy('name')
                            ->pluck('name', 'id'))
                        ->placeholder('All sites (pooled)')
                        ->helperText('Which domain\'s traffic to measure. Only affects site traffic, not Chaturbate revenue below.'),
                    Select::make('device')
                        ->label('Device')
                        ->options([
                            DeviceDetector::MOBILE => 'Mobile',
                            DeviceDetector::DESKTOP => 'Desktop',
                        ])
                        ->placeholder('All devices (pooled)')
                        ->helperText('Events logged before '.DeviceDetector::TRACKING_SINCE.' have no device recorded and drop out of a filtered view.'),
                    DatePicker::make('from')
                        ->label('From')
                        ->native(false)
                        ->default(now()->subDays(30)->toDateString())
                        ->helperText('Defaults to the last 30 days.'),
                    DatePicker::make('until')
                        ->label('Until')
                        ->native(false)
                        ->helperText('Leave blank for "up to now".'),
                ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFiltersFormContentComponent(),
            Section::make('What these numbers mean')
                ->collapsible()
                ->schema([
                    Text::make('Site traffic — your own pageviews and outbound clicks to Chaturbate, logged per site and device. Filtered by the selectors above.'),
                    Text::make('Chaturbate revenue is account-wide across every domain this deploy serves — Chaturbate\'s affiliate Stats API has no way to report earnings per site or per tracking sub-id (its group_by/track query parameters are accepted but silently ignored), so that section ignores the Site filter above no matter what\'s selected.')
                        ->color('warning'),
                    Text::make('Payout figures exclude settlement rows (payout requests, adjustments, cashed-out tokens) — those are money moving out, not money earned, and mixing them in would understate revenue on days a payout was requested.'),
                ]),
            Section::make('Site traffic')
                ->schema($this->getWidgetsSchemaComponents([
                    TrafficStatsWidget::class,
                    TrafficTrendChartWidget::class,
                ])),
            Section::make('Chaturbate revenue (account-wide)')
                ->schema($this->getWidgetsSchemaComponents([
                    ChaturbateStatsWidget::class,
                    ChaturbateRevenueChartWidget::class,
                ])),
        ]);
    }

    public function getFiltersFormContentComponent(): Component
    {
        return EmbeddedSchema::make('filtersForm');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncChaturbateStats')
                ->label('Sync Chaturbate now')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (): bool => (bool) Auth::user()?->isAdmin())
                ->requiresConfirmation()
                ->modalDescription('Pulls the latest affiliate revenue stats from Chaturbate right now, instead of waiting for the next scheduled sync.')
                ->action(function () {
                    Artisan::call('chaturbate:sync-stats');

                    Notification::make()
                        ->title('Sync complete')
                        ->body(trim(Artisan::output()) ?: null)
                        ->success()
                        ->send();
                }),
        ];
    }
}
