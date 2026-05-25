<?php

namespace App\Filament\Widgets;

use App\Services\ExpenseAnalyticsService;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class ExpenseAnalysisByCategoryChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Gasto por categoría efectiva';

    protected ?string $description = 'Cada subgasto se asigna a su propia categoría o, si no tiene, a la categoría del establecimiento.';

    protected string $color = 'success';

    protected ?string $maxHeight = '320px';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $rows = app(ExpenseAnalyticsService::class)->groupedByEffectiveCategory($this->pageFilters ?? []);

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
            '#16a34a',
            '#22c55e',
            '#34d399',
            '#06b6d4',
            '#0ea5e9',
            '#2563eb',
            '#4f46e5',
            '#8b5cf6',
            '#f59e0b',
            '#f97316',
            '#ef4444',
            '#ec4899',
        ];

        return array_slice(array_pad($colors, max($count, count($colors)), end($colors)), 0, $count);
    }
}
