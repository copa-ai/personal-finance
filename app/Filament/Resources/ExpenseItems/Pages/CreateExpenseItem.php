<?php

namespace App\Filament\Resources\ExpenseItems\Pages;

use App\Filament\Resources\ExpenseItems\ExpenseItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateExpenseItem extends CreateRecord
{
    protected static string $resource = ExpenseItemResource::class;
}
