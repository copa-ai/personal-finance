<x-filament-panels::page>
    <x-tradingview-widget widget="ticker-tape" :height="78" :config="[
        'symbols' => collect(\App\Filament\Pages\MarketExplore::marketOverviewTabs())
            ->flatMap(fn ($tab) => array_slice($tab['symbols'], 0, 3))
            ->map(fn ($s) => ['proName' => $s['s'], 'title' => $s['d']])
            ->values()->all(),
        'displayMode' => 'adaptive',
    ]" />

    <x-filament::section heading="Gráfico" description="Escribe cualquier símbolo de TradingView (por ejemplo NASDAQ:AAPL, BME:SAN, XETR:EUNL, BINANCE:ERGUSDT) o búscalo dentro del gráfico.">
        <div style="margin-bottom: 1rem; max-width: 24rem;">
            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                <x-filament::input type="text" wire:model.blur="tv" placeholder="EXCHANGE:TICKER" />
            </x-filament::input.wrapper>
        </div>

        <x-tradingview-widget widget="advanced-chart" :height="560" :config="[
            'symbol' => $tv,
            'interval' => 'D',
            'timezone' => config('app.display_timezone'),
            'style' => '1',
            'allow_symbol_change' => true,
            'details' => true,
            'calendar' => false,
            'support_host' => 'https://www.tradingview.com',
        ]" />
    </x-filament::section>

    <div style="display: grid; gap: 1.5rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 28rem), 1fr));">
        <x-filament::section heading="Panorama">
            <x-tradingview-widget widget="market-overview" :height="560" :config="[
                'tabs' => \App\Filament\Pages\MarketExplore::marketOverviewTabs(),
                'showChart' => true,
                'showSymbolLogo' => true,
            ]" />
        </x-filament::section>

        <x-filament::section heading="Mapa del mercado (S&P 500)">
            <x-tradingview-widget widget="stock-heatmap" :height="560" :config="[
                'dataSource' => 'SPX500',
                'grouping' => 'sector',
                'blockSize' => 'market_cap_basic',
                'blockColor' => 'change',
                'hasTopBar' => true,
            ]" />
        </x-filament::section>

        <x-filament::section heading="Cripto">
            <x-tradingview-widget widget="crypto-coins-heatmap" :height="520" :config="[
                'dataSource' => 'Crypto',
                'blockSize' => 'market_cap_calc',
                'blockColor' => 'change',
                'hasTopBar' => true,
            ]" />
        </x-filament::section>

        <x-filament::section heading="Divisas">
            <x-tradingview-widget widget="forex-cross-rates" :height="520" :config="[
                'currencies' => ['EUR', 'USD', 'GBP', 'CHF', 'JPY', 'CNY'],
            ]" />
        </x-filament::section>

        <x-filament::section heading="Calendario económico">
            <x-tradingview-widget widget="events" :height="520" :config="[
                'importanceFilter' => '0,1',
                'countryFilter' => 'us,eu,es,de,gb,cn,jp',
            ]" />
        </x-filament::section>

        <x-filament::section heading="Buscador de acciones">
            <x-tradingview-widget widget="screener" :height="520" :config="[
                'defaultColumn' => 'overview',
                'defaultScreen' => 'most_capitalized',
                'market' => 'europe',
                'showToolbar' => true,
            ]" />
        </x-filament::section>
    </div>
</x-filament-panels::page>
