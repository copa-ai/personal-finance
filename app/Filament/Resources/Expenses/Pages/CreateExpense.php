<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use Illuminate\Database\Eloquent\Model;
use Filament\Resources\Pages\CreateRecord;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    protected bool $shouldCreateSingleExpenseItem = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->shouldCreateSingleExpenseItem = (bool) ($data['create_single_item'] ?? false);
        unset($data['create_single_item']);

        $this->singleItemConcept = $data['singleItemConcept'] ?? null;
        unset($data['singleItemConcept']);

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $record = new ($this->getModel())($data);
        $record->shouldCreateSingleItem = $this->shouldCreateSingleExpenseItem;

        if ($parentRecord = $this->getParentRecord()) {
            return $this->associateRecordWithParent($record, $parentRecord);
        }

        $record->save();

        return $record;
    }
}
