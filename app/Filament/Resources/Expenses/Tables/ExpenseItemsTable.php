<?php

namespace App\Filament\Resources\Expenses\Tables;

use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Kirschbaum\Commentions\Filament\Actions\CommentsAction;

class ExpenseItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID'),
                TextColumn::make('category.name')
                    ->label('Categoría')
                    ->searchable(),
                TextColumn::make('concept')
                    ->label('Concepto')
                    ->searchable(),
                TextColumn::make('quantity')
                    ->label('Cantidad')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('unit_price')
                    ->label('Precio Unitario')
                    ->local_money()
                    ->sortable(),
                IconColumn::make('is_consumable')
                    ->label('¿Consumible?')
                    ->boolean(),
                TextColumn::make('end_date')
                    ->label('Fecha Fin')
                    ->date()
                    ->sortable(),
                TextColumn::make('projected_start_date')
                    ->label('Inicio Proyectado')
                    ->date()
                    ->sortable(),
                TextColumn::make('actual_start_date')
                    ->label('Inicio Real')
                    ->date()
                    ->sortable(),
                TextColumn::make('item_type')
                    ->label('Clasificación')
                    ->badge(),
                TextColumn::make('recurrence')
                    ->label('Recurrencia')
                    ->badge(),
                TextColumn::make('line_total')
                    ->label('Total de Línea')
                    ->local_money()
                    ->sortable(),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('item_type')
                    ->options(\App\Enums\ExpenseItemType::class)
                    ->label('Tipo'),
                \Filament\Tables\Filters\SelectFilter::make('recurrence')
                    ->options(\App\Enums\Recurrence::class)
                    ->label('Recurrencia'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->recordActions([
                CommentsAction::make()
                    ->mentionables(fn (Model $record) => User::query()->get()),
            ]);
    }
}
