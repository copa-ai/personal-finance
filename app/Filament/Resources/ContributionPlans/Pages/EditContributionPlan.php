<?php

namespace App\Filament\Resources\ContributionPlans\Pages;

use App\Filament\Resources\ContributionPlans\ContributionPlanResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditContributionPlan extends EditRecord
{
    protected static string $resource = ContributionPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
