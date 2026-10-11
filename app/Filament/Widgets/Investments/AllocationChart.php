<?php

namespace App\Filament\Widgets\Investments;

use App\Filament\Widgets\Concerns\HasDoughnutChartOptions;
use App\Services\Portfolio\PortfolioSummary;
use Filament\Widgets\ChartWidget;

class AllocationChart extends ChartWidget
{
    use HasDoughnutChartOptions;

    protected ?string $heading = 'Reparto por clase de activo';

    protected ?string $maxHeight = '300px';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $rows = collect(PortfolioSummary::current()['allocation'])
            ->filter(fn (array $row): bool => (float) $row['value_eur'] > 0)
            ->map(fn (array $row): array => ['label' => $row['asset_class']->getLabel(), 'total' => (float) $row['value_eur']])
            ->values()
            ->all();

        return [
            'datasets' => [[
                'data' => array_column($rows, 'total'),
                'backgroundColor' => $this->palette(count($rows)),
                'borderColor' => '#ffffff',
                'borderWidth' => 2,
            ]],
            'labels' => $this->buildFormattedLabels($rows),
        ];
    }

    protected function getOptions(): array
    {
        return $this->buildDoughnutOptions();
    }
}
