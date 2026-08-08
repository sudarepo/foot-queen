<?php

namespace App\Filament\Resources\Sites\Tables;

use App\Models\Cam;
use App\Models\Site;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SitesTable
{
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
                    ->state(fn (Site $record) => Cam::online()->forSite($record)->count()),

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
}
