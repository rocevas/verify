<?php

namespace App\Filament\Resources\BlocklistMonitorResource\Pages;

use App\Filament\Resources\BlocklistMonitorResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBlocklistMonitors extends ListRecords
{
    protected static string $resource = BlocklistMonitorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

