<?php

namespace App\Filament\Resources\PriceAlerts\Pages;

use App\Filament\Resources\PriceAlerts\PriceAlertResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPriceAlert extends EditRecord
{
    protected static string $resource = PriceAlertResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
