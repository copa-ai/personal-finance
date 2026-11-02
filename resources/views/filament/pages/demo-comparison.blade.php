@php
    $portfolios = $this->portfolios();
    $eur = fn (?float $value): string => $value === null ? 'No disponible' : number_format($value, 2, ',', '.').' €';
    $pct = fn (?float $value): string => $value === null ? 'No disponible' : number_format($value, 2, ',', '.').' %';
    $signed = fn (?float $value, string $text): string => $value === null ? 'text-gray-500' : ($value >= 0 ? 'text-success-600' : 'text-danger-600');
@endphp

<x-filament-panels::page>
    @if ($portfolios->isEmpty())
        <x-filament::section>
            <p>Todavía no hay carteras demo. Crea una en
                <a href="{{ \App\Filament\Resources\DemoPortfolios\DemoPortfolioResource::getUrl('create') }}" class="text-primary-600 underline">Carteras demo</a>
                para compararla con tu cartera real.</p>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Escenarios</x-slot>

            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:1.5rem">
                @foreach ($portfolios as $id => $portfolio)
                    <label style="display:flex;align-items:center;gap:0.5rem">
                        <x-filament::input.checkbox wire:model.live="selected" value="{{ $id }}" />
                        <span>{{ $portfolio->name }}</span>
                    </label>
                @endforeach
            </div>

            <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:1rem;margin-top:1rem">
                <div style="display:flex;flex-wrap:wrap;gap:1.5rem">
                    <label style="display:flex;align-items:center;gap:0.5rem">
                        <x-filament::input.checkbox wire:model.live="showBenchmark" />
                        <span>Benchmark</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:0.5rem">
                        <x-filament::input.checkbox wire:model.live="showReal" />
                        <span>Cartera real</span>
                    </label>
                </div>

                <x-filament::tabs>
                    <x-filament::tabs.item :active="$this->mode === 'eur'" wire:click="$set('mode', 'eur')">Valor en €</x-filament::tabs.item>
                    <x-filament::tabs.item :active="$this->mode === 'pct'" wire:click="$set('mode', 'pct')">Rentabilidad %</x-filament::tabs.item>
                </x-filament::tabs>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div
                wire:ignore
                wire:key="demo-comparison-chart"
                x-data="demoComparisonChart(@js($this->chart()))"
                x-on:demo-comparison-updated.window="update($event.detail.chart)"
                class="w-full"
            >
                <div x-ref="chart" style="width:100%;height:28rem"></div>
                <p class="mt-2 text-xs text-gray-500">Línea discontinua: benchmark (comprado el primer día y mantenido).</p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Resumen</x-slot>

            @php($rows = $this->summary())
            @if ($rows === [])
                <p class="text-sm text-gray-500">Selecciona al menos un escenario.</p>
            @else
                <div style="overflow-x:auto">
                    <table class="w-full text-sm" style="border-collapse:collapse">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="py-2 pe-4">Escenario</th>
                                <th class="py-2 pe-4">Puntos iniciales</th>
                                <th class="py-2 pe-4">Valor actual</th>
                                <th class="py-2 pe-4">Resultado</th>
                                <th class="py-2 pe-4">Benchmark</th>
                                <th class="py-2 pe-4">Diferencia vs real</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr class="border-t border-gray-200 dark:border-white/10">
                                    <td class="py-2 pe-4"><span style="display:inline-block;width:0.6rem;height:0.6rem;border-radius:9999px;background:{{ $row['color'] }};margin-right:0.4rem"></span>{{ $row['name'] }}</td>
                                    <td class="py-2 pe-4">{{ $eur($row['initial']) }}</td>
                                    <td class="py-2 pe-4 font-semibold">{{ $eur($row['value']) }}</td>
                                    <td class="py-2 pe-4 {{ $signed($row['pnl'], '') }}">{{ $eur($row['pnl']) }} ({{ $pct($row['pnl_percent']) }})</td>
                                    <td class="py-2 pe-4">{{ $eur($row['benchmark']) }}</td>
                                    <td class="py-2 pe-4 {{ $signed($row['diff'], '') }}">
                                        @if ($row['diff'] === null)
                                            Sin cartera real
                                        @else
                                            Tendrías {{ $eur($row['value']) }}: {{ $eur(abs($row['diff'])) }} / {{ $pct($row['diff_percent'] === null ? null : abs($row['diff_percent'])) }}
                                            {{ $row['diff'] >= 0 ? 'más' : 'menos' }} que la real ({{ $eur($row['real']) }})
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        @script
            <script>
                Alpine.data('demoComparisonChart', (initial) => ({
                    chart: null,
                    lines: [],

                    init() {
                        const dark = document.documentElement.classList.contains('dark')
                        const { createChart } = window.LightweightCharts

                        this.chart = createChart(this.$refs.chart, {
                            autoSize: true,
                            localization: { locale: 'es-ES' },
                            layout: {
                                background: { color: 'transparent' },
                                textColor: dark ? '#d1d5db' : '#374151',
                            },
                            grid: {
                                vertLines: { color: dark ? '#1f2937' : '#e5e7eb' },
                                horzLines: { color: dark ? '#1f2937' : '#e5e7eb' },
                            },
                            rightPriceScale: { borderVisible: false },
                            timeScale: { borderVisible: false },
                        })

                        this.update(initial)
                    },

                    update(data) {
                        const { LineSeries } = window.LightweightCharts
                        const pct = data.mode === 'pct'

                        this.lines.forEach((line) => this.chart.removeSeries(line))
                        this.lines = data.series.map((serie) => {
                            const base = serie.data.length ? serie.data[0].value : 0
                            const line = this.chart.addSeries(LineSeries, {
                                color: serie.color,
                                lineWidth: 2,
                                lineStyle: serie.dashed ? 2 : 0,
                                title: serie.label,
                                priceLineVisible: false,
                                priceFormat: pct
                                    ? { type: 'custom', formatter: (v) => v.toFixed(2) + ' %' }
                                    : { type: 'price', precision: 2, minMove: 0.01 },
                            })
                            line.setData(serie.data.map((p) => ({
                                time: p.time,
                                value: pct ? (base ? (p.value / base - 1) * 100 : 0) : p.value,
                            })))
                            return line
                        })
                        this.chart.timeScale().fitContent()
                    },

                    destroy() {
                        this.chart?.remove()
                    },
                }))
            </script>
        @endscript
    @endif
</x-filament-panels::page>
