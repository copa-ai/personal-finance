<?php

namespace App\Filament\Resources\DemoPortfolios\Pages;

use App\Filament\Resources\DemoPortfolios\DemoPortfolioResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDemoPortfolio extends CreateRecord
{
    protected static string $resource = DemoPortfolioResource::class;
}
