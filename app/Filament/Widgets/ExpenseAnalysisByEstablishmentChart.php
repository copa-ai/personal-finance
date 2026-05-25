<?php

namespace App\Filament\Widgets;

use App\Services\ExpenseAnalyticsService;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class ExpenseAnalysisByEstablishmentChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Gasto por establecimiento';

    protected ?string $description = 'Compara dónde se concentra el importe de los subgastos.';

    protected string $color = 'primary';

    protected ?string $maxHeight = '320px';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $rows = app(ExpenseAnalyticsService::class)->groupedByEstablishment($this->pageFilters ?? []);

        return [
            'datasets' => [
                [
                    'label' => 'Importe',
                    'data' => $rows->pluck('total')->map(fn (float $value): float => round($value, 2))->all(),
                    'backgroundColor' => $this->palette($rows->count()),
                    'borderColor' => '#ffffff',
                    'borderWidth' => 2,
                ],
            ],
            'labels' => $rows->pluck('label')->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'maintainAspectRatio' => false,
            'cutout' => '60%',
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                ],
                'tooltip' => [
                    'callbacks' => [
                        'label' => RawJs::make(<<<'JS'
function (context) {
    const value = new Intl.NumberFormat('es-ES', {
        style: 'currency',
        currency: 'EUR',
    }).format(context.parsed);

    return `${context.label}: ${value}`;
}
JS),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function palette(int $count): array
    {
        $colors = [
            '#0f766e',
            '#14b8a6',
            '#06b6d4',
            '#0ea5e9',
            '#3b82f6',
            '#6366f1',
            '#8b5cf6',
            '#a855f7',
            '#f97316',
            '#f59e0b',
            '#84cc16',
            '#22c55e',
        ];

        return array_slice(array_pad($colors, max($count, count($colors)), end($colors)), 0, $count);
    }
}
