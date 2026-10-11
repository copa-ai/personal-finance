@php
    $fmt = function ($value, $decimals = null) {
        if (! is_numeric($value)) {
            return '—';
        }
        $decimals ??= abs($value) >= 1000 ? 2 : (abs($value) >= 1 ? 2 : 6);
        return number_format((float) $value, $decimals, ',', '.');
    };
@endphp

<x-filament-panels::page>
    <style>
        .pf-quotes { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .pf-quotes th { text-align: start; font-weight: 500; color: var(--gray-500); padding: 0.5rem 0.75rem; border-bottom: 1px solid var(--gray-200); white-space: nowrap; }
        .pf-quotes td { padding: 0.6rem 0.75rem; border-bottom: 1px solid var(--gray-100); vertical-align: middle; }
        .dark .pf-quotes th { border-color: var(--gray-700); }
        .dark .pf-quotes td { border-color: var(--gray-800); }
        .pf-quotes .num { text-align: end; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .pf-quotes .up { color: var(--success-600); }
        .pf-quotes .down { color: var(--danger-600); }
        .pf-quotes .muted { color: var(--gray-500); font-size: 0.75rem; }
        .pf-quotes a { font-weight: 600; }
        .pf-quotes-wrap { overflow-x: auto; }
    </style>

    @if ($data['error'])
        <x-filament::callout icon="heroicon-o-signal-slash" color="warning" heading="Sin datos de mercado" :description="$data['error']" />
    @endif

    @forelse ($watchlists as $watchlist)
        <x-filament::section :heading="$watchlist->name" collapsible>
            <x-slot name="afterHeader">
                {{ ($this->deleteWatchlistAction)(['watchlist' => $watchlist->id]) }}
            </x-slot>

            <div class="pf-quotes-wrap">
                <table class="pf-quotes">
                    <thead>
                        <tr>
                            <th>Símbolo</th>
                            <th class="num">Precio</th>
                            <th class="num">Variación</th>
                            <th class="num">%</th>
                            <th>Fuente</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($watchlist->items as $item)
                            @php
                                $q = $data['quotes'][$item->market_symbol] ?? null;
                                $error = $data['errors'][$item->market_symbol] ?? null;
                                $pct = $q['change_pct'] ?? null;
                                $class = is_numeric($pct) ? ($pct >= 0 ? 'up' : 'down') : '';
                            @endphp
                            <tr wire:key="item-{{ $item->id }}">
                                <td>
                                    <a href="{{ \App\Filament\Pages\MarketSymbolPage::getUrl(['s' => $item->market_symbol]) }}" wire:navigate>
                                        {{ $item->label ?: ($q['name'] ?? $item->market_symbol) }}
                                    </a>
                                    <div class="muted">{{ $item->market_symbol }}</div>
                                </td>
                                <td class="num">
                                    @if ($q)
                                        <strong>{{ $fmt($q['price']) }}</strong> <span class="muted">{{ $q['currency'] }}</span>
                                    @elseif ($error)
                                        <span class="muted">{{ $error }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="num {{ $class }}">{{ is_numeric($q['change'] ?? null) ? ($q['change'] > 0 ? '+' : '') . $fmt($q['change']) : '—' }}</td>
                                <td class="num {{ $class }}">{{ is_numeric($pct) ? ($pct > 0 ? '+' : '') . $fmt($pct, 2) . ' %' : '—' }}</td>
                                <td>
                                    @if ($q)
                                        <span class="muted">{{ $q['source'] }}</span>
                                        @if ($q['stale'] ?? false)
                                            <x-filament::badge color="warning" size="sm">sin actualizar</x-filament::badge>
                                        @endif
                                    @endif
                                </td>
                                <td class="num">{{ ($this->removeItemAction)(['item' => $item->id]) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="muted">Lista vacía. Usa "Añadir símbolo".</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @empty
        <x-filament::empty-state icon="heroicon-o-queue-list" heading="Sin listas de seguimiento" description="Crea una con &quot;Nueva lista&quot;." />
    @endforelse

</x-filament-panels::page>
