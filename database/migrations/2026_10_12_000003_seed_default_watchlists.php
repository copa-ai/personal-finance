<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Listas de seguimiento iniciales. Se pueden editar o borrar desde Mercados.
 */
return new class extends Migration
{
    private const LISTS = [
        'Índices' => [
            'index:^GSPC' => 'S&P 500', 'index:^IXIC' => 'Nasdaq Composite', 'index:^DJI' => 'Dow Jones',
            'index:^STOXX50E' => 'Euro Stoxx 50', 'index:^IBEX' => 'IBEX 35', 'index:^GDAXI' => 'DAX',
            'index:^FTSE' => 'FTSE 100', 'index:^N225' => 'Nikkei 225', 'etf:URTH' => 'MSCI World (ETF URTH)',
            'index:^VIX' => 'VIX (volatilidad)',
        ],
        'Cripto' => [
            'crypto:BTC-USD' => 'Bitcoin', 'crypto:BTC-EUR' => 'Bitcoin en euros', 'crypto:ETH-USD' => 'Ethereum',
            'crypto:SOL-USD' => 'Solana', 'crypto:ERG-USD' => 'Ergo',
        ],
        'Divisas, materias primas y tipos' => [
            'fx:EURUSD' => 'EUR/USD', 'fx:EURGBP' => 'EUR/GBP', 'fx:EURCHF' => 'EUR/CHF',
            'commodity:GC=F' => 'Oro', 'commodity:SI=F' => 'Plata', 'commodity:CL=F' => 'Petróleo WTI',
            'commodity:BZ=F' => 'Brent', 'bond:^TNX' => 'Bono EE. UU. 10 años (%)', 'index:DX-Y.NYB' => 'Índice dólar (DXY)',
        ],
    ];

    public function up(): void
    {
        if (DB::table('watchlists')->exists()) {
            return;
        }

        $position = 0;
        foreach (self::LISTS as $name => $items) {
            $id = (string) Str::uuid();
            DB::table('watchlists')->insert(['id' => $id, 'name' => $name, 'position' => $position++, 'created_at' => now(), 'updated_at' => now()]);

            $itemPosition = 0;
            foreach ($items as $symbol => $label) {
                DB::table('watchlist_items')->insert([
                    'id' => (string) Str::uuid(), 'watchlist_id' => $id, 'market_symbol' => $symbol, 'label' => $label,
                    'position' => $itemPosition++, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('watchlists')->whereIn('name', array_keys(self::LISTS))->delete();
    }
};
