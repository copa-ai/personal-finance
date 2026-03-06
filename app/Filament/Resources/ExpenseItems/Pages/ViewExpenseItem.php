<?php

namespace App\Filament\Resources\ExpenseItems\Pages;

use App\Filament\Resources\ExpenseItems\ExpenseItemResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewExpenseItem extends ViewRecord
{
    protected static string $resource = ExpenseItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
