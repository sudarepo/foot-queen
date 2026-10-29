<?php

namespace App\Filament\Resources\Cams\Tables;

use App\Models\Cam;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
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
                IconColumn::make('is_banned')
                    ->label('Banned')
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
                TernaryFilter::make('is_banned')
                    ->label('Banned'),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('toggleBan')
                    ->label(fn (Cam $record) => $record->is_banned ? 'Unban' : 'Ban')
                    ->icon(fn (Cam $record) => $record->is_banned ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedNoSymbol)
                    ->color(fn (Cam $record) => $record->is_banned ? 'success' : 'danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Cam $record) => $record->is_banned
                        ? 'Unban this cam?'
                        : 'Ban this cam?')
                    ->modalDescription(fn (Cam $record) => $record->is_banned
                        ? 'This cam will become visible again on public pages and sitemap entries.'
                        : 'This cam will be hidden from public pages, profile URLs, and sitemap entries.')
                    ->action(function (Cam $record): void {
                        $record->update(['is_banned' => ! $record->is_banned]);
                    }),
                Action::make('visitRoom')
                    ->label('Visit room')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->url(fn ($record) => route('cams.redirect', [$record, 'src' => 'admin']))
                    ->openUrlInNewTab(),
            ]);
    }
}
