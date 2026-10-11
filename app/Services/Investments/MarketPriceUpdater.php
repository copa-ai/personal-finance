<?php

namespace App\Services\Investments;

use App\Models\FxRate;
use App\Models\Instrument;
use App\Models\InstrumentPrice;
use App\Services\MarketData\MarketDataClient;
use Illuminate\Support\Carbon;

/**
 * Trae precios de mercado para los instrumentos con símbolo y los tipos del BCE.
 * Nunca toca saldos: solo escribe instrument_prices y fx_rates.
 */
class MarketPriceUpdater
{
    public function __construct(private MarketDataClient $client)
    {
    }

    /**
     * @return array{updated: int, errors: array<int, string>}
     */
    public function refreshPrices(): array
    {
        $instruments = Instrument::query()->whereNotNull('market_symbol')->get();

        if ($instruments->isEmpty()) {
            return ['updated' => 0, 'errors' => []];
        }

        $result = $this->client->quotes($instruments->pluck('market_symbol')->all());
        $quotes = collect($result['quotes'])->keyBy('symbol');
        $today = Carbon::now(config('app.display_timezone'))->toDateString();
        $updated = 0;

        foreach ($instruments as $instrument) {
            $quote = $quotes->get($instrument->market_symbol);

            if ($quote === null || ! is_numeric($quote['price'] ?? null)) {
                continue;
            }

            InstrumentPrice::query()->updateOrCreate(
                ['instrument_id' => $instrument->id, 'date' => $today, 'source' => (string) ($quote['source'] ?? 'market')],
                ['price' => $quote['price'], 'currency' => strtoupper((string) ($quote['currency'] ?? $instrument->currency))],
            );
            $updated++;
        }

        return [
            'updated' => $updated,
            'errors' => collect($result['errors'])->map(fn (array $e): string => "{$e['symbol']}: {$e['error']}")->all(),
        ];
    }

    public function refreshFx(): int
    {
        $fx = $this->client->fx();
        $date = $fx['date'] ?? null;

        if (! $date || ! is_array($fx['rates'] ?? null)) {
            return 0;
        }

        $count = 0;
        foreach ($fx['rates'] as $currency => $rate) {
            if (! is_numeric($rate) || $rate <= 0 || ! preg_match('/^[A-Z]{3}$/', (string) $currency)) {
                continue;
            }

            FxRate::query()->updateOrCreate(
                ['date' => $date, 'currency' => $currency],
                ['units_per_eur' => $rate, 'source' => (string) ($fx['source'] ?? 'ecb')],
            );
            $count++;
        }

        return $count;
    }
}
