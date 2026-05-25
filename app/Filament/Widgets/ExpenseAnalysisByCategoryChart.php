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
            'layout' => [
                'padding' => 8,
            ],
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                    'labels' => [
                        'usePointStyle' => true,
                        'padding' => 16,
                        'generateLabels' => RawJs::make($this->legendLabelsScript()),
                    ],
                ],
                'tooltip' => [
                    'callbacks' => [
                        'label' => RawJs::make(<<<'JS'
function (context) {
    const value = new Intl.NumberFormat('es-ES', {
        style: 'currency',
        currency: 'EUR',
    }).format(context.parsed);
    const total = context.dataset.data.reduce((sum, item) => sum + item, 0);
    const percentage = total > 0 ? ((context.parsed / total) * 100).toFixed(1).replace('.', ',') : '0,0';

    return `${context.label}: ${value} (${percentage} %)`;
}
JS),
                    ],
                ],
            ],
        ];
    }

    private function legendLabelsScript(): string
    {
        return <<<'JS'
function (chart) {
    const dataset = chart.data.datasets[0];
    const total = dataset.data.reduce((sum, item) => sum + item, 0);
    const formatter = new Intl.NumberFormat('es-ES', {
        style: 'currency',
        currency: 'EUR',
    });

    return chart.data.labels.map((label, index) => {
        const value = Number(dataset.data[index] ?? 0);
        const percentage = total > 0 ? ((value / total) * 100).toFixed(1).replace('.', ',') : '0,0';
        const backgroundColor = Array.isArray(dataset.backgroundColor)
            ? dataset.backgroundColor[index]
            : dataset.backgroundColor;

        return {
            text: `${label}: ${formatter.format(value)} (${percentage} %)`,
            fillStyle: backgroundColor,
            strokeStyle: backgroundColor,
            lineWidth: 0,
            hidden: !chart.getDataVisibility(index),
            index,
            pointStyle: 'circle',
        };
    });
}
JS;
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
