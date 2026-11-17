<?php

namespace App\Filament\Resources\InvestmentPlatforms\Pages;

use App\Filament\Resources\InvestmentPlatforms\InvestmentPlatformResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditInvestmentPlatform extends EditRecord
{
    protected static string $resource = InvestmentPlatformResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
