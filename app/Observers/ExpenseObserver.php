<?php

namespace App\Observers;

use App\Enums\ExpenseItemType;
use App\Enums\Recurrence;
use App\Models\Expense;
use App\Models\ExpenseItem;
use App\Models\Product;

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

        # Si el concepto equivale a un producto ya creado en DB se asocia, si no queda vacio.
        $product = Product::where('name', $expense->singleItemConcept)->first();
        $units = $expense->singleItemUnits ?? 1;

        ExpenseItem::create([
            'product_id' => $product?->id,
            'expense_id' => $expense->id,
            'concept' => $expense->singleItemConcept,
            'quantity' => $units,
            'unit_price' => $expense->total / $units,
            'item_type' => ExpenseItemType::VARIABLE_IRREGULAR,
            'recurrence' => Recurrence::NONE,
            'is_consumable' => true,
        ]);
    }
}

