<?php

namespace App\Filament\Resources\PriceAlerts\Tables;

use App\Enums\PriceAlertCondition;
use App\Filament\Resources\InvestmentTransactions\Tables\InvestmentTransactionsTable;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PriceAlertsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('market_symbol')
            ->columns([
                TextColumn::make('market_symbol')
                    ->label('Símbolo')
                    ->fontFamily('mono')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('condition')
                    ->label('Condición')
                    ->badge(),
                TextColumn::make('threshold')
                    ->label('Umbral')
                    ->formatStateUsing(fn (?string $state, $record): string => InvestmentTransactionsTable::trimDecimals($state)
                        . ($record->condition === PriceAlertCondition::CHANGE_PCT ? ' %' : ''))
                    ->alignEnd(),
                TextColumn::make('last_price')
                    ->label('Último precio')
                    ->formatStateUsing(fn (?string $state): string => InvestmentTransactionsTable::trimDecimals($state))
                    ->placeholder('—')
                    ->alignEnd(),
                TextColumn::make('last_triggered_at')
                    ->label('Último aviso')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Nunca')
                    ->sortable(),
                ToggleColumn::make('active')
                    ->label('Activa'),
            ])
            ->filters([
                SelectFilter::make('condition')
                    ->label('Condición')
                    ->options(PriceAlertCondition::class),
            ])
            ->recordActions([
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
