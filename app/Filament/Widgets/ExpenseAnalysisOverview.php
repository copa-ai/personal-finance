<?php

namespace App\Filament\Widgets;

use App\Services\ExpenseAnalyticsService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ExpenseAnalysisOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected int | string | array $columnSpan = 'full';

    protected function getStats(): array
    {
        $filters = $this->pageFilters ?? [];
        $service = app(ExpenseAnalyticsService::class);

        $filteredTotal = $service->totalForFilters($filters);
        $rangeTotal = $service->totalForDateRange($filters);
        $selectedCategory = $service->selectedCategoryLabel($filters);

        $stats = [
            Stat::make('Gasto dentro del filtro', $this->formatCurrency($filteredTotal))
                ->description('Suma total de los subgastos visibles en el análisis.')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),
            Stat::make('Total del rango de fechas', $this->formatCurrency($rangeTotal))
                ->description('Importe total antes de aplicar el filtro de categoría.')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('info'),
        ];

        if ($selectedCategory !== null) {
            $share = $rangeTotal > 0 ? ($filteredTotal / $rangeTotal) * 100 : 0;

            $stats[] = Stat::make('Peso de la categoría', $this->formatPercentage($share))
                ->description($selectedCategory)
                ->descriptionIcon('heroicon-m-chart-pie')
                ->color('warning');
        }

        return $stats;
    }

    protected function getColumns(): int
    {
        return 3;
    }

    private function formatCurrency(float $value): string
    {
        return number_format($value, 2, ',', '.') . ' EUR';
    }

    private function formatPercentage(float $value): string
    {
        return number_format($value, 1, ',', '.') . ' %';
    }
}
