<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ConversionStatsWidget;
use App\Models\Site;
use App\Services\HomepageAbTest;
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

/**
 * Read-only view over the homepage grid-vs-feed A/B test — see
 * App\Services\HomepageAbTest and SEO-IMPROVEMENTS.md for how the underlying
 * page_view_events / cam_click_events data is collected. The numbers are
 * rendered by ConversionStatsWidget (a pre-styled Filament stat-card widget)
 * — hand-rolled Tailwind markup wouldn't be styled at all here (this project
 * has no Tailwind build scanning custom Filament views, only Filament's own
 * pre-compiled component CSS), so this page is fully schema-driven rather
 * than a custom Blade view.
 */
class ConversionDashboard extends Page
{
    use HasFiltersForm;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'A/B Test';

    protected static ?string $title = 'Grid vs. Feed';

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'md' => 3])
                ->schema([
                    /**
                     * Every domain served by this deploy logs into the same
                     * two tables. Pooling them would average two different
                     * audiences on two different niches into a CTR that
                     * describes neither, so the comparison is per-site by
                     * default — "All sites" is available but is a summary,
                     * not an experiment result.
                     */
                    Select::make('site_id')
                        ->label('Site')
                        ->options(fn () => Site::query()->orderBy('name')->pluck('name', 'id'))
                        ->default(fn () => Site::query()->where('is_default', true)->value('id'))
                        ->placeholder('All sites (pooled)')
                        ->helperText('Which domain\'s traffic to measure.'),
                    DatePicker::make('from')
                        ->label('From')
                        ->native(false)
                        ->default(HomepageAbTest::LAUNCHED_AT)
                        ->helperText('Defaults to when the 50/50 split shipped ('.HomepageAbTest::LAUNCHED_AT.'). Earlier data is skewed — "/feed" had no traffic before then, since nothing linked to it yet.'),
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
                    Text::make('View — a real (non-bot) page load of "/" or "/feed", logged once per visit. Bots/crawlers are excluded entirely (see HomepageAbTest::isBot()), so they never inflate these counts.'),
                    Text::make('Click — a real (non-bot) visitor clicking from a cam card through to the actual Chaturbate room, tracked by which page ("/" or "/feed") they clicked from.'),
                    Text::make('CTR — clicks ÷ views for that variant, as a percentage. This is the number that actually answers "which layout converts better," not raw click volume.'),
                    Text::make('The date range below matters: before the split shipped, "/" always showed the grid (nothing sent visitors to "/feed" yet), so all-time totals make grid look artificially ahead. It defaults to the launch date for a fair comparison — widen it if you want to see the full history anyway.')
                        ->color('warning'),
                ]),
            Grid::make(1)->schema(
                $this->getWidgetsSchemaComponents([ConversionStatsWidget::class])
            ),
        ]);
    }

    public function getFiltersFormContentComponent(): Component
    {
        return EmbeddedSchema::make('filtersForm');
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
