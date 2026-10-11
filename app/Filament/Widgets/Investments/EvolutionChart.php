<?php

namespace App\Filament\Widgets\Investments;

use App\Models\PortfolioSnapshot;
use App\Services\Portfolio\PortfolioSummary;
use Filament\Widgets\ChartWidget;

class EvolutionChart extends ChartWidget
{
    protected ?string $heading = 'Evolución';

    protected ?string $description = 'Valor de la cartera frente a lo aportado (una foto al día).';

    protected int | string | array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    public ?string $filter = '1y';

    protected function getFilters(): ?array
    {
        return ['3m' => '3 meses', '1y' => '1 año', '5y' => '5 años', 'all' => 'Todo'];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $from = match ($this->filter) {
            '3m' => now()->subMonths(3),
            '5y' => now()->subYears(5),
            'all' => null,
            default => now()->subYear(),
        };

        $points = PortfolioSnapshot::query()
            ->when($from, fn ($q) => $q->where('date', '>=', $from->toDateString()))
            ->orderBy('date')
            ->get(['date', 'value_eur', 'invested_eur'])
            ->map(fn (PortfolioSnapshot $s): array => [
                'date' => $s->date->toDateString(),
                'value' => (float) $s->value_eur,
                'invested' => (float) $s->invested_eur,
            ])
            ->keyBy('date');

        // El punto de hoy siempre al día, aunque la foto nocturna aún no se haya hecho.
        $today = PortfolioSummary::current();
        $points->put($today['as_of']->toDateString(), [
            'date' => $today['as_of']->toDateString(),
            'value' => (float) $today['value_eur'],
            'invested' => (float) $today['invested_eur'],
        ]);
        $points = $points->sortKeys()->values();

        return [
            'datasets' => [
                [
                    'label' => 'Valor',
                    'data' => $points->pluck('value')->all(),
                    'borderColor' => '#16a34a',
                    'backgroundColor' => 'rgba(22, 163, 74, 0.12)',
                    'fill' => true,
                    'tension' => 0.2,
                    'pointRadius' => 0,
                ],
                [
                    'label' => 'Aportado',
                    'data' => $points->pluck('invested')->all(),
                    'borderColor' => '#64748b',
                    'borderDash' => [6, 4],
                    'fill' => false,
                    'tension' => 0,
                    'pointRadius' => 0,
                ],
            ],
            'labels' => $points->pluck('date')->all(),
        ];
    }
}
