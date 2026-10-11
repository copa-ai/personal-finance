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
use App\Services\Investments\MarketPriceUpdater;
use App\Services\MarketData\MarketDataClient;
use App\Services\MarketData\MarketDataUnavailable;
use App\Services\Portfolio\PortfolioService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshPrices')
                ->label('Actualizar precios')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => app(MarketDataClient::class)->enabled())
                ->action(function (): void {
                    try {
                        $updater = app(MarketPriceUpdater::class);
                        $fx = $updater->refreshFx();
                        $result = $updater->refreshPrices();
                        app(PortfolioService::class)->takeSnapshot();

                        Notification::make()
                            ->success()
                            ->title('Precios actualizados')
                            ->body(sprintf('%d precios y %d tipos de cambio.%s', $result['updated'], $fx, $result['errors'] ? ' Sin datos: ' . implode('; ', $result['errors']) : ''))
                            ->send();
                    } catch (MarketDataUnavailable $e) {
                        Notification::make()->danger()->title('Sin datos de mercado')->body($e->getMessage())->send();
                    }

                    $this->redirect(static::getUrl());
                }),
        ];
    }
}
