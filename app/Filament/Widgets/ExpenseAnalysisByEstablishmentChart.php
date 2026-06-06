<?php

namespace App\Filament\Widgets;

use App\Services\ExpenseAnalyticsService;
use App\Filament\Widgets\Concerns\HasDoughnutChartOptions;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class ExpenseAnalysisByEstablishmentChart extends ChartWidget
{
    use HasDoughnutChartOptions;
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
}
