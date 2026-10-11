<?php

namespace App\Services\Portfolio;

use App\Enums\AssetClass;
use App\Enums\InvestmentTransactionType as T;
use App\Models\Instrument;
use App\Models\InstrumentPrice;
use App\Models\InvestmentTransaction;
use App\Models\PortfolioSnapshot;
use App\Models\TargetAllocation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Calcula la cartera a partir del libro de movimientos.
 *
 * - Coste fiscal por FIFO (criterio de Hacienda para valores homogéneos), por
 *   plataforma e instrumento. Los traspasos entre plataformas conservan el coste.
 * - Todo el dinero en EUR con el tipo de cambio guardado en cada movimiento.
 * - Los precios vienen de instrument_prices (manual manda sobre mercado); sin
 *   precio, la posición se valora a coste y se marca.
 */
class PortfolioService
{
    public function __construct(private FxConverter $fx)
    {
    }

    /**
     * @return array{
     *     positions: array<int, array<string, mixed>>,
     *     realized: array<int, array<string, mixed>>,
     *     income: array<int, array<string, mixed>>,
     *     cashflows: array<int, array{date: CarbonImmutable, amount: string}>,
     *     fees_eur: string,
     *     warnings: array<int, string>,
     * }
     */
    public function ledger(?CarbonInterface $asOf = null): array
    {
        $asOf = CarbonImmutable::parse(($asOf ?? now(config('app.display_timezone')))->toDateString());

        $transactions = InvestmentTransaction::query()
            ->with(['instrument', 'platform'])
            ->where('date', '<=', $asOf->toDateString())
            ->orderBy('date')
            ->orderBy('created_at')
            ->get();

        /** @var array<string, array{lots: array<int, array{qty: string, unit_cost: string, date: CarbonImmutable}>, platform: mixed, instrument: Instrument}> $positions */
        $positions = [];
        /** @var array<string, array<int, array{qty: string, unit_cost: string, date: CarbonImmutable}>> $inTransit */
        $inTransit = [];
        $realized = [];
        $income = [];
        $cashflows = [];
        $fees = '0';
        $warnings = [];

        foreach ($transactions as $tx) {
            $key = $tx->platform_id . '|' . $tx->instrument_id;
            $positions[$key] ??= ['lots' => [], 'platform' => $tx->platform, 'instrument' => $tx->instrument];
            $date = CarbonImmutable::parse($tx->date->toDateString());
            $fx = Dec::of($tx->fx_rate_eur);
            $qty = Dec::of($tx->quantity);
            $price = Dec::of($tx->price);
            $txFeesEur = Dec::mul(Dec::of($tx->fees), $fx);
            $fees = Dec::add($fees, $txFeesEur);

            switch ($tx->type) {
                case T::BUY:
                    $cost = Dec::add(Dec::mul(Dec::mul($qty, $price), $fx), $txFeesEur);
                    $this->addLot($positions[$key]['lots'], $qty, $cost, $date);
                    $cashflows[] = ['date' => $date, 'amount' => Dec::neg($cost)];
                    break;

                case T::TRANSFER_IN:
                    $pending = $inTransit[$tx->instrument_id] ?? [];
                    $carried = $this->takeLots($pending, $qty);
                    $inTransit[$tx->instrument_id] = $pending;

                    foreach ($carried['lots'] as $lot) {
                        $positions[$key]['lots'][] = $lot;
                    }

                    // Lo que no venía de otra plataforma propia entra desde fuera: es una aportación.
                    if (Dec::cmp($carried['missing'], '0') > 0) {
                        $cost = Dec::add(Dec::mul(Dec::mul($carried['missing'], $price), $fx), $txFeesEur);
                        $this->addLot($positions[$key]['lots'], $carried['missing'], $cost, $date);
                        $cashflows[] = ['date' => $date, 'amount' => Dec::neg($cost)];
                    } elseif (! Dec::isZero($txFeesEur)) {
                        $cashflows[] = ['date' => $date, 'amount' => Dec::neg($txFeesEur)];
                    }
                    break;

                case T::TRANSFER_OUT:
                    $taken = $this->takeLots($positions[$key]['lots'], $qty);
                    $this->warnIfMissing($warnings, $taken['missing'], $tx);
                    $inTransit[$tx->instrument_id] = array_merge($inTransit[$tx->instrument_id] ?? [], $taken['lots']);
                    if (! Dec::isZero($txFeesEur)) {
                        $cashflows[] = ['date' => $date, 'amount' => Dec::neg($txFeesEur)];
                    }
                    break;

                case T::STAKING_REWARD:
                    // Rendimiento: entra con su valor de mercado como coste y tributa como ingreso.
                    $value = Dec::mul(Dec::mul($qty, $price), $fx);
                    $this->addLot($positions[$key]['lots'], $qty, $value, $date);
                    $income[] = $this->incomeRow($tx, $date, $value);
                    break;

                case T::ADJUSTMENT:
                    if (Dec::cmp($qty, '0') >= 0) {
                        $cost = Dec::mul(Dec::mul($qty, $price), $fx);
                        $this->addLot($positions[$key]['lots'], $qty, $cost, $date);
                        $cashflows[] = ['date' => $date, 'amount' => Dec::neg($cost)];
                    } else {
                        $taken = $this->takeLots($positions[$key]['lots'], Dec::abs($qty));
                        $this->warnIfMissing($warnings, $taken['missing'], $tx);
                        $cashflows[] = ['date' => $date, 'amount' => $this->lotsCost($taken['lots'])];
                    }
                    break;

                case T::SELL:
                    $taken = $this->takeLots($positions[$key]['lots'], $qty);
                    $this->warnIfMissing($warnings, $taken['missing'], $tx);
                    $proceeds = Dec::sub(Dec::mul(Dec::mul($qty, $price), $fx), $txFeesEur);
                    $cost = $this->lotsCost($taken['lots']);
                    $realized[] = [
                        'transaction_id' => $tx->id,
                        'date' => $date,
                        'platform' => $tx->platform?->name,
                        'instrument' => $tx->instrument?->name,
                        'instrument_id' => $tx->instrument_id,
                        'isin' => $tx->instrument?->isin,
                        'quantity' => $qty,
                        'proceeds_eur' => Dec::round($proceeds),
                        'cost_eur' => Dec::round($cost),
                        'gain_eur' => Dec::round(Dec::sub($proceeds, $cost)),
                        'acquired_from' => collect($taken['lots'])->min('date'),
                        'two_month_rule' => false,
                    ];
                    $cashflows[] = ['date' => $date, 'amount' => $proceeds];
                    break;

                case T::SPLIT:
                    if (Dec::cmp($qty, '0') <= 0) {
                        $warnings[] = "Split sin factor válido el {$date->toDateString()} en {$tx->instrument?->name}.";
                        break;
                    }
                    foreach ($positions[$key]['lots'] as &$lot) {
                        $lot['qty'] = Dec::mul($lot['qty'], $qty);
                        $lot['unit_cost'] = Dec::div($lot['unit_cost'], $qty);
                    }
                    unset($lot);
                    break;

                case T::DIVIDEND:
                case T::INTEREST:
                    $net = Dec::sub(Dec::mul(Dec::of($tx->amount), $fx), $txFeesEur);
                    $income[] = $this->incomeRow($tx, $date, $net);
                    $cashflows[] = ['date' => $date, 'amount' => $net];
                    break;

                case T::FEE:
                    $fee = Dec::mul(Dec::of($tx->amount), $fx);
                    $fees = Dec::add($fees, $fee);
                    $cashflows[] = ['date' => $date, 'amount' => Dec::neg(Dec::add($fee, $txFeesEur))];
                    break;
            }
        }

        $this->flagTwoMonthRule($realized, $transactions);

        return [
            'positions' => $this->valuePositions($positions, $asOf, $warnings),
            'realized' => $realized,
            'income' => $income,
            'cashflows' => $cashflows,
            'fees_eur' => Dec::round($fees),
            'warnings' => $warnings,
        ];
    }

