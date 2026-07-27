<?php

namespace App\Filament\Resources\Cams\Pages;

use App\Filament\Resources\Cams\CamResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCam extends ViewRecord
{
    protected static string $resource = CamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('visitRoom')
                ->label('Visit room')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->url(fn () => route('cams.redirect', [$this->record, 'src' => 'admin']))
                ->openUrlInNewTab(),
        ];
    }
}
