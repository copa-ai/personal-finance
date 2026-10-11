<?php

namespace App\Filament\Widgets;

use App\Models\Expense;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ExpensesOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Total Gastos Pagados', '€' . number_format(
                Expense::query()
                    ->where('status', 'PAID')
                    // Las aportaciones a plataformas de inversión no son gasto.
                    ->whereDoesntHave('establishment', fn ($query) => $query->whereNotNull('investment_platform_id'))
                    ->sum('total'),
                2,
            ))
                ->description('Monto total en gastos')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),
        ];
    }
}
