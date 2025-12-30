<?php

namespace App\Filament\Resources\DmarcMonitorResource\Pages;

use App\Filament\Resources\DmarcMonitorResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDmarcMonitors extends ListRecords
{
    protected static string $resource = DmarcMonitorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

