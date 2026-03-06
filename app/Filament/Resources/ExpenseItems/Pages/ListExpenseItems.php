<?php

namespace App\Filament\Resources\ExpenseItems\Pages;

use App\Filament\Resources\ExpenseItems\ExpenseItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExpenseItems extends ListRecords
{
    protected static string $resource = ExpenseItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
