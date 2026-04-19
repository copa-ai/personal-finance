<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Enums\ExpenseItemType;
use App\Enums\Recurrence;
use App\Models\Expense;
use App\Models\ExpenseItem;
use Illuminate\Database\Eloquent\Model;
use Filament\Resources\Pages\CreateRecord;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    protected bool $shouldCreateSingleExpenseItem = false;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $singleExpenseItemData = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->shouldCreateSingleExpenseItem = (bool) ($data['create_single_item'] ?? false);
        $this->singleExpenseItemData = is_array($data['single_item'] ?? null) ? $data['single_item'] : null;

        unset($data['create_single_item']);
        unset($data['single_item']);

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $record = new ($this->getModel())($data);

        if ($parentRecord = $this->getParentRecord()) {
            $record = $this->associateRecordWithParent($record, $parentRecord);
        } else {
            $record->save();
        }

        if ($record instanceof Expense) {
            $this->createSingleExpenseItem($record);
        }

        return $record;
    }

    protected function createSingleExpenseItem(Expense $expense): void
    {
        if (! $this->shouldCreateSingleExpenseItem) {
            return;
        }

        if ($expense->items()->exists()) {
            return;
        }

        $itemData = $this->singleExpenseItemData ?? [];
        $quantity = $this->normalizeQuantity($itemData['quantity'] ?? 1);
        $unitPrice = $this->normalizeMoney($itemData['unit_price'] ?? null);

        if ($unitPrice === null) {
            $total = $this->normalizeMoney($expense->total);
            $unitPrice = $total !== null ? number_format($total / $quantity, 2, '.', '') : '0.00';
        }

        ExpenseItem::create([
            'expense_id' => $expense->id,
            'product_id' => $itemData['product_id'] ?? null,
            'concept' => filled($itemData['concept'] ?? null) ? $itemData['concept'] : $expense->establishment,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tags' => $itemData['tags'] ?? [],
            'item_type' => $itemData['item_type'] ?? ExpenseItemType::VARIABLE_IRREGULAR->value,
            'recurrence' => $itemData['recurrence'] ?? Recurrence::NONE->value,
            'is_consumable' => array_key_exists('is_consumable', $itemData) ? (bool) $itemData['is_consumable'] : true,
            'actual_start_date' => $itemData['actual_start_date'] ?? null,
            'projected_start_date' => $itemData['projected_start_date'] ?? null,
            'end_date' => $itemData['end_date'] ?? null,
        ]);
    }

    protected function normalizeQuantity(mixed $value): string
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        $quantity = is_numeric($value) ? (float) $value : 1.0;
        $quantity = $quantity > 0 ? $quantity : 1.0;

        return number_format($quantity, 3, '.', '');
    }

    protected function normalizeMoney(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
