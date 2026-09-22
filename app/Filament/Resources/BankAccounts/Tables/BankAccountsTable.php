<?php

namespace App\Filament\Resources\BankAccounts\Tables;

use App\Models\BankAccount;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BankAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('bank_name')
                    ->label('Entidad bancaria')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('iban')
                    ->label('IBAN')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('assets_count')
                    ->label('Activos')
                    ->counts('assets'),
                TextColumn::make('total_value')
                    ->label('Valor total')
                    ->getStateUsing(fn (BankAccount $record): string => $record->total_value)
                    ->money('EUR'),
                TextColumn::make('currency')
                    ->label('Moneda')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Creada el')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
