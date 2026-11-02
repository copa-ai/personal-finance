<?php

namespace App\Filament\Pages;

use App\Models\DemoPortfolio;
use App\Services\Demo\DemoValuationService;
use App\Services\Demo\RealPortfolioService;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Throwable;

class DemoComparison extends Page
{
    public const COLORS = ['#3b82f6', '#16a34a', '#f59e0b', '#a855f7', '#ef4444', '#14b8a6'];

    public const REAL_COLOR = '#6b7280';

    protected static ?string $slug = 'comparar-demo';

    protected static ?string $title = 'Comparar escenarios';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static string|\UnitEnum|null $navigationGroup = 'Finanzas';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.demo-comparison';

    /** @var list<string> */
    #[Url]
    public array $selected = [];

    #[Url]
    public bool $showBenchmark = true;

    #[Url]
    public bool $showReal = true;

    /** 'eur' (valor en €) o 'pct' (rentabilidad %). */
    #[Url]
    public string $mode = 'eur';

    /** @var Collection<string, DemoPortfolio>|null */
    private ?Collection $portfolioCache = null;

    public function mount(): void
    {
        $valid = $this->portfolios()->keys()->all();
        $this->selected = array_values(array_intersect($this->selected, $valid));

        if ($this->selected === []) {
            $this->selected = array_slice($valid, 0, 2);
        }

        if (! in_array($this->mode, ['eur', 'pct'], true)) {
            $this->mode = 'eur';
        }
    }

    /**
     * @return Collection<string, DemoPortfolio>
     */
    public function portfolios(): Collection
    {
        return $this->portfolioCache ??= DemoPortfolio::query()->with('benchmarkInstrument')->orderBy('name')->get()->keyBy('id');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['showBenchmark', 'showReal', 'mode'], true) || str_starts_with($property, 'selected')) {
            $this->dispatch('demo-comparison-updated', chart: $this->chart());
        }
    }

    /**
     * @return list<DemoPortfolio>
     */
    private function selectedPortfolios(): array
    {
        return $this->portfolios()->only($this->selected)->values()->all();
    }

    /**
     * Curva real hasta hoy, o null si no se puede calcular (p. ej. sin tipo de cambio).
     *
     * @return list<array{date: string, value: float}>|null
     */
    private function realCurve(): ?array
    {
        $portfolios = $this->selectedPortfolios();
        if ($portfolios === []) {
            return [];
        }

        $from = collect($portfolios)->map(fn (DemoPortfolio $p) => $p->start_date->toDateString())->min();

        try {
            return app(RealPortfolioService::class)->curve($from, now()->toDateString());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{series: list<array<string, mixed>>, mode: string}
     */
    public function chart(): array
    {
        $valuation = app(DemoValuationService::class);
        $series = [];

        foreach ($this->selectedPortfolios() as $index => $portfolio) {
            $color = self::COLORS[$index % count(self::COLORS)];

            $series[] = [
                'key' => $portfolio->id,
                'label' => $portfolio->name,
                'color' => $color,
                'dashed' => false,
                'data' => $this->points($valuation->series($portfolio)),
            ];

            if ($this->showBenchmark && ($benchmark = $this->benchmark($portfolio)) !== null) {
                $series[] = [
                    'key' => $portfolio->id.'-bench',
                    'label' => "{$portfolio->name} · {$portfolio->benchmarkInstrument?->symbol}",
                    'color' => $color,
                    'dashed' => true,
                    'data' => $this->points($benchmark),
                ];
            }
        }

        if ($this->showReal && $series !== []) {
            $real = $this->realCurve() ?? [];
            if ($real !== []) {
                $series[] = [
                    'key' => 'real',
                    'label' => 'Cartera real',
                    'color' => self::REAL_COLOR,
                    'dashed' => false,
                    'data' => $this->points($real),
                ];
            }
        }

        return ['series' => $series, 'mode' => $this->mode];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{time: string, value: float}>
     */
    private function points(array $rows): array
    {
        return array_map(fn (array $row): array => [
            'time' => $row['date'],
            'value' => round((float) $row['value'], 2),
        ], $rows);
    }

    /**
     * @return list<array{date: string, value: float}>|null
     */
    private function benchmark(DemoPortfolio $portfolio): ?array
    {
        try {
            return app(DemoValuationService::class)->benchmarkSeries($portfolio);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resumen por escenario y diferencia frente a la cartera real.
     *
     * @return list<array{id: string, name: string, color: string, initial: float, value: float, pnl: float, pnl_percent: float|null, real: float|null, diff: float|null, diff_percent: float|null, benchmark: float|null}>
     */
    public function summary(): array
    {
        $valuation = app(DemoValuationService::class);
        $real = $this->realCurve();
        $realValue = $real ? (float) end($real)['value'] : null;
        $rows = [];

        foreach ($this->selectedPortfolios() as $index => $portfolio) {
            $summary = $valuation->summary($portfolio);
            $benchmark = $this->benchmark($portfolio);
            $diff = $realValue === null ? null : $summary['value'] - $realValue;

            $rows[] = [
                'id' => $portfolio->id,
                'name' => $portfolio->name,
                'color' => self::COLORS[$index % count(self::COLORS)],
                'initial' => $summary['initial_points'],
                'value' => $summary['value'],
                'pnl' => $summary['pnl'],
                'pnl_percent' => $summary['pnl_percent'],
                'real' => $realValue,
                'diff' => $diff,
                'diff_percent' => $diff !== null && $realValue != 0.0 ? $diff / $realValue * 100 : null,
                'benchmark' => $benchmark ? (float) end($benchmark)['value'] : null,
            ];
        }

        return $rows;
    }
}
