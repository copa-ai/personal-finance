<?php

namespace App\Filament\Resources\ExpenseItems\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ExpenseItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID'),
                TextColumn::make('expense.id')
                    ->searchable(),
                TextColumn::make('product.name')
                    ->searchable(),
                TextColumn::make('concept')
                    ->searchable(),
                TextColumn::make('quantity')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('unit_price')
                    ->local_money()
                    ->sortable(),
                IconColumn::make('is_consumable')
                    ->boolean(),
                TextColumn::make('end_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('projected_start_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('actual_start_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('item_type')
                    ->badge(),
                TextColumn::make('recurrence')
                    ->badge(),
                TextColumn::make('line_total')
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
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
