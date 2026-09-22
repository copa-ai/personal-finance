<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Models\ExpenseItem;
use Illuminate\Database\Eloquent\Builder;

class StockService
{
    /**
     * Agrupa los subgastos consumibles por producto (concepto + categoría efectiva)
     * y calcula cuánto queda de cada uno a partir de sus movimientos:
     * las compras suman, los consumos y pérdidas restan. Los ajustes de descuadre
     * (movement_type ADJUSTMENT) no son movimientos de stock, así que se excluyen.
     */
    public function query(): Builder
    {
        return ExpenseItem::query()
            ->join('expenses', 'expense_items.expense_id', '=', 'expenses.id')
            ->leftJoin('establishments', 'expenses.establishment_id', '=', 'establishments.id')
            ->leftJoin('categories as item_categories', 'expense_items.category_id', '=', 'item_categories.id')
            ->leftJoin('categories as establishment_categories', 'establishments.category_id', '=', 'establishment_categories.id')
            ->where('expense_items.is_consumable', true)
            ->where('expense_items.movement_type', '!=', MovementType::ADJUSTMENT->value)
            ->whereNotNull('expense_items.concept')
            ->where('expense_items.concept', '!=', '')
            ->selectRaw(
                "MD5(expense_items.concept || '|' || COALESCE(item_categories.name, establishment_categories.name, 'Sin categoría')) as id",
            )
            ->selectRaw('expense_items.concept as concept')
            ->selectRaw("COALESCE(item_categories.name, establishment_categories.name, 'Sin categoría') as category_name")
            ->selectRaw(
                "SUM(CASE
                    WHEN expense_items.movement_type = ? THEN expense_items.quantity
                    ELSE -expense_items.quantity
                END) as remaining_quantity",
                [MovementType::PURCHASE->value],
            )
            ->selectRaw(
                "SUM(CASE WHEN expense_items.movement_type = ? THEN expense_items.quantity ELSE 0 END) as purchased_quantity",
                [MovementType::PURCHASE->value],
            )
            ->selectRaw(
                "SUM(CASE WHEN expense_items.movement_type != ? THEN expense_items.quantity ELSE 0 END) as consumed_quantity",
                [MovementType::PURCHASE->value],
            )
            ->selectRaw('MAX(expenses.date) as last_movement_at')
            ->groupByRaw("expense_items.concept, COALESCE(item_categories.name, establishment_categories.name, 'Sin categoría')");
    }
}
