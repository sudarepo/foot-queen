<?php

namespace App\Filament\Resources\Cams\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CamInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                ImageEntry::make('thumbnail_url')
                    ->label('')
                    ->square()
                    ->height(240),
                Section::make('Overview')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('username'),
                        TextEntry::make('provider')->badge(),
                        TextEntry::make('room_subject')->columnSpanFull(),
                        TextEntry::make('gender')->badge(),
                        TextEntry::make('age')->placeholder('—'),
                        TextEntry::make('viewers')->numeric(),
                        TextEntry::make('hair_color')->placeholder('—'),
                        TextEntry::make('body_type')->placeholder('—'),
                        TextEntry::make('country')->placeholder('—'),
                        TextEntry::make('categories')
                            ->badge()
                            ->columnSpanFull(),
                    ]),
                Section::make('Status')
                    ->columns(4)
                    ->schema([
                        IconEntry::make('is_online')->label('Online')->boolean(),
                        IconEntry::make('is_hd')->label('HD')->boolean(),
                        IconEntry::make('is_new')->label('New')->boolean(),
                        TextEntry::make('last_seen_at')->dateTime(),
                    ]),
                Grid::make(1)
                    ->schema([
                        TextEntry::make('room_url')->label('Room URL')->copyable(),
                        TextEntry::make('embed_url')->label('Embed URL')->copyable()->placeholder('—'),
                    ]),
            ]);
    }
}