    /**
     * Resumen para el panel.
     *
     * @return array<string, mixed>
     */
    public function summary(?CarbonInterface $asOf = null): array
    {
        $asOf = CarbonImmutable::parse(($asOf ?? now(config('app.display_timezone')))->toDateString());
        $ledger = $this->ledger($asOf);
        $positions = collect($ledger['positions']);

        $value = $positions->reduce(fn (string $c, array $p): string => Dec::add($c, $p['value_eur'] ?? '0'), '0');
        $invested = $positions->reduce(fn (string $c, array $p): string => Dec::add($c, $p['cost_eur']), '0');

        $flows = $ledger['cashflows'];
        $flows[] = ['date' => $asOf, 'amount' => $value];

        return [
            'as_of' => $asOf,
            'value_eur' => Dec::round($value),
            'invested_eur' => Dec::round($invested),
            'unrealized_eur' => Dec::round(Dec::sub($value, $invested)),
            'realized_eur' => Dec::round(collect($ledger['realized'])->reduce(fn (string $c, array $r): string => Dec::add($c, $r['gain_eur']), '0')),
            'income_eur' => Dec::round(collect($ledger['income'])->reduce(fn (string $c, array $r): string => Dec::add($c, $r['amount_eur']), '0')),
            'fees_eur' => $ledger['fees_eur'],
            'xirr' => Xirr::calculate($flows),
            'positions' => $ledger['positions'],
            'allocation' => $this->allocation($positions, $value),
            'warnings' => $ledger['warnings'],
        ];
    }

