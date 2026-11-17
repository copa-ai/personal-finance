<?php

namespace App\Filament\Resources\InvestmentPlatforms\Pages;

use App\Filament\Resources\InvestmentPlatforms\InvestmentPlatformResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInvestmentPlatforms extends ListRecords
{
    protected static string $resource = InvestmentPlatformResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
