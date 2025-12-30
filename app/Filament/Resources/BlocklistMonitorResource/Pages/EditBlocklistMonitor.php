<?php

namespace App\Filament\Resources\BlocklistMonitorResource\Pages;

use App\Filament\Resources\BlocklistMonitorResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBlocklistMonitor extends EditRecord
{
    protected static string $resource = BlocklistMonitorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

