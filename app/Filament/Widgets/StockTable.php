<?php

namespace App\Filament\Widgets;

use App\Services\StockService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class StockTable extends TableWidget
{
    protected static ?string $heading = 'Stock de productos activos';

    protected int | string | array $columnSpan = 'full';

    protected function getTableQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return app(StockService::class)->query();
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('remaining_quantity', 'asc')
            ->columns([
                TextColumn::make('concept')
                    ->label('Producto')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category_name')
                    ->label('Categoría')
                    ->sortable(),
                TextColumn::make('remaining_quantity')
                    ->label('Queda')
                    ->numeric(decimalPlaces: 3)
                    ->sortable()
                    ->color(fn (mixed $state): string => match (true) {
                        (float) $state <= 0 => 'danger',
                        (float) $state <= 1 => 'warning',
                        default => 'success',
                    })
                    ->weight('bold'),
                TextColumn::make('purchased_quantity')
                    ->label('Comprado (total)')
                    ->numeric(decimalPlaces: 3)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                TextColumn::make('consumed_quantity')
                    ->label('Consumido / perdido (total)')
                    ->numeric(decimalPlaces: 3)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                TextColumn::make('last_movement_at')
                    ->label('Último movimiento')
                    ->date()
                    ->sortable(),
            ])
            ->emptyStateHeading('No hay productos consumibles registrados')
            ->emptyStateDescription('El stock se calcula a partir de los subgastos marcados como "Consumible".');
    }
}
