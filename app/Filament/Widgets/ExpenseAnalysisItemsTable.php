<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\ExpenseItem;
use App\Services\ExpenseAnalyticsService;
use Filament\Actions\Action;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

class ExpenseAnalysisItemsTable extends TableWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'Tabla de análisis';

    protected int | string | array $columnSpan = 'full';

    protected function getTableQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return app(ExpenseAnalyticsService::class)->tableQuery($this->pageFilters ?? []);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('expense_date', 'desc')
            ->columns([
                TextColumn::make('expense_date')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('Fecha')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('establishment_name')
                    ->label('Establecimiento'),
                TextColumn::make('effective_category_name')
                    ->label('Categoría efectiva'),
                TextColumn::make('concept')
                    ->label('Concepto')
                    ->wrap(),
                TextColumn::make('quantity')
                    ->label('Cantidad')
                    ->numeric(decimalPlaces: 3)
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                TextColumn::make('unit_price')
                    ->label('Precio unitario')
                    ->local_money()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                TextColumn::make('line_total')
                    ->label('Total línea')
                    ->local_money()
                    ->summarize(Sum::make())
                    ->sortable()
            ])
            ->recordActions([
                Action::make('openExpense')
                    ->label('Ver gasto')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (ExpenseItem $record): string => ExpenseResource::getUrl('view', ['record' => $record->expense_id]))
                    ->openUrlInNewTab(),
            ])
            ->emptyStateHeading('No hay subgastos para estos filtros')
            ->emptyStateDescription('Prueba a ampliar el rango de fechas o quitar la categoría seleccionada.');
    }
}
