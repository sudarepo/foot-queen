<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable()
                    ->sortable(),

                IconColumn::make('is_admin')
                    ->label('Admin')
                    ->boolean()
                    ->sortable(),

                /**
                 * Blank for admins, who aren't assigned sites — the badge
                 * below says so rather than leaving an ambiguous empty cell.
                 */
                TextColumn::make('sites.name')
                    ->label('Sites')
                    ->badge()
                    ->placeholder(fn (User $record): string => $record->isAdmin() ? 'All sites' : 'None')
                    ->listWithLineBreaks()
                    ->limitList(3),

                TextColumn::make('created_at')
                    ->label('Added')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_admin')
                    ->label('Administrators'),
            ])
            ->recordActions([
                EditAction::make(),
                /**
                 * Per-row rather than a bulk action, so UserPolicy::delete()
                 * gets to refuse on every row — including the "don't delete
                 * yourself" case a bulk selection would sweep up.
                 */
                DeleteAction::make(),
            ]);
    }
}
