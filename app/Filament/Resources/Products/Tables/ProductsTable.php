<?php

namespace App\Filament\Resources\Products\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID'),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('brand')
                    ->searchable(),
                TextColumn::make('variant')
                    ->searchable(),
                TextColumn::make('category.name')
                    ->searchable(),
                TextColumn::make('unit_of_measure')
                    ->searchable(),
                IconColumn::make('is_consumable')
                    ->boolean(),
                TextColumn::make('current_quantity')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('daily_consumption_rate')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('target_price')
                    ->local_money()
                    ->sortable(),
                IconColumn::make('active')
                    ->boolean(),
                TextColumn::make('last_updated_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('category_id')
                    ->relationship('category', 'name')
                    ->label('Necesidad'),
                \Filament\Tables\Filters\TernaryFilter::make('is_consumable')
                    ->label('¿Es Consumible?'),
                \Filament\Tables\Filters\TernaryFilter::make('active')
                    ->label('¿Activo?'),
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
