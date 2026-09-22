<?php

namespace App\Filament\Resources\Assets\Tables;

use App\Enums\AssetType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AssetsTable
{
    public static function configure(Table $table, bool $includeBankAccountColumn = true): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('bankAccount.name')
                    ->label('Cuenta bancaria')
                    ->searchable()
                    ->sortable()
                    ->visible($includeBankAccountColumn),
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge(),
                TextColumn::make('current_value')
                    ->label('Valor actual')
                    ->money('EUR')
                    ->sortable(),
                TextColumn::make('documents_count')
                    ->label('Documentos')
                    ->counts('documents')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Creado el')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(AssetType::class),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
