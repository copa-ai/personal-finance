<?php

namespace App\Filament\Widgets\Investments;

use App\Services\Portfolio\PortfolioService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TaxTotals extends StatsOverviewWidget
{
    public int $year;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $totals = app(PortfolioService::class)->taxYear($this->year)['totals'];

        return [
            Stat::make('Ganancias', PortfolioStats::eur($totals['gains_eur']))->color('success'),
            Stat::make('Pérdidas', PortfolioStats::eur($totals['losses_eur']))->color('danger'),
            Stat::make('Saldo de ganancias y pérdidas', PortfolioStats::eur($totals['net_eur']))
                ->description('Transmisiones de elementos patrimoniales'),
            Stat::make('Rendimientos (dividendos, intereses, staking)', PortfolioStats::eur($totals['income_eur']))
                ->description('Rendimientos del capital mobiliario, en bruto menos comisiones'),
        ];
    }
}