    /**
     * Valor por clase de activo frente al objetivo.
     *
     * @return array<int, array{asset_class: AssetClass, value_eur: string, pct: float, target_pct: float|null, drift_pp: float|null}>
     */
    public function allocation(Collection $positions, string $total): array
    {
        $targets = TargetAllocation::query()->get()->keyBy(fn (TargetAllocation $t) => $t->asset_class->value);
        $byClass = $positions->groupBy(fn (array $p) => $p['asset_class']->value);
        $classes = $byClass->keys()->merge($targets->keys())->unique();

        return $classes->map(function (string $class) use ($byClass, $targets, $total): array {
            $value = collect($byClass->get($class, []))->reduce(fn (string $c, array $p): string => Dec::add($c, $p['value_eur'] ?? '0'), '0');
            $pct = Dec::isZero($total) ? 0.0 : (float) Dec::mul(Dec::div($value, $total), '100');
            $target = $targets->get($class)?->target_pct;

            return [
                'asset_class' => AssetClass::from($class),
                'value_eur' => Dec::round($value),
                'pct' => round($pct, 2),
                'target_pct' => $target === null ? null : (float) $target,
                'drift_pp' => $target === null ? null : round($pct - (float) $target, 2),
            ];
        })->sortByDesc('value_eur')->values()->all();
    }

    /**
     * Ganancias y pérdidas realizadas de un año, para la declaración de la Renta.
     *
     * @return array{sales: array<int, array<string, mixed>>, income: array<int, array<string, mixed>>, totals: array<string, string>}
     */
    public function taxYear(int $year): array
    {
        $ledger = $this->ledger(CarbonImmutable::create($year, 12, 31));
        $inYear = fn (array $row): bool => $row['date']->year === $year;

        $sales = array_values(array_filter($ledger['realized'], $inYear));
        $income = array_values(array_filter($ledger['income'], $inYear));

        $gains = '0';
        $losses = '0';
        foreach ($sales as $sale) {
            if (Dec::cmp($sale['gain_eur'], '0') >= 0) {
                $gains = Dec::add($gains, $sale['gain_eur']);
            } else {
                $losses = Dec::add($losses, $sale['gain_eur']);
            }
        }

        return [
            'sales' => $sales,
            'income' => $income,
            'totals' => [
                'gains_eur' => Dec::round($gains),
                'losses_eur' => Dec::round($losses),
                'net_eur' => Dec::round(Dec::add($gains, $losses)),
                'income_eur' => Dec::round(collect($income)->reduce(fn (string $c, array $r): string => Dec::add($c, $r['amount_eur']), '0')),
            ],
        ];
    }

    public function takeSnapshot(?CarbonInterface $date = null): PortfolioSnapshot
    {
        $summary = $this->summary($date);

        return PortfolioSnapshot::query()->updateOrCreate(
            ['date' => $summary['as_of']->toDateString()],
            [
                'value_eur' => $summary['value_eur'],
                'invested_eur' => $summary['invested_eur'],
                'by_asset_class' => collect($summary['allocation'])
                    ->mapWithKeys(fn (array $row): array => [$row['asset_class']->value => $row['value_eur']])
                    ->all(),
            ],
        );
    }

    /**
     * @param  array<int, array{qty: string, unit_cost: string, date: CarbonImmutable}>  $lots
     */
    private function addLot(array &$lots, string $qty, string $totalCost, CarbonImmutable $date): void
    {
        if (Dec::cmp($qty, '0') <= 0) {
            return;
        }

        $lots[] = ['qty' => $qty, 'unit_cost' => Dec::div($totalCost, $qty), 'date' => $date];
    }

    /**
     * Saca unidades por FIFO.
     *
     * @param  array<int, array{qty: string, unit_cost: string, date: CarbonImmutable}>  $lots
     * @return array{lots: array<int, array{qty: string, unit_cost: string, date: CarbonImmutable}>, missing: string}
     */
    private function takeLots(array &$lots, string $qty): array
    {
        $taken = [];
        $remaining = $qty;

        while (Dec::cmp($remaining, '0') > 0 && $lots !== []) {
            $lot = &$lots[0];
            $used = Dec::min($lot['qty'], $remaining);
            $taken[] = ['qty' => $used, 'unit_cost' => $lot['unit_cost'], 'date' => $lot['date']];
            $lot['qty'] = Dec::sub($lot['qty'], $used);
            $remaining = Dec::sub($remaining, $used);

            if (Dec::isZero($lot['qty'])) {
                array_shift($lots);
            }
            unset($lot);
        }

        return ['lots' => $taken, 'missing' => $remaining];
    }

