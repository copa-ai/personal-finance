<?php

namespace App\Observers;

use App\Enums\ExpenseItemType;
use App\Enums\Recurrence;
use App\Models\Expense;
use App\Models\ExpenseItem;

class ExpenseObserver
{
    public function created(Expense $expense): void
    {
        if (! $expense->shouldCreateSingleItem) {
            return;
        }

        if ($expense->items()->exists()) {
            return;
        }

        ExpenseItem::create([
            'expense_id' => $expense->id,
            'concept' => $expense->establishment,
            'quantity' => 1,
            'unit_price' => $expense->total,
            'item_type' => ExpenseItemType::VARIABLE_IRREGULAR,
            'recurrence' => Recurrence::NONE,
            'is_consumable' => true,
        ]);
    }
}

