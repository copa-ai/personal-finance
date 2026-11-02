<?php

namespace App\Services\Demo;

use App\Enums\TradeSide;
use App\Models\DemoPortfolio;
use App\Models\Instrument;
use App\Models\PriceBar;
use App\Services\Market\FxService;
use Closure;
use Illuminate\Support\Carbon;

class DemoValuationService
{
    /**
     * Serie diaria: caja + posiciones valoradas al último cierre (en EUR) desde start_date hasta $to (por defecto hoy).
     *
     * @return list<array{date: string, cash: float, positions: float, value: float}>
     */
    public function series(DemoPortfolio $portfolio, Carbon|string|null $to = null): array
    {
        $start = $portfolio->start_date->copy()->startOfDay();
        $end = $to === null ? Carbon::today() : Carbon::parse($to)->startOfDay();

        if ($end->lt($start)) {
            return [];
        }

        $trades = $portfolio->trades()->with('instrument')->orderBy('date')->orderBy('created_at')->get();
        $priceOf = [];   // instrument_id => cursor
        $fxOf = [];      // currency => cursor
        $currencyOf = [];

        foreach ($trades->pluck('instrument')->unique('id') as $instrument) {
            $priceOf[$instrument->id] = $this->cursor($instrument->id);
            $currency = strtoupper((string) $instrument->currency);
            $currencyOf[$instrument->id] = $currency;
            if ($currency !== 'EUR' && ! isset($fxOf[$currency])) {
                $fxOf[$currency] = $this->fxCursor($currency);
            }
        }

        $cash = (float) $portfolio->initial_points;
        $units = [];
        $lastPrice = [];
        $lastFx = [];
        $i = 0;
        $out = [];

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $d = $day->toDateString();

            while ($i < $trades->count() && $trades[$i]->date->toDateString() <= $d) {
                $t = $trades[$i++];
                $sign = $t->side === TradeSide::BUY ? 1 : -1;
                $cash -= $sign * (float) $t->total_points;
                $units[$t->instrument_id] = ($units[$t->instrument_id] ?? 0.0) + $sign * (float) $t->units;
                $lastPrice[$t->instrument_id] ??= (float) $t->price;
                $lastFx[$t->instrument_id] ??= (float) $t->fx_rate;
            }

            $positions = 0.0;
            foreach ($units as $id => $qty) {
                if (abs($qty) < 1e-9) {
                    continue;
                }
                $price = $priceOf[$id]($d) ?? $lastPrice[$id];
                $quote = $currencyOf[$id] === 'EUR' ? 1.0 : $fxOf[$currencyOf[$id]]($d);
                $fx = $currencyOf[$id] === 'EUR' ? 1.0 : ($quote !== null ? 1 / $quote : $lastFx[$id]);
                $lastPrice[$id] = $price;
                $lastFx[$id] = $fx;
                $positions += $qty * $price * $fx;
            }

            $out[] = [
                'date' => $d,
                'cash' => round($cash, 8),
                'positions' => round($positions, 8),
                'value' => round($cash + $positions, 8),
            ];
        }

