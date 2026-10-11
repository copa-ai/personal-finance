@php
    $fmt = fn ($v, $d = 2) => is_numeric($v) ? number_format((float) $v, abs((float) $v) < 1 && $d === 2 ? 6 : $d, ',', '.') : '—';
    $pct = $quote['change_pct'] ?? null;
    $color = is_numeric($pct) ? ($pct >= 0 ? 'var(--success-600)' : 'var(--danger-600)') : 'inherit';
@endphp

<x-filament-widgets::widget>
    @if ($error && ! $quote)
        <x-filament::callout icon="heroicon-o-signal-slash" color="warning" heading="Sin cotización" :description="$error" />
    @endif

    @if ($quote)
        <x-filament::section>
            <div style="display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.5rem 2rem;">
                <div style="font-size: 2rem; font-weight: 700; font-variant-numeric: tabular-nums;">
                    {{ $fmt($quote['price']) }} <span style="font-size: 1rem; font-weight: 400; color: var(--gray-500);">{{ $quote['currency'] }}</span>
                </div>
                <div style="font-size: 1.125rem; font-weight: 600; color: {{ $color }}; font-variant-numeric: tabular-nums;">
                    {{ is_numeric($quote['change'] ?? null) ? (($quote['change'] > 0 ? '+' : '') . $fmt($quote['change'])) : '' }}
                    {{ is_numeric($pct) ? '(' . ($pct > 0 ? '+' : '') . $fmt($pct, 2) . ' %)' : '' }}
                </div>
                <div style="color: var(--gray-500); font-size: 0.875rem;">
                    Cierre anterior {{ $fmt($quote['previous_close'] ?? null) }} ·
                    {{ \Illuminate\Support\Carbon::parse($quote['as_of'])->setTimezone(config('app.display_timezone'))->format('d/m/Y H:i') }} ·
                    {{ $quote['source'] }}
                    @if ($quote['stale'] ?? false)
                        <x-filament::badge color="warning" size="sm">sin actualizar</x-filament::badge>
                    @endif
                </div>
            </div>
        </x-filament::section>
    @endif

</x-filament-widgets::widget>
