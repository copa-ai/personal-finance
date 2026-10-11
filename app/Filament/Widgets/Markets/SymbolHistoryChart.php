<?php

namespace App\Filament\Widgets\Markets;

use App\Services\MarketData\MarketDataClient;
use App\Services\MarketData\MarketDataUnavailable;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class SymbolHistoryChart extends ChartWidget
{
    public string $symbol;

    protected ?string $heading = 'Histórico';

    protected int | string | array $columnSpan = 'full';

    protected ?string $maxHeight = '340px';

    public ?string $filter = '1y';

    private const RANGES = [
        '5d' => ['5 días', '1h'],
        '1mo' => ['1 mes', '1d'],
        '6mo' => ['6 meses', '1d'],
        '1y' => ['1 año', '1d'],
        '5y' => ['5 años', '1wk'],
        'max' => ['Todo', '1mo'],
    ];

    protected function getFilters(): ?array
    {
        return collect(self::RANGES)->map(fn (array $r): string => $r[0])->all();
    }

    public function getDescription(): ?string
    {
        $history = $this->history();

        return $history['error'] ?? (isset($history['source']) ? "Fuente: {$history['source']} · {$history['currency']}" : null);
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $candles = collect($this->history()['candles'] ?? []);
        $intraday = in_array($this->filter, ['5d'], true);
        $up = $candles->isNotEmpty() && $candles->last()['c'] >= $candles->first()['c'];

        return [
            'datasets' => [[
                'label' => $this->symbol,
                'data' => $candles->pluck('c')->all(),
                'borderColor' => $up ? '#16a34a' : '#dc2626',
                'backgroundColor' => $up ? 'rgba(22, 163, 74, 0.10)' : 'rgba(220, 38, 38, 0.10)',
                'fill' => true,
                'tension' => 0.15,
                'pointRadius' => 0,
                'borderWidth' => 2,
            ]],
            'labels' => $candles->map(fn (array $c): string => Carbon::parse($c['t'])
                ->setTimezone(config('app.display_timezone'))
                ->format($intraday ? 'd/m H:i' : 'd/m/Y'))->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'interaction' => ['intersect' => false, 'mode' => 'index'],
            'scales' => ['x' => ['ticks' => ['maxTicksLimit' => 8]]],
        ];
    }

    private function history(): array
    {
        $range = array_key_exists((string) $this->filter, self::RANGES) ? $this->filter : '1y';
        $interval = self::RANGES[$range][1];

        return once(function () use ($range, $interval): array {
            try {
                return Cache::remember(
                    "market:history:{$this->symbol}:{$range}:{$interval}",
                    $interval === '1h' ? 600 : 3600,
                    fn (): array => app(MarketDataClient::class)->history($this->symbol, $range, $interval),
                );
            } catch (MarketDataUnavailable $e) {
                return ['candles' => [], 'error' => $e->getMessage()];
            }
        });
    }
}
