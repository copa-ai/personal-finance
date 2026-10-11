<?php

namespace App\Filament\Resources\InvestmentTransactions\Tables;

use App\Enums\InvestmentTransactionType as T;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvestmentTransactionsTable
{
    public static function typeColor(?T $type): string
    {
        return match ($type) {
            T::BUY, T::TRANSFER_IN, T::STAKING_REWARD => 'success',
            T::SELL, T::TRANSFER_OUT => 'danger',
            T::DIVIDEND, T::INTEREST => 'info',
            T::FEE => 'warning',
            default => 'gray',
        };
    }

    public static function configure(Table $table, bool $includePlatformColumn = true, bool $includeInstrumentColumn = true): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns(array_values(array_filter([
                TextColumn::make('date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (?T $state): string => self::typeColor($state)),
                $includePlatformColumn ? TextColumn::make('platform.name')
                    ->label('Plataforma')
                    ->searchable()
                    ->sortable() : null,
                $includeInstrumentColumn ? TextColumn::make('instrument.name')
                    ->label('Instrumento')
                    ->searchable()
                    ->sortable() : null,
                TextColumn::make('quantity')
                    ->label('Cantidad')
                    ->formatStateUsing(fn (?string $state): string => self::trimDecimals($state))
                    ->alignEnd(),
                TextColumn::make('price')
                    ->label('Precio')
                    ->formatStateUsing(fn (?string $state): string => self::trimDecimals($state))
                    ->alignEnd(),
                TextColumn::make('amount')
                    ->label('Importe')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),
                TextColumn::make('fees')
                    ->label('Comisiones')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('currency')
                    ->label('Divisa')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('fx_rate_eur')
                    ->label('Tipo de cambio')
                    ->formatStateUsing(fn (?string $state): string => self::trimDecimals($state))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('notes')
                    ->label('Notas')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])))
            ->filters(array_values(array_filter([
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(T::class)
                    ->multiple(),
                $includePlatformColumn ? SelectFilter::make('platform_id')
                    ->label('Plataforma')
                    ->relationship('platform', 'name')
                    ->preload() : null,
                $includeInstrumentColumn ? SelectFilter::make('instrument_id')
                    ->label('Instrumento')
                    ->relationship('instrument', 'name')
                    ->searchable()
                    ->preload() : null,
                Filter::make('date')
                    ->label('Fecha')
                    ->schema([
                        DatePicker::make('from')->label('Desde'),
                        DatePicker::make('until')->label('Hasta'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->where('date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->where('date', '<=', $date))),
            ])))
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Sin movimientos')
            ->emptyStateDescription('Apunta compras, ventas, dividendos… o un "Ajuste de saldo" para empezar con lo que tienes hoy.');
    }

    /**
     * 0.5000000000 -> 0,5 ; 1200.0000000000 -> 1.200
     */
    public static function trimDecimals(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        [$int, $dec] = array_pad(explode('.', $value), 2, '');
        $dec = rtrim($dec, '0');
        $sign = str_starts_with($int, '-') ? '-' : '';
        $int = number_format((float) ltrim($int, '-'), 0, ',', '.');

        return $sign . $int . ($dec !== '' ? ',' . $dec : '');
    }
}
