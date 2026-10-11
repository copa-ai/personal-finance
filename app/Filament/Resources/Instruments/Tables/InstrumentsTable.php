<?php

namespace App\Filament\Resources\Instruments\Tables;

use App\Enums\AssetClass;
use App\Enums\InstrumentKind;
use App\Filament\Resources\InvestmentTransactions\Tables\InvestmentTransactionsTable;
use App\Models\Instrument;
use App\Models\InstrumentPrice;
use App\Services\Investments\MarketPriceUpdater;
use App\Services\MarketData\MarketDataUnavailable;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InstrumentsTable
{
    /** @var \Illuminate\Support\Collection<string, InstrumentPrice>|null */
    private static $prices = null;

    public static function latestPrice(Instrument $instrument): ?InstrumentPrice
    {
        // Una sola consulta para todos los instrumentos de la página.
        self::$prices ??= InstrumentPrice::latestFor(Instrument::query()->pluck('id'));

        return self::$prices->get($instrument->id);
    }

    public static function refreshPricesAction(): Action
    {
        return Action::make('refreshPrices')
            ->label('Actualizar precios ahora')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->action(function (): void {
                try {
                    $result = app(MarketPriceUpdater::class)->refreshPrices();
                } catch (MarketDataUnavailable $e) {
                    Notification::make()->danger()->title('No se pudieron actualizar los precios')->body($e->getMessage())->send();

                    return;
                }

                self::$prices = null;

                Notification::make()
                    ->{$result['errors'] === [] ? 'success' : 'warning'}()
                    ->title(sprintf('Precios actualizados: %d', $result['updated']))
                    ->body($result['errors'] === [] ? null : implode("\n", $result['errors']))
                    ->send();
            });
    }

    public static function configure(Table $table): Table
    {
        self::$prices = null;

        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->description(fn (Instrument $record): ?string => $record->isin)
                    ->searchable(['name', 'isin', 'ticker'])
                    ->sortable(),
                TextColumn::make('kind')
                    ->label('Tipo')
                    ->badge()
                    ->sortable(),
                TextColumn::make('asset_class')
                    ->label('Clase')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('market_symbol')
                    ->label('Símbolo de mercado')
                    ->placeholder('Precio manual')
                    ->fontFamily('mono')
                    ->searchable(),
                TextColumn::make('latest_price')
                    ->label('Último precio')
                    ->state(function (Instrument $record): ?string {
                        $price = self::latestPrice($record);

                        return $price === null ? null : InvestmentTransactionsTable::trimDecimals($price->price) . ' ' . $price->currency;
                    })
                    ->placeholder('—')
                    ->alignEnd(),
                TextColumn::make('latest_price_date')
                    ->label('Fecha')
                    ->state(fn (Instrument $record) => self::latestPrice($record)?->date)
                    ->date('d/m/Y')
                    ->description(fn (Instrument $record): ?string => self::latestPrice($record)?->source)
                    ->placeholder('—'),
                TextColumn::make('maturity_date')
                    ->label('Vencimiento')
                    ->date('d/m/Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label('Tipo')
                    ->options(InstrumentKind::class),
                SelectFilter::make('asset_class')
                    ->label('Clase')
                    ->options(AssetClass::class),
                TernaryFilter::make('market_symbol')
                    ->label('Con símbolo de mercado')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('market_symbol'),
                        false: fn (Builder $query) => $query->whereNull('market_symbol'),
                    ),
            ])
            ->headerActions([
                self::refreshPricesAction(),
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
