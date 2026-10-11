<?php

namespace App\Filament\Widgets\Investments;

use App\Filament\Widgets\Concerns\HasDoughnutChartOptions;
use App\Services\Portfolio\PortfolioSummary;
use Filament\Widgets\ChartWidget;

class PlatformChart extends ChartWidget
{
    use HasDoughnutChartOptions;

    protected ?string $heading = 'Reparto por plataforma';

    protected ?string $maxHeight = '300px';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $rows = collect(PortfolioSummary::current()['positions'])
            ->groupBy('platform')
            ->map(fn ($positions, string $platform): array => [
                'label' => $platform,
                'total' => round($positions->sum(fn (array $p): float => (float) $p['value_eur']), 2),
            ])
            ->sortByDesc('total')
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
