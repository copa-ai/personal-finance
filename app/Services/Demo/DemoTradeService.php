<?php

namespace App\Services\Demo;

use App\Enums\TradeSide;
use App\Models\DemoPortfolio;
use App\Models\DemoTrade;
use App\Models\Instrument;
use App\Services\Market\FxService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoTradeService
{
    private const EPSILON = 1e-6;

    public function __construct(private readonly FxService $fx) {}

    /**
     * Calcula precio, tipo de cambio, comisión y total sin guardar nada.
     *
     * @return array{date: string, side: string, units: float, price: float, fx_rate: float, gross: float, fee: float, total_points: float}
     *
     * @throws DemoTradeException si no hay precio o tipo de cambio.
     */
    public function preview(DemoPortfolio $portfolio, Instrument $instrument, TradeSide $side, Carbon|string $date, float $units): array
    {
        $date = Carbon::parse($date)->toDateString();

        if ($units <= 0) {
            throw new DemoTradeException('Las unidades deben ser mayores que cero.');
        }

        $bar = $instrument->priceBars()->where('date', '<=', $date)->orderByDesc('date')->first();

        if ($bar === null || (float) $bar->close <= 0) {
            throw new DemoTradeException("No hay cotización de {$instrument->symbol} anterior o igual a {$date}.");
        }

        try {
            $fxRate = $this->fx->toEur((string) $instrument->currency, $date);
        } catch (RuntimeException $e) {
            throw new DemoTradeException($e->getMessage(), 0, $e);
        }

        $price = (float) $bar->close;
        $gross = $units * $price * $fxRate;
        $fee = (float) $portfolio->fee_fixed + $gross * (float) $portfolio->fee_percent / 100;
        $total = $side === TradeSide::BUY ? $gross + $fee : $gross - $fee;

        if ($total <= 0) {
            throw new DemoTradeException('La comisión iguala o supera el importe de la operación.');
        }

        return [
            'date' => $date,
            'side' => $side->value,
            'units' => $units,
            'price' => $price,
            'fx_rate' => $fxRate,
            'gross' => round($gross, 8),
            'fee' => round($fee, 8),
            'total_points' => round($total, 8),
        ];
    }

    /**
     * Ejecuta la operación al cierre del día (o el último anterior) y la guarda.
     *
     * @throws DemoTradeException si rompe la línea temporal (caja o unidades negativas, fecha anterior al inicio).
     */
    public function execute(DemoPortfolio $portfolio, Instrument $instrument, TradeSide $side, Carbon|string $date, float $units): DemoTrade
    {
        return DB::transaction(function () use ($portfolio, $instrument, $side, $date, $units): DemoTrade {
            // Bloquea la cartera para serializar operaciones concurrentes.
            DemoPortfolio::query()->whereKey($portfolio->getKey())->lockForUpdate()->first();

            $preview = $this->preview($portfolio, $instrument, $side, $date, $units);

            $this->assertTimeline($portfolio, [[
                'instrument_id' => $instrument->id,
                'symbol' => $instrument->symbol,
                'side' => $side,
                'date' => $preview['date'],
                'units' => $units,
                'total_points' => $preview['total_points'],
            ]]);

            return $portfolio->trades()->create([
                'instrument_id' => $instrument->id,
                'side' => $side,
                'date' => $preview['date'],
                'units' => $units,
                'price' => $preview['price'],
                'fx_rate' => $preview['fx_rate'],
                'fee' => $preview['fee'],
                'total_points' => $preview['total_points'],
            ]);
        });
    }

    /**
     * Elimina una operación comprobando que el resto de la línea temporal sigue siendo válida.
     *
     * @throws DemoTradeException
     */
    public function delete(DemoTrade $trade): void
    {
        DB::transaction(function () use ($trade): void {
            $portfolio = $trade->portfolio;
            $this->assertTimeline($portfolio, [], excluding: $trade->id);
            $trade->delete();
        });
    }

    /**
     * Reproduce cronológicamente las operaciones existentes (más las nuevas, que van las últimas de su día)
     * y falla si la caja o las unidades quedan en negativo en algún momento.
     *
     * @param  array<int, array{instrument_id: string, symbol: string, side: TradeSide, date: string, units: float, total_points: float}>  $extra
     *
     * @throws DemoTradeException
     */
    public function assertTimeline(DemoPortfolio $portfolio, array $extra = [], ?string $excluding = null): void
    {
        $start = $portfolio->start_date->toDateString();
        $events = [];

        $existing = $portfolio->trades()->with('instrument:id,symbol')->orderBy('date')->orderBy('created_at')->get();

        foreach ($existing as $i => $t) {
            if ($t->id === $excluding) {
                continue;
            }
            $events[] = [
                'date' => $t->date->toDateString(), 'order' => $i,
                'instrument_id' => $t->instrument_id, 'symbol' => $t->instrument?->symbol ?? $t->instrument_id,
                'side' => $t->side, 'units' => (float) $t->units, 'total_points' => (float) $t->total_points,
            ];
        }

        foreach ($extra as $j => $e) {
            if ($e['date'] < $start) {
                throw new DemoTradeException("La operación no puede ser anterior al inicio de la cartera ({$start}).");
            }
            $events[] = $e + ['order' => PHP_INT_MAX - count($extra) + $j];
        }

        usort($events, fn (array $a, array $b): int => [$a['date'], $a['order']] <=> [$b['date'], $b['order']]);

        $cash = (float) $portfolio->initial_points;
        $holdings = [];

        foreach ($events as $e) {
            $held = $holdings[$e['instrument_id']] ?? 0.0;

            if ($e['side'] === TradeSide::BUY) {
                $cash -= $e['total_points'];
                $held += $e['units'];
            } else {
                $cash += $e['total_points'];
                $held -= $e['units'];
            }

            if ($held < -self::EPSILON) {
                throw new DemoTradeException("No tienes unidades suficientes de {$e['symbol']} el {$e['date']}.");
            }

            if ($cash < -self::EPSILON) {
                throw new DemoTradeException("Puntos insuficientes el {$e['date']}: la caja quedaría en ".number_format($cash, 2, ',', '.').' puntos.');
            }

            $holdings[$e['instrument_id']] = $held;
        }
    }
}
