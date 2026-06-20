<?php

namespace App\Services;

use App\Models\Expense;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Enums\MovementType;

class BalanceExpensesService
{
    /**
     * Ejecuta el servicio para cuadrar los gastos pendientes.
     *
     * @param Carbon|null $startDate
     * @param Carbon|null $endDate
     * @return array{matched_count:int, adjusted_count:int}
     */
    public function execute(?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $query = Expense::withPendingSubexpenses();
        $matchedCount = 0;
        $adjustedCount = 0;

        if ($startDate) {
            $query->where('date', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('date', '<=', $endDate);
        }

        // Usamos chunk para procesar en lotes y no saturar la memoria
        $query->chunk(100, function ($expenses) use (&$matchedCount, &$adjustedCount) {
            DB::transaction(function () use ($expenses, &$matchedCount, &$adjustedCount) {
                foreach ($expenses as $expense) {
                    // Verificamos nuevamente con la lógica exacta del modelo (diferencia > 10 céntimos)
                    if ($expense->hasPendingSubexpenses()) {
                        $matchedCount++;
                        $this->createAdjustmentItem($expense);
                        $adjustedCount++;
                    }
                }
            });
        });

        return [
            'matched_count' => $matchedCount,
            'adjusted_count' => $adjustedCount,
        ];
    }

    /**
     * Crea el subgasto para ajustar la diferencia del total.
     *
     * @param Expense $expense
     * @return void
     */
    public function createAdjustmentItem(Expense $expense): void
    {
        // subexpensesDifferenceCents() devuelve: (Total de Subgastos) - (Total del Gasto)
        // Si el total del gasto es MAYOR, devuelve un valor negativo. Al invertirlo, nos da un precio positivo (añade el faltante).
        // Si el total del gasto es MENOR, devuelve un valor positivo. Al invertirlo, nos da un precio negativo (resta el sobrante).
        
        $differenceInCents = $expense->subexpensesDifferenceCents();

        if ($differenceInCents === null || $differenceInCents === 0) {
            return;
        }

        // Convertimos los céntimos a decimal (ej. -250 céntimos pasan a ser 2.50)
        $unitPrice = -($differenceInCents / 100);

        $expense->items()->create([
            'category_id' => null,
            'concept'     => 'Ajuste de descuadre',
            'quantity'    => 1,
            'unit_price'  => $unitPrice,
            'movement_type' => MovementType::ADJUSTMENT,
        ]);
    }
}
