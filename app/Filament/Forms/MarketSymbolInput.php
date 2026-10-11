<?php

namespace App\Filament\Forms;

use App\Services\MarketData\MarketDataClient;
use App\Services\MarketData\MarketDataUnavailable;
use App\Services\MarketData\MarketSymbol;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Campo de símbolo de mercado (formato de pf-market-data) con buscador.
 */
class MarketSymbolInput
{
    public static function make(string $name = 'market_symbol', bool $required = false): TextInput
    {
        return TextInput::make($name)
            ->label('Símbolo de mercado')
            ->helperText('Formato tipo:código, p. ej. crypto:BTC-EUR, etf:IWDA.AS, index:^GSPC, fx:EURUSD. Usa "Buscar" para encontrarlo.')
            ->maxLength(60)
            ->regex(MarketSymbol::PATTERN)
            ->validationMessages(['regex' => 'Formato no válido: usa tipo:código (crypto, index, etf, stock, fx, commodity, bond, fund).'])
            ->required($required)
            ->suffixAction(self::searchAction($name));
    }

    public static function searchAction(string $name): Action
    {
        return Action::make('searchMarketSymbol')
            ->label('Buscar')
            ->icon('heroicon-o-magnifying-glass')
            ->modalHeading('Buscar símbolo de mercado')
            ->modalSubmitActionLabel('Usar')
            ->schema([
                Select::make('symbol')
                    ->label('Símbolo')
                    ->placeholder('Escribe un nombre o ticker: Apple, MSCI World, bitcoin…')
                    ->searchable()
                    ->required()
                    ->getSearchResultsUsing(fn (string $search): array => self::search($search))
                    ->getOptionLabelUsing(fn (?string $value): ?string => $value),
            ])
            ->action(function (array $data, Set $set) use ($name): void {
                $set($name, $data['symbol']);
            });
    }

    /**
     * @return array<string, string>
     */
    public static function search(string $search): array
    {
        $client = app(MarketDataClient::class);

        if (! $client->enabled()) {
            Notification::make()
                ->warning()
                ->title('Buscador no disponible')
                ->body('El servicio de datos de mercado no está configurado (MARKET_DATA_URL).')
                ->send();

            return [];
        }

        try {
            return collect($client->search($search))
                ->filter(fn (array $result): bool => MarketSymbol::isValid($result['symbol'] ?? null))
                ->mapWithKeys(fn (array $result): array => [
                    $result['symbol'] => sprintf(
                        '%s — %s%s',
                        $result['symbol'],
                        $result['name'] ?? '',
                        filled($result['exchange'] ?? null) ? " ({$result['exchange']})" : '',
                    ),
                ])
                ->all();
        } catch (MarketDataUnavailable $e) {
            Notification::make()
                ->danger()
                ->title('No se pudo buscar')
                ->body($e->getMessage())
                ->send();

            return [];
        }
    }
}
