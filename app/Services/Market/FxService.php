<?php

namespace App\Services\Market;

use App\Enums\InstrumentType;
use App\Enums\MarketProvider;
use App\Models\Instrument;
use Illuminate\Support\Carbon;
use RuntimeException;

class FxService
{
    /**
     * Símbolo del par de divisa EUR/{CCY} (p. ej. EURUSD).
     */
    public static function pairSymbol(string $currency): string
    {
        return 'EUR'.strtoupper($currency);
    }

    /**
     * Devuelve el instrumento del par EUR/{CCY}, creándolo (Yahoo, EURUSD=X) si no existe.
     */
    public function ensurePair(string $currency): Instrument
    {
        $currency = strtoupper($currency);
        $symbol = self::pairSymbol($currency);

        return Instrument::query()->firstOrCreate(
            ['symbol' => $symbol],
            [
                'name' => "EUR/{$currency}",
                'type' => InstrumentType::FX,
                'provider' => MarketProvider::YAHOO,
                'provider_symbol' => $symbol.'=X',
                'currency' => $currency,
            ],
        );
    }

    /**
     * Cuántos EUR vale 1 unidad de $currency: 1 / (cierre de EUR{CCY}=X, que cotiza CCY por 1 EUR)
     * usando el último cierre en o antes de $date. EUR => 1.0.
     *
     * @throws RuntimeException si no hay tipo de cambio.
     */
    public function toEur(string $currency, Carbon|string $date): float
    {
        $currency = strtoupper($currency);

        if ($currency === 'EUR') {
            return 1.0;
        }

        $date = Carbon::parse($date)->toDateString();
        $bar = Instrument::query()
            ->where('symbol', self::pairSymbol($currency))
            ->where('type', InstrumentType::FX->value)
            ->first()
            ?->priceBars()
            ->where('date', '<=', $date)
            ->orderByDesc('date')
            ->first();

        if ($bar === null || (float) $bar->close <= 0) {
            throw new RuntimeException("No hay tipo de cambio EUR/{$currency} anterior o igual a {$date}.");
        }

        return 1 / (float) $bar->close;
    }
}
