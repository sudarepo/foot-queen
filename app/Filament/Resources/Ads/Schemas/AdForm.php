<?php

namespace App\Filament\Resources\Ads\Schemas;

use App\Services\AdPlacement;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AdForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Placement and targeting')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('site_id')
                                ->label('Site')
                                ->relationship('site', 'name')
                                ->searchable()
                                ->preload()
                                ->placeholder('Global (all sites)')
                                ->helperText('Leave empty to show this ad on every site.'),

                            Select::make('placement')
                                ->required()
                                ->options(AdPlacement::options())
                                ->default(AdPlacement::HomepageHeader->value),
                        ]),

                        Grid::make(3)->schema([
                            Toggle::make('is_active')
                                ->default(true)
                                ->label('Active'),

                            TextInput::make('weight')
                                ->numeric()
                                ->minValue(1)
                                ->default(1)
                                ->helperText('Higher weight wins when multiple ads match the same slot.'),

                            TextInput::make('creative')
                                ->required()
                                ->maxLength(120)
                                ->helperText('Short internal name for this banner creative.'),
                        ]),
                    ]),

                Section::make('Creative')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('advertiser')
                                ->required()
                                ->maxLength(120),

                            TextInput::make('campaign')
                                ->required()
                                ->maxLength(120),
                        ]),

                        TextInput::make('destination_url')
                            ->required()
                            ->url()
                            ->maxLength(2048)
                            ->helperText('Where the click should send the visitor.'),

                        FileUpload::make('image_path')
                            ->label('Banner image')
                            ->required()
                            ->image()
                            ->disk('public')
                            ->directory('ads')
                            ->visibility('public')
                            ->helperText('Responsive banner artwork. Recommended size: 728×90 for desktop, with a mobile-safe variant if needed.'),

                        TextInput::make('alt_text')
                            ->maxLength(160)
                            ->helperText('Fallback text if the image cannot load.'),
                    ]),

                Section::make('Schedule')
                    ->schema([
                        Grid::make(2)->schema([
                            DateTimePicker::make('starts_at')
                                ->seconds(false)
                                ->native(false),

                            DateTimePicker::make('ends_at')
                                ->seconds(false)
                                ->native(false),
                        ]),
                    ]),
            ]);
    }
}
