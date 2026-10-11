<?php

namespace App\Services\MarketData;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente de pf-market-data, el único servicio con salida a internet.
 * Esta app nunca habla con proveedores externos: solo envía símbolos a ese servicio.
 */
class MarketDataClient
{
    public function enabled(): bool
    {
        return filled(config('services.market_data.url'));
    }

    /**
     * @param  array<int, string>  $symbols
     * @return array{quotes: array<int, array<string, mixed>>, errors: array<int, array{symbol: string, error: string}>}
     */
    public function quotes(array $symbols): array
    {
        $symbols = array_values(array_unique(array_filter(array_map([MarketSymbol::class, 'normalize'], $symbols))));

        if ($symbols === []) {
            return ['quotes' => [], 'errors' => []];
        }

        $result = ['quotes' => [], 'errors' => []];

        // El servicio admite hasta 50 símbolos por petición.
        foreach (array_chunk($symbols, 50) as $chunk) {
            $data = $this->get('/quote', ['symbols' => implode(',', $chunk)]);
            $result['quotes'] = [...$result['quotes'], ...($data['quotes'] ?? [])];
            $result['errors'] = [...$result['errors'], ...($data['errors'] ?? [])];
        }

        return $result;
    }

    /**
     * @return array{symbol: string, currency: string|null, source: string|null, candles: array<int, array{t: string, o: float, h: float, l: float, c: float, v: float|null}>}
     */
    public function history(string $symbol, string $range = '1y', string $interval = '1d'): array
    {
        return $this->get('/history', ['symbol' => $symbol, 'range' => $range, 'interval' => $interval]);
    }

    /**
     * @return array<int, array{symbol: string, name: string, type: string, exchange: string|null}>
     */
    public function search(string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        return $this->get('/search', ['q' => mb_substr($query, 0, 60)])['results'] ?? [];
    }

    /**
     * Tipos de referencia del BCE.
     *
     * @return array{date: string, base: string, rates: array<string, float>, source: string}
     */
    public function fx(): array
    {
        return $this->get('/fx');
    }

    /**
     * Reenvía un aviso a ntfy a través del servicio (esta app no sale a internet).
     *
     * @param  array<int, string>  $tags
     */
    public function notify(string $title, string $message, string $priority = 'default', array $tags = []): void
    {
        $this->send(fn (PendingRequest $http) => $http->post('/notify', [
            'title' => $title,
            'message' => $message,
            'priority' => $priority,
            'tags' => $tags,
        ]));
    }

    private function get(string $path, array $query = []): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get($path, $query))->json() ?? [];
    }

    private function send(callable $call): Response
    {
        if (! $this->enabled()) {
            throw new MarketDataUnavailable('El servicio de datos de mercado no está configurado (MARKET_DATA_URL).');
        }

        $base = (string) config('services.market_data.url');
        if (! str_starts_with($base, 'http://') && ! str_starts_with($base, 'https://')) {
            $base = 'http://' . $base;
        }

        $http = Http::baseUrl(rtrim($base, '/'))
            ->acceptJson()
            ->timeout((int) config('services.market_data.timeout', 20))
            ->connectTimeout(5);

        try {
            $response = $call($http);
        } catch (ConnectionException $e) {
            throw new MarketDataUnavailable('No se pudo conectar con el servicio de datos de mercado: ' . $e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new MarketDataUnavailable(sprintf(
                'El servicio de datos de mercado respondió %d: %s',
                $response->status(),
                $response->json('error') ?? mb_substr($response->body(), 0, 200),
            ));
        }

        return $response;
    }
}
