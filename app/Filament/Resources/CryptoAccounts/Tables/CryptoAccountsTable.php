<?php

namespace App\Filament\Resources\CryptoAccounts\Tables;

use App\Enums\CryptoTaxStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CryptoAccountsTable
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
                TextColumn::make('currency')
                    ->label('Criptomoneda')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('amount_held')
                    ->label('Cantidad')
                    ->sortable(),
                TextColumn::make('current_value_eur')
                    ->label('Valor estimado')
                    ->money('EUR')
                    ->sortable(),
                TextColumn::make('tax_status')
                    ->label('Estado fiscal')
                    ->badge()
                    ->color(fn (CryptoTaxStatus $state): string => match ($state) {
                        CryptoTaxStatus::DETECTED => 'success',
                        CryptoTaxStatus::NOT_DETECTED => 'danger',
                        CryptoTaxStatus::UNKNOWN => 'gray',
                    }),
                TextColumn::make('wallet_reference')
                    ->label('Wallet / exchange')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Creada el')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('tax_status')
                    ->label('Estado fiscal')
                    ->options(CryptoTaxStatus::class),
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
