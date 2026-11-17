<?php

namespace App\Filament\Widgets\Investments;

use App\Services\Portfolio\PortfolioSummary;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PortfolioStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $s = PortfolioSummary::current();
        $unrealized = (float) $s['unrealized_eur'];
        $invested = (float) $s['invested_eur'];

        return [
            Stat::make('Valor de la cartera', self::eur($s['value_eur']))
                ->description('Aportado (coste): ' . self::eur($s['invested_eur']))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('primary'),
            Stat::make('Plusvalía latente', self::eur($s['unrealized_eur']))
                ->description($invested > 0 ? self::pct($unrealized / $invested * 100) . ' sobre lo aportado' : 'Sin posiciones')
                ->descriptionIcon($unrealized >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($unrealized >= 0 ? 'success' : 'danger'),
            Stat::make('Rentabilidad anual (XIRR)', $s['xirr'] === null ? '—' : self::pct($s['xirr'] * 100))
                ->description('Tiene en cuenta cuándo entra y sale cada euro')
                ->color($s['xirr'] === null ? 'gray' : ($s['xirr'] >= 0 ? 'success' : 'danger')),
            Stat::make('Ganancias realizadas', self::eur($s['realized_eur']))
                ->description('Ventas, por FIFO'),
            Stat::make('Dividendos, intereses y staking', self::eur($s['income_eur'])),
            Stat::make('Comisiones pagadas', self::eur($s['fees_eur']))
                ->color('gray'),
        ];
    }

    public static function eur(string|float|null $value): string
    {
        return number_format((float) $value, 2, ',', '.') . ' €';
    }

    public static function pct(float $value): string
    {
        return ($value > 0 ? '+' : '') . number_format($value, 2, ',', '.') . ' %';
    }
}
