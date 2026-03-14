<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Enums\ExpenseItemType;
use App\Enums\Recurrence;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\ExpenseItem;
use Filament\Resources\Pages\CreateRecord;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    protected bool $shouldCreateSingleExpenseItem = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->shouldCreateSingleExpenseItem = (bool) ($data['create_single_item'] ?? false);
        unset($data['create_single_item']);

        return $data;
    }

    protected function afterCreate(): void
    {
        if (! $this->shouldCreateSingleExpenseItem) {
            return;
        }

        ExpenseItem::create([
            'expense_id' => $this->record->id,
            'concept' => $this->record->establishment,
            'quantity' => 1,
            'unit_price' => $this->record->total,
            'item_type' => ExpenseItemType::VARIABLE_IRREGULAR,
            'recurrence' => Recurrence::NONE,
            'is_consumable' => true,
        ]);
    }
}
