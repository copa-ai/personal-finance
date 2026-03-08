<?php

namespace App\Observers;

use App\Models\ExpenseItem;
use App\Models\Product;

class ExpenseItemObserver
{
    public function created(ExpenseItem $expenseItem): void
    {
        $this->applyDelta(
            $expenseItem->product_id,
            $expenseItem->is_consumable ? (float) $expenseItem->quantity : 0.0,
        );
    }

    public function updated(ExpenseItem $expenseItem): void
    {
        if (! $expenseItem->wasChanged(['product_id', 'is_consumable', 'quantity'])) {
            return;
        }

        $before = [];
        $after = [];

        $originalProductId = $expenseItem->getOriginal('product_id');
        $originalIsConsumable = (bool) $expenseItem->getOriginal('is_consumable');
        $originalQuantity = (float) $expenseItem->getOriginal('quantity');

        if ($originalProductId && $originalIsConsumable) {
            $before[$originalProductId] = $originalQuantity;
        }

        if ($expenseItem->product_id && $expenseItem->is_consumable) {
            $after[$expenseItem->product_id] = (float) $expenseItem->quantity;
        }

        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $productId) {
            $delta = ($after[$productId] ?? 0.0) - ($before[$productId] ?? 0.0);
            $this->applyDelta($productId, $delta);
        }
    }

    public function deleted(ExpenseItem $expenseItem): void
    {
        $this->applyDelta(
            $expenseItem->product_id,
            $expenseItem->is_consumable ? -1 * (float) $expenseItem->quantity : 0.0,
        );
    }

    private function applyDelta(?string $productId, float $delta): void
    {
        if (! $productId || $delta == 0.0) {
            return;
        }

        Product::query()
            ->whereKey($productId)
            ->increment('current_quantity', $delta, ['last_updated_at' => now()]);
    }
}
