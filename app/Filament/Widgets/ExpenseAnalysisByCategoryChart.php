<?php

namespace App\Filament\Widgets;

use App\Services\ExpenseAnalyticsService;
use App\Filament\Widgets\Concerns\HasDoughnutChartOptions;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class ExpenseAnalysisByCategoryChart extends ChartWidget
{
    use HasDoughnutChartOptions;
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
        $items = $rows->map(fn ($row): array => [
            'label' => $row['label'],
            'total' => round((float) $row['total'], 2),
        ])->all();

        return [
            'datasets' => [
                [
                    'label' => 'Importe',
                    'data' => array_column($items, 'total'),
                    'backgroundColor' => $this->palette($rows->count()),
                    'borderColor' => '#ffffff',
                    'borderWidth' => 2,
                ],
            ],
            'labels' => $this->buildFormattedLabels($items),
        ];
    }

    protected function getOptions(): array
    {
        return $this->buildDoughnutOptions();
    }

    /**
     * @return array<int, string>
     */
    protected function paletteColors(): array
    {
        return [
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
    }
}
