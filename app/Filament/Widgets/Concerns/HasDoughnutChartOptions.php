<?php

namespace App\Filament\Widgets\Concerns;

use Filament\Support\RawJs;

trait HasDoughnutChartOptions
{
    protected function buildDoughnutOptions(): array
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
                    ],
                ],
                'tooltip' => [
                    'callbacks' => [
                        'label' => RawJs::make(<<<'JS'
function (context) {
    return context.label;
}
JS),
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<int, array{label: string, total: float}>  $rows
     * @return array<int, string>
     */
    protected function buildFormattedLabels(array $rows): array
    {
        $total = array_reduce(
            $rows,
            fn (float $carry, array $row): float => $carry + (float) $row['total'],
            0.0
        );

        return array_map(
            fn (array $row): string => sprintf(
                '%s: %s (%s %%)',
                $row['label'],
                $this->formatEur((float) $row['total']),
                $this->formatPercentage($total > 0 ? ((float) $row['total'] / $total) * 100 : 0.0)
            ),
            $rows
        );
    }

    protected function formatEur(float $value): string
    {
        return number_format($value, 2, ',', '.') . ' €';
    }

    protected function formatPercentage(float $value): string
    {
        return number_format($value, 1, ',', '.');
    }

    /**
     * @return array<int, string>
     */
    protected function palette(int $count): array
    {
        $colors = $this->paletteColors();

        return array_slice(array_pad($colors, max($count, count($colors)), end($colors)), 0, $count);
    }

    /**
     * @return array<int, string>
     */
    protected function paletteColors(): array
    {
        return [
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
    }
}
