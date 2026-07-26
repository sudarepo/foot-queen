<?php

namespace App\Filament\Resources\Cams;

use App\Filament\Resources\Cams\Pages\ListCams;
use App\Filament\Resources\Cams\Pages\ViewCam;
use App\Filament\Resources\Cams\Schemas\CamInfolist;
use App\Filament\Resources\Cams\Tables\CamsTable;
use App\Models\Cam;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Read-only — cam data is entirely managed by CamSyncService (see
 * app/Console/Commands/SyncCams.php), overwritten on every sync run. Creating
 * or editing rows by hand here would just get clobbered, so there's no
 * form/create/edit page, only list + view.
 */
class CamResource extends Resource
{
    protected static ?string $model = Cam::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function infolist(Schema $schema): Schema
    {
        return CamInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CamsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
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
            'index' => ListCams::route('/'),
            'view' => ViewCam::route('/{record}'),
        ];
    }
}
