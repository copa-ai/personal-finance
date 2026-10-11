<?php

namespace App\Filament\Resources\ContributionPlans\Pages;

use App\Filament\Resources\ContributionPlans\ContributionPlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListContributionPlans extends ListRecords
{
    protected static string $resource = ContributionPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
