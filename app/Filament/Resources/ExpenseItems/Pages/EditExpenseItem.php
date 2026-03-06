<?php

namespace App\Filament\Resources\ExpenseItems\Pages;

use App\Filament\Resources\ExpenseItems\ExpenseItemResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditExpenseItem extends EditRecord
{
    protected static string $resource = ExpenseItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
