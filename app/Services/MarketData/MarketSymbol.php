<?php

namespace App\Services\MarketData;

/**
 * Símbolo canónico de pf-market-data: "<tipo>:<código>".
 * Códigos tradicionales al estilo Yahoo (^GSPC, IWDA.AS, GC=F); cripto BASE-QUOTE; fx BASEQUOTE.
 */
final class MarketSymbol
{
    public const KINDS = [
        'crypto' => 'Cripto',
        'index' => 'Índice',
        'etf' => 'ETF',
        'stock' => 'Acción',
        'fx' => 'Divisa',
        'commodity' => 'Materia prima',
        'bond' => 'Bono / tipo',
        'fund' => 'Fondo',
    ];

    /**
     * Las mismas reglas que pf-market-data (service/symbols.py). Si un símbolo no las
     * cumple, el servicio rechaza la petición /quote entera, no solo ese símbolo.
     */
    public const STRICT_PATTERN = '/^(?:crypto:[A-Z0-9]{2,10}-[A-Z]{3,4}|fx:[A-Z]{6}|(?:index|etf|stock|commodity|bond|fund):\^?[A-Z0-9][A-Z0-9.\-=]{0,23})$/';

    /**
     * Para formularios: admite minúsculas, que normalize() pasa a mayúsculas al guardar.
     */
    public const PATTERN = '/^(?:crypto:[A-Za-z0-9]{2,10}-[A-Za-z]{3,4}|fx:[A-Za-z]{6}|(?:index|etf|stock|commodity|bond|fund):\^?[A-Za-z0-9][A-Za-z0-9.\-=]{0,23})$/';

    public static function isValid(?string $symbol): bool
    {
        return is_string($symbol) && preg_match(self::STRICT_PATTERN, $symbol) === 1;
    }

    /**
     * "Stock:aapl " -> "stock:AAPL". Devuelve null si ni así es válido.
     */
    public static function normalize(?string $symbol): ?string
    {
        if (! is_string($symbol) || ! str_contains($symbol, ':')) {
            return null;
        }

        [$kind, $code] = explode(':', trim($symbol), 2);
        $normalized = strtolower(trim($kind)) . ':' . strtoupper(trim($code));

        return self::isValid($normalized) ? $normalized : null;
    }

    public static function kind(string $symbol): string
    {
        return strstr($symbol, ':', true) ?: '';
    }

    public static function code(string $symbol): string
    {
        return substr((string) strstr($symbol, ':'), 1);
    }

    /**
     * Símbolo equivalente para los widgets de TradingView, que usan otra nomenclatura.
     * Devuelve null cuando no hay una traducción fiable: el widget pedirá buscarlo.
     */
    public static function toTradingView(string $symbol): ?string
    {
        $code = self::code($symbol);

        return match (self::kind($symbol)) {
            'crypto' => 'BITSTAMP:' . str_replace('-', '', strtoupper($code)),
            'fx' => 'FX_IDC:' . strtoupper($code),
            'index' => [
                '^GSPC' => 'SP:SPX', '^IXIC' => 'NASDAQ:IXIC', '^DJI' => 'DJ:DJI', '^IBEX' => 'BME:IBC',
                '^GDAXI' => 'XETR:DAX', '^STOXX50E' => 'TVC:SX5E', '^N225' => 'TVC:NI225', '^FTSE' => 'TVC:UKX',
                '^VIX' => 'TVC:VIX', '^FCHI' => 'EURONEXT:PX1',
            ][$code] ?? null,
            'commodity' => ['GC=F' => 'TVC:GOLD', 'SI=F' => 'TVC:SILVER', 'CL=F' => 'TVC:USOIL', 'BZ=F' => 'TVC:UKOIL'][$code] ?? null,
            'bond' => ['^TNX' => 'TVC:US10Y', '^TYX' => 'TVC:US30Y', '^FVX' => 'TVC:US05Y'][$code] ?? null,
            'stock', 'etf', 'fund' => str_contains($code, '.') ? null : $code,
            default => null,
        };
    }
}
