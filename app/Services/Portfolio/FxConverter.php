<?php

namespace App\Services\Portfolio;

use App\Models\FxRate;
use Carbon\CarbonInterface;

/**
 * Convierte a EUR con el último tipo del BCE (o manual) en o antes de la fecha.
 */
class FxConverter
{
    /** @var array<string, string|null> */
    private array $cache = [];

    /**
     * Euros por 1 unidad de la divisa, o null si no hay tipo guardado.
     */
    public function eurPerUnit(string $currency, CarbonInterface $date): ?string
    {
        $currency = strtoupper($currency);

        if ($currency === 'EUR') {
            return '1';
        }

        $key = $currency . '|' . $date->toDateString();

        if (! array_key_exists($key, $this->cache)) {
            $rate = FxRate::query()
                ->where('currency', $currency)
                ->where('date', '<=', $date->toDateString())
                ->orderByDesc('date')
                ->value('units_per_eur');

            $this->cache[$key] = $rate === null ? null : Dec::div('1', Dec::of($rate));
        }

        return $this->cache[$key];
    }

    public function toEur(string $amount, string $currency, CarbonInterface $date): ?string
    {
        $rate = $this->eurPerUnit($currency, $date);

        return $rate === null ? null : Dec::mul($amount, $rate);
    }
}
