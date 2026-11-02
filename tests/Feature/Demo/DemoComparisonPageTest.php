<?php

namespace Tests\Feature\Demo;

use App\Enums\InstrumentType;
use App\Enums\MarketProvider;
use App\Enums\TradeSide;
use App\Filament\Pages\DemoComparison;
use App\Models\DemoPortfolio;
use App\Models\Instrument;
use App\Models\PriceBar;
use App\Models\User;
use App\Services\Demo\DemoTradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DemoComparisonPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function portfolio(string $name, ?Instrument $benchmark = null): DemoPortfolio
    {
        return DemoPortfolio::query()->create([
            'name' => $name, 'initial_points' => 1000, 'start_date' => now()->subDays(3)->toDateString(),
            'fee_fixed' => 0, 'fee_percent' => 0, 'benchmark_instrument_id' => $benchmark?->id,
        ]);
    }

    private function instrument(): Instrument
    {
        $instrument = Instrument::query()->create([
            'symbol' => 'AAA', 'name' => 'AAA', 'type' => InstrumentType::STOCK,
            'provider' => MarketProvider::YAHOO, 'provider_symbol' => 'AAA', 'currency' => 'EUR',
        ]);

        foreach ([3 => 10, 2 => 10, 1 => 12, 0 => 15] as $daysAgo => $close) {
            PriceBar::query()->create([
                'instrument_id' => $instrument->id, 'date' => now()->subDays($daysAgo)->toDateString(),
                'open' => $close, 'high' => $close, 'low' => $close, 'close' => $close,
            ]);
        }

        return $instrument;
    }

    public function test_page_renders_and_points_to_the_resource_when_empty(): void
    {
        $this->get('/comparar-demo')->assertOk()->assertSee('Todavía no hay carteras demo');
    }

    public function test_page_selects_portfolios_and_builds_the_chart(): void
    {
        $instrument = $this->instrument();
        $a = $this->portfolio('Escenario A', $instrument);
        $this->portfolio('Escenario B');
        app(DemoTradeService::class)->execute($a, $instrument, TradeSide::BUY, now()->subDays(2)->toDateString(), 50);

        $this->get('/comparar-demo')->assertOk()->assertSee('Escenario A');

        $page = Livewire::test(DemoComparison::class);
        $chart = $page->instance()->chart();

        $labels = collect($chart['series'])->pluck('label');
        $this->assertTrue($labels->contains('Escenario A'));
        $this->assertTrue($labels->contains('Escenario B'));
        $this->assertTrue($labels->contains('Escenario A · AAA'));
        $this->assertFalse($labels->contains('Cartera real'));

        $series = collect($chart['series'])->firstWhere('label', 'Escenario A');
        $this->assertEqualsWithDelta(1250, end($series['data'])['value'], 0.01);
    }

    public function test_toggles_remove_benchmark_and_filter_selection(): void
    {
        $instrument = $this->instrument();
        $a = $this->portfolio('Escenario A', $instrument);
        $this->portfolio('Escenario B');

        $page = Livewire::test(DemoComparison::class)
            ->set('showBenchmark', false)
            ->set('selected', [$a->id])
            ->set('mode', 'pct')
            ->assertDispatched('demo-comparison-updated');

        $chart = $page->instance()->chart();
        $this->assertSame(['Escenario A'], collect($chart['series'])->pluck('label')->all());
        $this->assertSame('pct', $chart['mode']);
    }

    public function test_summary_compares_with_the_real_portfolio(): void
    {
        $instrument = $this->instrument();
        $a = $this->portfolio('Escenario A', $instrument);
        app(DemoTradeService::class)->execute($a, $instrument, TradeSide::BUY, now()->subDays(2)->toDateString(), 50);

        $asset = \App\Models\CryptoAccount::query()->create(['name' => 'Cuenta', 'currency' => 'BTC']);
        $asset->valueSnapshots()->create(['date' => now()->subDays(3)->toDateString(), 'value' => 1000, 'currency' => 'EUR']);

        $rows = Livewire::test(DemoComparison::class)->instance()->summary();

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(1250, $rows[0]['value'], 0.01);
        $this->assertEqualsWithDelta(1000, $rows[0]['real'], 0.01);
        $this->assertEqualsWithDelta(250, $rows[0]['diff'], 0.01);
        $this->assertEqualsWithDelta(25, $rows[0]['diff_percent'], 0.01);
    }
}
