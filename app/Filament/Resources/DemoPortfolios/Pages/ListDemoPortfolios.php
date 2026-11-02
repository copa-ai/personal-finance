<?php

namespace App\Filament\Resources\DemoPortfolios\Pages;

use App\Filament\Resources\DemoPortfolios\DemoPortfolioResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDemoPortfolios extends ListRecords
{
    protected static string $resource = DemoPortfolioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
