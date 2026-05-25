<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ExpenseAnalysisByCategoryChart;
use App\Filament\Widgets\ExpenseAnalysisByEstablishmentChart;
use App\Filament\Widgets\ExpenseAnalysisItemsTable;
use App\Filament\Widgets\ExpenseAnalysisOverview;
use App\Services\ExpenseAnalyticsService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Actions\FilterAction;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersAction;
use Filament\Schemas\Schema;
use BackedEnum;
use UnitEnum;

class ExpenseAnalysis extends Dashboard
{
    use HasFiltersAction;

    protected static string $routePath = '/analisis-gastos';

    protected static ?string $title = 'Análisis de gastos';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $navigationLabel = 'Análisis de gastos';

    protected static ?int $navigationSort = 2;

    public function getWidgets(): array
    {
        return [
            ExpenseAnalysisOverview::class,
            ExpenseAnalysisByCategoryChart::class,
            ExpenseAnalysisByEstablishmentChart::class,
            ExpenseAnalysisItemsTable::class,
        ];
    }

    public function persistsFiltersInSession(): bool
    {
        return false;
    }

    protected function getHeaderActions(): array
    {
        return [
            FilterAction::make()
                ->label('Aplicar filtros')
                ->schema($this->getFilterSchema()),
            Action::make('clearFilters')
                ->label('Limpiar filtros')
                ->color('gray')
                ->icon('heroicon-o-x-mark')
                ->action(function (): void {
                    $this->filters = [];
                }),
        ];
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    protected function getFilterSchema(): array
    {
        return [
            DatePicker::make('startDate')
                ->label('Desde'),
            DatePicker::make('endDate')
                ->label('Hasta'),
            Select::make('categoryId')
                ->label('Categoría')
                ->placeholder('Todas')
                ->searchable()
                ->preload()
                ->options(fn (): array => app(ExpenseAnalyticsService::class)->categoryOptions()),
        ];
    }
}
