<?php

namespace App\Filament\Pages\Concerns;

use App\Services\MarketData\MarketDataClient;
use App\Services\MarketData\MarketDataUnavailable;
use App\Services\MarketData\MarketSymbol;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

trait SearchesMarketSymbols
{
    /**
     * Buscador de símbolos de pf-market-data más un campo libre para escribirlo a mano.
     *
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    protected function marketSymbolFields(): array
    {
        return [
            Select::make('found_symbol')
                ->label('Buscar')
                ->helperText('Nombre, ticker o ISIN: "apple", "msci world", "bitcoin", "ibex"…')
                ->searchable()
                ->live()
                ->getSearchResultsUsing(function (string $search): array {
                    try {
                        return collect(app(MarketDataClient::class)->search($search))
                            ->filter(fn (array $r): bool => MarketSymbol::isValid($r['symbol'] ?? null))
                            ->mapWithKeys(fn (array $r): array => [
                                $r['symbol'] => sprintf('%s — %s%s', $r['name'], $r['symbol'], filled($r['exchange'] ?? null) ? " ({$r['exchange']})" : ''),
                            ])
                            ->all();
                    } catch (MarketDataUnavailable) {
                        return [];
                    }
                })
                ->getOptionLabelUsing(fn (?string $value): ?string => $value)
                ->visible(fn (): bool => app(MarketDataClient::class)->enabled()),
            TextInput::make('manual_symbol')
                ->label('O escríbelo')
                ->placeholder('index:^GSPC, etf:IWDA.AS, crypto:BTC-EUR, fx:EURUSD, commodity:GC=F')
                ->regex(MarketSymbol::PATTERN)
                ->validationMessages(['regex' => 'Formato: tipo:código, por ejemplo stock:AAPL.'])
                ->required(fn (Get $get): bool => blank($get('found_symbol'))),
        ];
    }

    protected function pickedSymbol(array $data): string
    {
        $symbol = trim((string) ($data['found_symbol'] ?? '')) ?: trim((string) $data['manual_symbol']);

        return MarketSymbol::normalize($symbol) ?? $symbol;
    }
}
