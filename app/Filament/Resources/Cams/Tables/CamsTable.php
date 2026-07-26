<?php

namespace App\Filament\Resources\Cams\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CamsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('thumbnail_url')
                    ->label('')
                    ->square()
                    ->height(48),
                TextColumn::make('username')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('gender')
                    ->badge()
                    ->sortable(),
                TextColumn::make('viewers')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_online')
                    ->label('Online')
                    ->boolean(),
                IconColumn::make('is_hd')
                    ->label('HD')
                    ->boolean(),
                IconColumn::make('is_new')
                    ->label('New')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('hair_color')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('body_type')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('provider')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_seen_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('viewers', 'desc')
            ->filters([
                TernaryFilter::make('is_online')
                    ->label('Online')
                    ->default(true),
                SelectFilter::make('gender')
                    ->options([
                        'female' => 'Female',
                        'male' => 'Male',
                        'trans' => 'Trans',
                        'couple' => 'Couple',
                    ]),
                TernaryFilter::make('is_hd')
                    ->label('HD'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
