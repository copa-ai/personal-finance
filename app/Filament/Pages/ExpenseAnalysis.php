<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ExpenseAnalysisByCategoryChart;
use App\Filament\Widgets\ExpenseAnalysisByEstablishmentChart;
use App\Filament\Widgets\ExpenseAnalysisItemsTable;
use App\Filament\Widgets\ExpenseAnalysisOverview;
use App\Services\ExpenseAnalyticsService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use BackedEnum;
use UnitEnum;

class ExpenseAnalysis extends Dashboard
{
    use HasFiltersForm;

    protected bool $persistsFiltersInSession = false;

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

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Filtros')
                    ->description('La categoría se aplica a la categoría efectiva del subgasto.')
                    ->columnSpanFull()
                    ->schema([
                        DatePicker::make('startDate')
                            ->label('Desde')
                            ->default(now()->startOfMonth()->toDateString()),
                        DatePicker::make('endDate')
                            ->label('Hasta')
                            ->default(now()->toDateString()),
                        Select::make('categoryId')
                            ->label('Categoría')
                            ->placeholder('Todas')
                            ->searchable()
                            ->preload()
                            ->options(fn (): array => app(ExpenseAnalyticsService::class)->categoryOptions()),
                    ])
                    ->columns([
                        'md' => 3,
                        'xl' => 3,
                    ])
                    ->contained(false),
            ]);
    }
}
