<?php

namespace App\Filament\Resources\Sites;

use App\Filament\Resources\Sites\Pages\CreateSite;
use App\Filament\Resources\Sites\Pages\EditSite;
use App\Filament\Resources\Sites\Pages\ListSites;
use App\Filament\Resources\Sites\Schemas\SiteForm;
use App\Filament\Resources\Sites\Tables\SitesTable;
use App\Models\Site;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * One record per domain this deploy serves.
 *
 * Everything that used to be hardcoded for a single site — its name, logo,
 * the Chaturbate tags the sync searches, the page copy, the affiliate label —
 * is editable here, so launching another niche domain is a record and a DNS
 * entry rather than a fork of the codebase.
 */
class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = -1;

    public static function form(Schema $schema): Schema
    {
        return SiteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SitesTable::configure($table);
    }

    /**
     * Non-admins see only the sites assigned to them (see Site::scopeAdministeredBy).
     *
     * This backs the record lookup on the edit page as well as the listing, so
     * an unassigned site is a 404 by URL, not just an absent table row.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->administeredBy(Auth::user());
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSites::route('/'),
            'create' => CreateSite::route('/create'),
            'edit' => EditSite::route('/{record}/edit'),
        ];
    }
}
