<x-filament-panels::page>
    <x-filament::section heading="Gráfico de TradingView" collapsible>
        @if ($tradingView)
            <x-tradingview-widget widget="advanced-chart" :height="520" :config="[
                'symbol' => $tradingView,
                'interval' => 'D',
                'timezone' => config('app.display_timezone'),
                'allow_symbol_change' => true,
                'support_host' => 'https://www.tradingview.com',
            ]" />
        @else
            <p style="color: var(--gray-500);">
                No hay una equivalencia segura de este símbolo en TradingView.
                Búscalo en <a href="{{ \App\Filament\Pages\MarketExplore::getUrl() }}" style="text-decoration: underline;">Explorar</a>.
            </p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
