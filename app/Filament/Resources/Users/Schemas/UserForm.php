<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Two questions: who is this, and what may they touch.
 */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),

                        /**
                         * Hashed by the model's `password` cast, so no
                         * dehydration callback here. Left blank on an edit it
                         * is dropped from the payload entirely, which is what
                         * keeps "change this user's name" from also resetting
                         * their password to an empty string.
                         */
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->minLength(8)
                            ->maxLength(255)
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText(fn (string $operation): ?string => $operation === 'edit'
                                ? 'Leave blank to keep the current password.'
                                : null)
                            ->columnSpanFull(),
                    ]),

                Section::make('Access')
                    ->description('An administrator manages the whole network. Anyone else only sees the sites ticked below.')
                    ->schema([
                        Toggle::make('is_admin')
                            ->label('Administrator')
                            ->helperText('Full access: every site, plus the ability to add sites and users.')
                            ->live(),

                        /**
                         * Admins reach every site without being listed against
                         * any of them, so the assignments are hidden rather
                         * than shown ticked-and-ignored. Still relationship-
                         * bound, so toggling someone to admin and back leaves
                         * their previous assignments intact.
                         */
                        CheckboxList::make('sites')
                            ->label('Sites this user administers')
                            ->relationship(titleAttribute: 'name')
                            ->columns(2)
                            ->bulkToggleable()
                            ->noSearchResultsMessage('No sites match your search.')
                            ->helperText('They can edit these sites\' branding, content and copy — but not create or delete sites.')
                            ->hidden(fn (Get $get): bool => (bool) $get('is_admin')),
                    ]),
            ]);
    }
}