    /**
     * @param  array<int, array{qty: string, unit_cost: string, date: CarbonImmutable}>  $lots
     */
    private function lotsCost(array $lots): string
    {
        return array_reduce($lots, fn (string $c, array $lot): string => Dec::add($c, Dec::mul($lot['qty'], $lot['unit_cost'])), '0');
    }

    private function warnIfMissing(array &$warnings, string $missing, InvestmentTransaction $tx): void
    {
        if (Dec::cmp($missing, '0') > 0) {
            $warnings[] = sprintf(
                'El %s se sacan %s unidades de %s en %s sin saldo suficiente: falta apuntar alguna compra o un ajuste.',
                $tx->date->toDateString(),
                rtrim(rtrim($missing, '0'), '.'),
                $tx->instrument?->name,
                $tx->platform?->name,
            );
        }
    }

    private function incomeRow(InvestmentTransaction $tx, CarbonImmutable $date, string $amountEur): array
    {
        return [
            'transaction_id' => $tx->id,
            'date' => $date,
            'type' => $tx->type,
            'platform' => $tx->platform?->name,
            'instrument' => $tx->instrument?->name,
            'amount_eur' => Dec::round($amountEur),
        ];
    }

    /**
     * Regla de los dos meses (art. 33.5 LIRPF): una pérdida no se puede compensar si se
     * recompran valores homogéneos dentro de los dos meses anteriores o posteriores.
     * Solo se avisa; el cálculo de la pérdida diferida queda para el asesor.
     */
    private function flagTwoMonthRule(array &$realized, Collection $transactions): void
    {
        foreach ($realized as &$sale) {
            if (Dec::cmp($sale['gain_eur'], '0') >= 0) {
                continue;
            }

            $from = $sale['date']->subMonthsNoOverflow(2);
            $to = $sale['date']->addMonthsNoOverflow(2);

            $sale['two_month_rule'] = $transactions->contains(
                fn (InvestmentTransaction $tx): bool => $tx->type === T::BUY
                    && $tx->instrument_id === $sale['instrument_id']
                    && $tx->date->betweenIncluded($from, $to)
            );
        }
        unset($sale);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function valuePositions(array $positions, CarbonImmutable $asOf, array &$warnings): array
    {
        $instrumentIds = collect($positions)->map(fn (array $p) => $p['instrument']->id)->unique()->values();
        $prices = InstrumentPrice::latestFor($instrumentIds);

        $rows = [];

        foreach ($positions as $position) {
            $qty = array_reduce($position['lots'], fn (string $c, array $lot): string => Dec::add($c, $lot['qty']), '0');

            if (Dec::isZero($qty)) {
                continue;
            }

            /** @var Instrument $instrument */
            $instrument = $position['instrument'];
            $cost = $this->lotsCost($position['lots']);
            $price = $prices->get($instrument->id);

            $valueEur = null;
            $priceAgeDays = null;

            if ($price !== null) {
                $valueEur = $this->fx->toEur(Dec::mul($qty, Dec::of($price->price)), $price->currency, $asOf);
                $priceAgeDays = (int) $price->date->diffInDays($asOf);

                if ($valueEur === null) {
                    $warnings[] = "Falta el tipo de cambio {$price->currency}/EUR para valorar {$instrument->name}.";
                }
            }

            $valuedAtCost = $price === null || $valueEur === null;
            $valueEur ??= $cost;

            $rows[] = [
                'platform_id' => $position['platform']->id,
                'platform' => $position['platform']->name,
                'instrument_id' => $instrument->id,
                'instrument' => $instrument->name,
                'kind' => $instrument->kind,
                'asset_class' => $instrument->asset_class,
                'market_symbol' => $instrument->market_symbol,
                'quantity' => $qty,
                'cost_eur' => Dec::round($cost),
                'avg_cost_eur' => Dec::round(Dec::div($cost, $qty), 4),
                'price' => $price?->price,
                'price_currency' => $price?->currency,
                'price_date' => $price?->date,
                'price_source' => $price?->source,
                'price_age_days' => $priceAgeDays,
                'valued_at_cost' => $valuedAtCost,
                'value_eur' => Dec::round($valueEur),
                'unrealized_eur' => Dec::round(Dec::sub($valueEur, $cost)),
                'unrealized_pct' => Dec::isZero($cost) ? null : round((float) Dec::mul(Dec::div(Dec::sub($valueEur, $cost), $cost), '100'), 2),
            ];
        }

        usort($rows, fn (array $a, array $b): int => Dec::cmp($b['value_eur'], $a['value_eur']));

        return $rows;
    }
}
