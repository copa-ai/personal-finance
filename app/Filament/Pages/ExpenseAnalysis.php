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

    /**
     * Título dinámico de la página basado en los filtros aplicados
     */
    public function getTitle(): string | Htmlable
    {
        $categoryName = $this->getFilterCategoryName();

        if ($categoryName) {
            return "Análisis de gastos: {$categoryName}";
        }

        return 'Análisis de gastos general';
    }

    /**
     * Descripción / Subtítulo dinámico con el rango de fechas
     */
    public function getSubheading(): ?string
    {
        $startDate = $this->filters['startDate'] ?? null;
        $endDate = $this->filters['endDate'] ?? null;

        if ($startDate && $endDate) {
            return "Mostrando datos desde el {$startDate} hasta el {$endDate}";
        }

        if ($startDate) {
            return "Mostrando datos desde el {$startDate}";
        }

        if ($endDate) {
            return "Mostrando datos hasta el {$endDate}";
        }

        return 'Mostrando el histórico completo de gastos';
    }

    /**
     * Helper para obtener el nombre de la categoría seleccionada
     */
    protected function getFilterCategoryName(): ?string
    {
        $categoryId = $this->filters['categoryId'] ?? null;

        if (! $categoryId) {
            return null;
        }

        // Recuperamos las opciones del servicio para buscar el nombre comercial/legible
        $categories = app(ExpenseAnalyticsService::class)->categoryOptions();

        return $categories[$categoryId] ?? null;
    }

    public function persistsFiltersInSession(): bool
    {
        return true;
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
