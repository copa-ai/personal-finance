<?php

namespace App\Services\MarketData;

use Illuminate\Support\Facades\Cache;

/**
 * Cotizaciones para pintar páginas, con una caché corta: abrir varias veces la misma
 * página no debe gastar el límite de peticiones de los proveedores.
 */
class MarketQuotes
{
    public function __construct(private MarketDataClient $client)
    {
    }

    /**
     * @param  array<int, string>  $symbols
     * @return array{quotes: array<string, array<string, mixed>>, errors: array<string, string>, error: string|null}
     */
    public function get(array $symbols, int $ttl = 60): array
    {
        $symbols = array_values(array_unique($symbols));
        sort($symbols);

        if ($symbols === []) {
            return ['quotes' => [], 'errors' => [], 'error' => null];
        }

        if (! $this->client->enabled()) {
            return ['quotes' => [], 'errors' => [], 'error' => 'El servicio de datos de mercado no está configurado (MARKET_DATA_URL).'];
        }

        try {
            $result = Cache::remember('market:quotes:' . md5(implode(',', $symbols)), $ttl, fn (): array => $this->client->quotes($symbols));
        } catch (MarketDataUnavailable $e) {
            return ['quotes' => [], 'errors' => [], 'error' => $e->getMessage()];
        }

        return [
            'quotes' => collect($result['quotes'])->keyBy('symbol')->all(),
            'errors' => collect($result['errors'])->pluck('error', 'symbol')->all(),
            'error' => null,
        ];
    }
}
