<?php

namespace Tests\Feature\Demo;

use App\Enums\InstrumentType;
use App\Enums\MarketProvider;
use App\Enums\TradeSide;
use App\Models\DemoPortfolio;
use App\Models\Instrument;
use App\Models\PriceBar;
use App\Services\Demo\DemoTradeService;
use App\Services\Demo\DemoValuationService;
use App\Services\Market\FxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoValuationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function bar(Instrument $i, string $date, float $close): void
    {
        PriceBar::query()->create(['instrument_id' => $i->id, 'date' => $date, 'open' => $close, 'high' => $close, 'low' => $close, 'close' => $close]);
    }

    private function instrument(string $symbol, string $currency = 'EUR'): Instrument
    {
        return Instrument::query()->create([
            'symbol' => $symbol, 'name' => $symbol, 'type' => InstrumentType::STOCK,
            'provider' => MarketProvider::YAHOO, 'provider_symbol' => $symbol, 'currency' => $currency,
        ]);
    }

    private function portfolio(array $attrs = []): DemoPortfolio
    {
        return DemoPortfolio::query()->create($attrs + [
            'name' => 'Test', 'initial_points' => 1000, 'start_date' => '2026-10-01', 'fee_fixed' => 0, 'fee_percent' => 0,
        ]);
    }

    public function test_series_positions_and_summary(): void
    {
        $i = $this->instrument('AAA');
        foreach (['2026-10-01' => 10, '2026-10-02' => 10, '2026-10-03' => 12, '2026-10-05' => 15] as $d => $c) {
            $this->bar($i, $d, $c);
        }
        $p = $this->portfolio();
        app(DemoTradeService::class)->execute($p, $i, TradeSide::BUY, '2026-10-02', 50); // 500

        $v = app(DemoValuationService::class);
        $series = collect($v->series($p, '2026-10-05'))->keyBy('date');

        $this->assertCount(5, $series);
        $this->assertEqualsWithDelta(1000, $series['2026-10-01']['value'], 1e-6);
        $this->assertEqualsWithDelta(500, $series['2026-10-02']['cash'], 1e-6);
        $this->assertEqualsWithDelta(1100, $series['2026-10-03']['value'], 1e-6);
        $this->assertEqualsWithDelta(1100, $series['2026-10-04']['value'], 1e-6); // carry forward
        $this->assertEqualsWithDelta(1250, $series['2026-10-05']['value'], 1e-6);

        $pos = $v->positions($p, '2026-10-05');
        $this->assertCount(1, $pos);
        $this->assertEqualsWithDelta(750, $pos[0]['value'], 1e-6);
        $this->assertEqualsWithDelta(250, $pos[0]['pnl'], 1e-6);
        $this->assertEqualsWithDelta(50, $pos[0]['pnl_percent'], 1e-6);

        $s = $v->summary($p, '2026-10-05');
        $this->assertEqualsWithDelta(250, $s['pnl'], 1e-6);
        $this->assertEqualsWithDelta(25, $s['pnl_percent'], 1e-6);
        $this->assertSame('2026-10-05', $s['as_of']);
    }

    public function test_series_converts_fx_and_closed_positions_vanish(): void
    {
        $i = $this->instrument('USDX', 'USD');
        $pair = app(FxService::class)->ensurePair('USD');
        $this->bar($pair, '2026-10-01', 2.0);
        $this->bar($pair, '2026-10-03', 4.0);
        $this->bar($i, '2026-10-01', 10);
        $p = $this->portfolio();
        $t = app(DemoTradeService::class);
        $t->execute($p, $i, TradeSide::BUY, '2026-10-01', 100); // 100*10*0.5 = 500

        $v = app(DemoValuationService::class);
        $series = collect($v->series($p, '2026-10-03'))->keyBy('date');
        $this->assertEqualsWithDelta(1000, $series['2026-10-01']['value'], 1e-6);
        $this->assertEqualsWithDelta(750, $series['2026-10-03']['value'], 1e-6); // fx 0.25

        $t->execute($p, $i, TradeSide::SELL, '2026-10-03', 100);
        $this->assertSame([], $v->positions($p, '2026-10-03'));
        $this->assertEqualsWithDelta(-250, $v->summary($p, '2026-10-03')['pnl'], 1e-6);
    }

    public function test_benchmark_series_buys_on_start_with_fee_and_holds(): void
    {
        $b = $this->instrument('BMK');
        $this->bar($b, '2026-10-01', 10);
        $this->bar($b, '2026-10-02', 11);
        $p = $this->portfolio(['fee_fixed' => 10, 'fee_percent' => 0, 'benchmark_instrument_id' => $b->id]);

        $s = app(DemoValuationService::class)->benchmarkSeries($p, '2026-10-02');

        $this->assertCount(2, $s);
        $this->assertEqualsWithDelta(990, $s[0]['value'], 1e-6);   // 99 uds * 10
        $this->assertEqualsWithDelta(1089, $s[1]['value'], 1e-6);
    }

    public function test_benchmark_is_null_without_instrument(): void
    {
        $this->assertNull(app(DemoValuationService::class)->benchmarkSeries($this->portfolio()));
    }
}
