<?php

namespace App\Filament\Resources\Ads;

use App\Filament\Resources\Ads\Pages\CreateAd;
use App\Filament\Resources\Ads\Pages\EditAd;
use App\Filament\Resources\Ads\Pages\ListAds;
use App\Filament\Resources\Ads\Schemas\AdForm;
use App\Filament\Resources\Ads\Tables\AdsTable;
use App\Models\Ad;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AdResource extends Resource
{
    protected static ?string $model = Ad::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $recordTitleAttribute = 'creative';

    protected static ?int $navigationSort = 25;

    public static function form(Schema $schema): Schema
    {
        return AdForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdsTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return (bool) Auth::user()?->isAdmin();
    }

    public static function canCreate(): bool
    {
        return (bool) Auth::user()?->isAdmin();
    }

    public static function canEdit(Model $record): bool
    {
        return (bool) Auth::user()?->isAdmin();
    }

    public static function canDelete(Model $record): bool
    {
        return (bool) Auth::user()?->isAdmin();
    }

    public static function canDeleteAny(): bool
    {
        return (bool) Auth::user()?->isAdmin();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('site')
            ->withCount(['impressions', 'clicks']);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAds::route('/'),
            'create' => CreateAd::route('/create'),
            'edit' => EditAd::route('/{record}/edit'),
        ];
    }
}
