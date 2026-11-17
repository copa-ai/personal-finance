<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Investments\AllocationChart;
use App\Filament\Widgets\Investments\AllocationTable;
use App\Filament\Widgets\Investments\EvolutionChart;
use App\Filament\Widgets\Investments\PendingReminders;
use App\Filament\Widgets\Investments\PlatformChart;
use App\Filament\Widgets\Investments\PortfolioStats;
use App\Filament\Widgets\Investments\PortfolioWarnings;
use App\Filament\Widgets\Investments\PositionsTable;
use Filament\Pages\Page;
use UnitEnum;

class Portfolio extends Page
{
    protected static ?string $slug = 'cartera';

    protected static ?string $title = 'Cartera';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-briefcase';

    protected static string|UnitEnum|null $navigationGroup = 'Inversiones';

    protected static ?int $navigationSort = 1;

    public function getSubheading(): ?string
    {
        return 'Calculada a partir de tus movimientos. Coste fiscal por FIFO, valores en euros.';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            PortfolioWarnings::class,
            PortfolioStats::class,
            PendingReminders::class,
            AllocationChart::class,
            PlatformChart::class,
            EvolutionChart::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 2;
    }

    protected function getFooterWidgets(): array
    {
        return [
            PositionsTable::class,
            AllocationTable::class,
        ];
    }
}
