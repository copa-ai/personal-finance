<?php

namespace App\Services\Investments;

use App\Enums\InvestmentTransactionType;
use App\Enums\ReminderStatus;
use App\Models\ContributionPlan;
use App\Models\InvestmentTransaction;
use App\Models\Reminder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * "Hecho" en un recordatorio de aportación crea la compra con los datos del plan
 * (el usuario solo corrige precio y cantidad); "Saltar" deja constancia sin movimiento.
 */
class ContributionService
{
    /**
     * @param  array{date: string, quantity: string|float, price: string|float, fees?: string|float|null, fx_rate_eur?: string|float|null, instrument_id?: string|null, notes?: string|null}  $data
     */
    public function complete(Reminder $reminder, array $data): InvestmentTransaction
    {
        $plan = $this->plan($reminder);
        $instrumentId = $data['instrument_id'] ?? $plan->instrument_id;

        if ($instrumentId === null) {
            throw new InvalidArgumentException('Indica en qué instrumento se ha invertido.');
        }

        return DB::transaction(function () use ($reminder, $plan, $data, $instrumentId): InvestmentTransaction {
            $transaction = InvestmentTransaction::query()->create([
                'platform_id' => $plan->platform_id,
                'instrument_id' => $instrumentId,
                'type' => InvestmentTransactionType::BUY,
                'date' => $data['date'],
                'quantity' => $data['quantity'],
                'price' => $data['price'],
                'fees' => $data['fees'] ?? 0,
                'currency' => $plan->currency,
                'fx_rate_eur' => $data['fx_rate_eur'] ?? 1,
                'notes' => $data['notes'] ?? "Aportación periódica: {$plan->name}",
            ]);

            $reminder->resolve(ReminderStatus::DONE);

            return $transaction;
        });
    }

    public function skip(Reminder $reminder): void
    {
        $this->plan($reminder);
        $reminder->resolve(ReminderStatus::SKIPPED);
    }

    private function plan(Reminder $reminder): ContributionPlan
    {
        $plan = $reminder->remindable;

        if (! $plan instanceof ContributionPlan) {
            throw new InvalidArgumentException('El recordatorio no es de una aportación periódica.');
        }

        if ($reminder->status !== ReminderStatus::PENDING) {
            throw new InvalidArgumentException('Este recordatorio ya está resuelto.');
        }

        return $plan;
    }
}
