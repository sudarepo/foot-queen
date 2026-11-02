<?php

namespace App\Filament\Resources\Ads\Tables;

use App\Models\Ad;
use App\Services\AdPlacement;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AdsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('site.name')
                    ->label('Site')
                    ->badge()
                    ->placeholder('Global')
                    ->sortable(),

                TextColumn::make('placement')
                    ->badge()
                    ->state(fn (Ad $record): string => AdPlacement::from($record->placement)->label())
                    ->sortable(),

                TextColumn::make('creative')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('advertiser')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('campaign')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('impressions_count')
                    ->label('Impressions')
                    ->badge()
                    ->sortable(),

                TextColumn::make('clicks_count')
                    ->label('Clicks')
                    ->badge()
                    ->sortable(),

                TextColumn::make('ctr')
                    ->label('CTR')
                    ->state(fn (Ad $record): string => $record->impressions_count > 0
                        ? number_format(($record->clicks_count / $record->impressions_count) * 100, 2).'%'
                        : '—')
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                TextColumn::make('starts_at')
                    ->dateTime()
                    ->toggleable(),

                TextColumn::make('ends_at')
                    ->dateTime()
                    ->toggleable(),
            ])
            ->recordActions([
                ReplicateAction::make()
                    ->label('Duplicate campaign')
                    ->excludeAttributes(['id', 'created_at', 'updated_at', 'impressions_count', 'clicks_count'])
                    ->mutateRecordDataUsing(function (array $data): array {
                        $data['campaign'] = trim(($data['campaign'] ?? 'Campaign').' (Copy)');
                        $data['creative'] = trim(($data['creative'] ?? 'Creative').' (Copy)');

                        return $data;
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
