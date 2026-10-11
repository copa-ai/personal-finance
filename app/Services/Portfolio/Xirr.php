<?php

namespace App\Services\Portfolio;

use Carbon\CarbonInterface;

/**
 * Tasa interna de rentabilidad anualizada con fechas irregulares (XIRR).
 * Tiene en cuenta cuándo entra y sale cada euro, a diferencia de "ganado / aportado".
 */
final class Xirr
{
    /**
     * @param  array<int, array{date: CarbonInterface, amount: string|float}>  $flows
     * @return float|null  Tasa anual (0.07 = 7 %), o null si no se puede calcular.
     */
    public static function calculate(array $flows): ?float
    {
        $flows = array_values(array_filter($flows, fn (array $f): bool => (float) $f['amount'] != 0.0));

        $hasPositive = false;
        $hasNegative = false;
        foreach ($flows as $flow) {
            $hasPositive = $hasPositive || (float) $flow['amount'] > 0;
            $hasNegative = $hasNegative || (float) $flow['amount'] < 0;
        }

        if (! $hasPositive || ! $hasNegative) {
            return null;
        }

        usort($flows, fn (array $a, array $b): int => $a['date'] <=> $b['date']);
        $start = $flows[0]['date'];

        $points = array_map(fn (array $f): array => [
            'years' => $start->diffInDays($f['date']) / 365.0,
            'amount' => (float) $f['amount'],
        ], $flows);

        if (end($points)['years'] <= 0) {
            return null;
        }

        $npv = function (float $rate) use ($points): float {
            $sum = 0.0;
            foreach ($points as $p) {
                $sum += $p['amount'] / ((1 + $rate) ** $p['years']);
            }

            return $sum;
        };

        // Bisección: robusta y suficiente para carteras personales.
        $low = -0.9999;
        $high = 10.0;
        $fLow = $npv($low);
        $fHigh = $npv($high);

        if ($fLow * $fHigh > 0) {
            return null;
        }

        for ($i = 0; $i < 200; $i++) {
            $mid = ($low + $high) / 2;
            $fMid = $npv($mid);

            if (abs($fMid) < 1e-7 || ($high - $low) / 2 < 1e-9) {
                return round($mid, 6);
            }

            if ($fLow * $fMid < 0) {
                $high = $mid;
            } else {
                $low = $mid;
                $fLow = $fMid;
            }
        }

        return round(($low + $high) / 2, 6);
    }
}
