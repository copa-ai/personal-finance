@props(['widget', 'config' => [], 'height' => 500])
{{--
    Widget de TradingView. Lo carga el navegador de quien mira el panel, no el servidor:
    no usa la red del servicio. El script se inyecta con Alpine porque el panel navega
    en modo SPA y los <script> en línea no se ejecutan al cambiar de página.
--}}
<div
    wire:ignore
    style="height: {{ is_numeric($height) ? $height . 'px' : $height }}; width: 100%;"
    x-data
    x-init="
        const dark = document.documentElement.classList.contains('dark');
        const config = Object.assign({ colorTheme: dark ? 'dark' : 'light', isTransparent: true, locale: 'es', width: '100%', height: '100%' }, @js($config));
        const container = document.createElement('div');
        container.className = 'tradingview-widget-container__widget';
        container.style.height = '100%';
        $el.appendChild(container);
        const script = document.createElement('script');
        script.src = 'https://s3.tradingview.com/external-embedding/embed-widget-{{ $widget }}.js';
        script.async = true;
        script.innerHTML = JSON.stringify(config);
        $el.appendChild(script);
    "
    class="tradingview-widget-container"
></div>
