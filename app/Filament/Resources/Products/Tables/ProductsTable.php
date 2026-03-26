<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Kirschbaum\Commentions\Filament\Actions\CommentsAction;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID'),
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable(),
                TextColumn::make('brand')
                    ->label('Marca')
                    ->searchable(),
                TextColumn::make('variant')
                    ->label('Variante')
                    ->searchable(),
                TextColumn::make('category.name')
                    ->label('Categoría')
                    ->searchable(),
                TextColumn::make('need.name')
                    ->label('Necesidad')
                    ->searchable(),
                TextColumn::make('unit_of_measure')
                    ->label('Unidad de Medida')
                    ->searchable(),
                IconColumn::make('is_consumable')
                    ->label('¿Consumible?')
                    ->boolean(),
                TextColumn::make('current_quantity')
                    ->label('Cantidad Actual')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('daily_consumption_rate')
                    ->label('Consumo Diario')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('target_price')
                    ->label('Precio Objetivo')
                    ->local_money()
                    ->sortable(),
                IconColumn::make('active')
                    ->label('¿Activo?')
                    ->headerTooltip('Activa o desactiva el producto. Si está inactivo, seguirá guardado para el historial, pero podrás filtrarlo y evitar usarlo en nuevos registros.')
                    ->boolean(),
                TextColumn::make('last_updated_at')
                    ->label('Última Actualización')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Creado el')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('category_id')
                    ->relationship('category', 'name')
                    ->label('Categoría'),
                \Filament\Tables\Filters\SelectFilter::make('need_id')
                    ->relationship('need', 'name')
                    ->label('Necesidad'),
                \Filament\Tables\Filters\TernaryFilter::make('is_consumable')
                    ->label('¿Es Consumible?'),
                \Filament\Tables\Filters\TernaryFilter::make('active')
                    ->label('¿Activo?'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                CommentsAction::make()
                    ->mentionables(fn (Model $record) => User::query()->get()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
