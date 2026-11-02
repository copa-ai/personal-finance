<?php

namespace App\Services\Demo;

use App\Models\Asset;
use App\Models\CryptoAccount;
use App\Models\ValueSnapshot;
use App\Services\Market\FxService;
use Illuminate\Support\Carbon;

class RealPortfolioService
{
    public function __construct(private readonly FxService $fx) {}

    /**
     * Curva del patrimonio real: suma, por fecha, del último snapshot conocido de cada
     * Asset y CryptoAccount convertido a EUR (con la divisa de ese día), arrastrando hacia
     * delante el valor de cada elemento entre snapshots.
     *
     * Devuelve un punto por cada fecha con algún snapshot dentro del rango, más un punto en
     * $from (si ya había valor) y otro en $to (si se indican). Un elemento aporta 0 antes
     * de su primer snapshot.
     *
     * @return list<array{date: string, value: float}>
     */
    public function curve(Carbon|string|null $from = null, Carbon|string|null $to = null): array
    {
        $from = $from !== null ? Carbon::parse($from)->toDateString() : null;
        $to = $to !== null ? Carbon::parse($to)->toDateString() : null;

        $snapshots = ValueSnapshot::query()
            ->whereIn('valuable_type', [Asset::class, CryptoAccount::class])
            ->when($to, fn ($q) => $q->where('date', '<=', $to))
            ->orderBy('date')
            ->orderBy('created_at')
            ->get();

        if ($snapshots->isEmpty()) {
            return [];
        }

        $dates = $snapshots->map(fn (ValueSnapshot $s) => $s->date->toDateString())->unique()->values();
        $byDate = $snapshots->groupBy(fn (ValueSnapshot $s) => $s->date->toDateString());

        /** @var array<string, float> $current valor en EUR vigente por elemento */
        $current = [];
        $series = [];
        $rates = [];

        foreach ($dates as $date) {
            foreach ($byDate[$date] as $snapshot) {
                $rate = $rates[$snapshot->currency.'|'.$date] ??= $this->fx->toEur((string) $snapshot->currency, $date);
                $current[$snapshot->valuable_type.'|'.$snapshot->valuable_id] = (float) $snapshot->value * $rate;
            }
            $series[$date] = round(array_sum($current), 2);
        }

        $result = [];
        $lastBefore = null;

        foreach ($series as $date => $value) {
            if ($from !== null && $date < $from) {
                $lastBefore = $value;

                continue;
            }
            $result[$date] = $value;
        }

        if ($from !== null && $lastBefore !== null && ! isset($result[$from])) {
            $result[$from] = $lastBefore;
        }

        if ($to !== null && ! isset($result[$to])) {
            // Último valor conocido en o antes de $to ($series ya está acotada por $to).
            $result[$to] = end($series);
        }

        ksort($result);

        return array_map(
            fn (string $date, float $value) => ['date' => $date, 'value' => $value],
            array_keys($result),
            array_values($result),
        );
    }
}