        return $out;
    }

    /**
     * Posiciones abiertas a fecha $asOf (por defecto hoy), con coste medio en EUR y P&L latente.
     *
     * @return list<array{instrument_id: string, symbol: string, name: string, currency: string, units: float, price: float, fx_rate: float, value: float, cost: float, pnl: float, pnl_percent: float|null}>
     */
    public function positions(DemoPortfolio $portfolio, Carbon|string|null $asOf = null): array
    {
        $asOf = ($asOf === null ? Carbon::today() : Carbon::parse($asOf))->toDateString();
        $trades = $portfolio->trades()->with('instrument')->where('date', '<=', $asOf)->orderBy('date')->orderBy('created_at')->get();

        $held = [];
        foreach ($trades as $t) {
            $h = &$held[$t->instrument_id];
            $h ??= ['instrument' => $t->instrument, 'units' => 0.0, 'cost' => 0.0];

            if ($t->side === TradeSide::BUY) {
                $h['units'] += (float) $t->units;
                $h['cost'] += (float) $t->total_points;
            } else {
                $avg = $h['units'] > 0 ? $h['cost'] / $h['units'] : 0.0;
                $h['cost'] -= $avg * (float) $t->units;
                $h['units'] -= (float) $t->units;
            }
            unset($h);
        }

        $fx = app(FxService::class);
        $out = [];

        foreach ($held as $id => $h) {
            if ($h['units'] < 1e-9) {
                continue;
            }
            /** @var Instrument $instrument */
            $instrument = $h['instrument'];
            $price = $this->closeOnOrBefore($instrument, $asOf);
            $rate = $fx->toEur((string) $instrument->currency, $asOf);
            $value = $h['units'] * $price * $rate;

            $out[] = [
                'instrument_id' => $id,
                'symbol' => $instrument->symbol,
                'name' => $instrument->name,
                'currency' => (string) $instrument->currency,
                'units' => $h['units'],
                'price' => $price,
                'fx_rate' => $rate,
                'value' => round($value, 8),
                'cost' => round($h['cost'], 8),
                'pnl' => round($value - $h['cost'], 8),
                'pnl_percent' => $h['cost'] > 0 ? round(($value - $h['cost']) / $h['cost'] * 100, 4) : null,
            ];
        }

        return $out;
    }

    /**
     * Resumen a fecha $asOf: valor total, P&L absoluto y % sobre los puntos iniciales.
     *
     * @return array{as_of: string, initial_points: float, cash: float, positions_value: float, value: float, pnl: float, pnl_percent: float}
     */
    public function summary(DemoPortfolio $portfolio, Carbon|string|null $asOf = null): array
    {
        $series = $this->series($portfolio, $asOf);
        $last = $series === [] ? null : end($series);
        $initial = (float) $portfolio->initial_points;
        $value = $last['value'] ?? $initial;
        $pnl = $value - $initial;

        return [
            'as_of' => $last['date'] ?? $portfolio->start_date->toDateString(),
            'initial_points' => $initial,
            'cash' => $last['cash'] ?? $initial,
            'positions_value' => $last['positions'] ?? 0.0,
            'value' => $value,
            'pnl' => round($pnl, 8),
            'pnl_percent' => $initial > 0 ? round($pnl / $initial * 100, 4) : 0.0,
        ];
    }

    /**
     * Serie del benchmark: initial_points invertidos en start_date (comisión incluida: fee_fixed + fee_percent%
     * de modo que bruto + comisión = initial_points) y mantenidos. Null si no hay benchmark.
     *
     * @return list<array{date: string, value: float}>|null
     *
     * @throws DemoTradeException
     */
    public function benchmarkSeries(DemoPortfolio $portfolio, Carbon|string|null $to = null): ?array
    {
        $instrument = $portfolio->benchmarkInstrument;

        if ($instrument === null) {
            return null;
        }

        $start = $portfolio->start_date->copy()->startOfDay();
        $end = $to === null ? Carbon::today() : Carbon::parse($to)->startOfDay();
        $startDate = $start->toDateString();

        $gross = ((float) $portfolio->initial_points - (float) $portfolio->fee_fixed) / (1 + (float) $portfolio->fee_percent / 100);
        if ($gross <= 0) {
            throw new DemoTradeException('Los puntos iniciales no cubren la comisión del benchmark.');
        }

        $price0 = $this->closeOnOrBefore($instrument, $startDate);
        $fx = app(FxService::class);
        $units = $gross / ($price0 * $fx->toEur((string) $instrument->currency, $startDate));

        $currency = strtoupper((string) $instrument->currency);
        $price = $this->cursor($instrument->id);
        $fxCursor = $currency === 'EUR' ? null : $this->fxCursor($currency);
        $lastPrice = $price0;
        $lastFx = $fx->toEur($currency, $startDate);
        $out = [];

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $d = $day->toDateString();
            $lastPrice = $price($d) ?? $lastPrice;
            $rate = $fxCursor === null ? 1.0 : $fxCursor($d);
            $lastFx = $rate === null ? $lastFx : ($fxCursor === null ? 1.0 : 1 / $rate);
            $out[] = ['date' => $d, 'value' => round($units * $lastPrice * $lastFx, 8)];
        }

        return $out;
    }

    private function closeOnOrBefore(Instrument $instrument, string $date): float
    {
        $bar = $instrument->priceBars()->where('date', '<=', $date)->orderByDesc('date')->first();

        if ($bar === null) {
            throw new DemoTradeException("No hay cotización de {$instrument->symbol} anterior o igual a {$date}.");
        }

        return (float) $bar->close;
    }

    /** Cursor de cierres del par EUR/{CCY}: devuelve CCY por 1 EUR (o null si aún no hay dato). */
    private function fxCursor(string $currency): Closure
    {
        $pair = Instrument::query()->where('symbol', FxService::pairSymbol($currency))->value('id');

        return $pair === null ? fn (string $d): ?float => null : $this->cursor($pair);
    }

    /**
     * Devuelve una función stateful (fechas crecientes) que da el último cierre en o antes de la fecha.
     *
     * @return Closure(string): ?float
     */
    private function cursor(string $instrumentId): Closure
    {
        $bars = PriceBar::query()->where('instrument_id', $instrumentId)->orderBy('date')->get(['date', 'close'])
            ->map(fn (PriceBar $b): array => [Carbon::parse($b->date)->toDateString(), (float) $b->close])->all();
        $i = 0;
        $last = null;

        return function (string $date) use ($bars, &$i, &$last): ?float {
            while ($i < count($bars) && $bars[$i][0] <= $date) {
                $last = $bars[$i++][1];
            }

            return $last;
        };
    }
}
