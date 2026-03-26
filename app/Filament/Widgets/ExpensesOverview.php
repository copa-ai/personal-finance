<?php

namespace App\Filament\Widgets;

use App\Models\Expense;
use App\Models\Need;
use App\Models\Product;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ExpensesOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Total Gastos Pagados', '€' . number_format(Expense::where('status', 'PAID')->sum('total'), 2))
                ->description('Monto total en gastos')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),
            Stat::make('Total Productos', Product::count())
                ->description('Productos registrados')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('info'),
            Stat::make('Necesidades', Need::count())
                ->description('Clasificaciones activas')
                ->descriptionIcon('heroicon-m-tag')
                ->color('primary'),
        ];
    }
}
