<?php

namespace App\Observers;

use App\Models\ExpenseItem;
use App\Enums\MovementType;

class ExpenseItemObserver
{
    /**
     * Handle the ExpenseItem "creating" event.
     */
    public function creating(ExpenseItem $expenseItem): void
    {
        // Si el tipo de movimiento viene vacío, nulo o no está definido
        if (empty($expenseItem->movement_type)) {
            $expenseItem->movement_type = MovementType::PURCHASE;
        }
    }
}