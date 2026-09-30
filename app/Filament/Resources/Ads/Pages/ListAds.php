<?php

namespace App\Filament\Resources\Ads\Pages;

use App\Filament\Resources\Ads\AdResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAds extends ListRecords
{
    protected static string $resource = AdResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add ad'),
        ];
    }
}
