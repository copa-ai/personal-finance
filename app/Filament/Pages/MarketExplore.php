<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Exploración libre de cualquier mercado con los widgets de TradingView.
 * No hay API de datos de TradingView: estos widgets son su forma pública de mostrarlos.
 */
class MarketExplore extends Page
{
    protected static ?string $slug = 'mercados/explorar';

    protected static ?string $title = 'Explorar mercados';

    protected static ?string $navigationLabel = 'Explorar';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static string|UnitEnum|null $navigationGroup = 'Mercados';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.market-explore';

    #[Url]
    public string $tv = 'SP:SPX';

    public function getSubheading(): ?string
    {
        return 'Gráficos y datos de TradingView: índices, acciones, ETFs, divisas, materias primas, bonos y cripto de todo el mundo.';
    }

    public function updatedTv(): void
    {
        // Solo caracteres de un símbolo de TradingView ("EXCHANGE:TICKER").
        $this->tv = preg_replace('/[^A-Za-z0-9:._!\-]/', '', strtoupper($this->tv)) ?: 'SP:SPX';
        $this->redirect(static::getUrl(['tv' => $this->tv]));
    }

    public static function marketOverviewTabs(): array
    {
        $tab = fn (string $title, array $symbols): array => [
            'title' => $title,
            'symbols' => array_map(fn (array $s): array => ['s' => $s[0], 'd' => $s[1]], $symbols),
        ];

        return [
            $tab('Índices', [['SP:SPX', 'S&P 500'], ['NASDAQ:IXIC', 'Nasdaq'], ['DJ:DJI', 'Dow Jones'], ['TVC:SX5E', 'Euro Stoxx 50'], ['BME:IBC', 'IBEX 35'], ['XETR:DAX', 'DAX'], ['TVC:UKX', 'FTSE 100'], ['TVC:NI225', 'Nikkei 225']]),
            $tab('Cripto', [['BITSTAMP:BTCUSD', 'Bitcoin'], ['BITSTAMP:ETHUSD', 'Ethereum'], ['BINANCE:SOLUSDT', 'Solana'], ['CRYPTOCAP:TOTAL', 'Capitalización total'], ['CRYPTOCAP:BTC.D', 'Dominancia BTC']]),
            $tab('Divisas', [['FX_IDC:EURUSD', 'EUR/USD'], ['FX_IDC:EURGBP', 'EUR/GBP'], ['FX_IDC:EURCHF', 'EUR/CHF'], ['FX_IDC:USDJPY', 'USD/JPY'], ['TVC:DXY', 'Índice dólar']]),
            $tab('Materias primas', [['TVC:GOLD', 'Oro'], ['TVC:SILVER', 'Plata'], ['TVC:USOIL', 'Petróleo WTI'], ['TVC:UKOIL', 'Brent'], ['TVC:NATURALGAS' , 'Gas natural']]),
            $tab('Bonos', [['TVC:US10Y', 'EE. UU. 10 años'], ['TVC:US02Y', 'EE. UU. 2 años'], ['TVC:DE10Y', 'Alemania 10 años'], ['TVC:ES10Y', 'España 10 años'], ['TVC:IT10Y', 'Italia 10 años']]),
            $tab('ETFs', [['AMEX:SPY', 'SPDR S&P 500'], ['AMEX:VT', 'Vanguard Total World'], ['NASDAQ:QQQ', 'Invesco QQQ'], ['AMEX:GLD', 'SPDR Gold'], ['AMEX:TLT', 'iShares 20+ Year Treasury']]),
        ];
    }
}
