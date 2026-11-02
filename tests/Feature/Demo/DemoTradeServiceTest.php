<?php

namespace Tests\Feature\Demo;

use App\Enums\InstrumentType;
use App\Enums\MarketProvider;
use App\Enums\TradeSide;
use App\Models\DemoPortfolio;
use App\Models\Instrument;
use App\Models\PriceBar;
use App\Services\Demo\DemoTradeException;
use App\Services\Demo\DemoTradeService;
use App\Services\Market\FxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoTradeServiceTest extends TestCase
{
    use RefreshDatabase;

    private function bar(Instrument $i, string $date, float $close): void
    {
        PriceBar::query()->create(['instrument_id' => $i->id, 'date' => $date, 'open' => $close, 'high' => $close, 'low' => $close, 'close' => $close]);
    }

    private function instrument(string $symbol = 'AAA', string $currency = 'EUR'): Instrument
    {
        return Instrument::query()->create([
            'symbol' => $symbol, 'name' => $symbol, 'type' => InstrumentType::STOCK,
            'provider' => MarketProvider::YAHOO, 'provider_symbol' => $symbol, 'currency' => $currency,
        ]);
    }

    private function portfolio(array $attrs = []): DemoPortfolio
    {
        return DemoPortfolio::query()->create($attrs + [
            'name' => 'Test', 'initial_points' => 1000, 'start_date' => '2026-10-01', 'fee_fixed' => 1, 'fee_percent' => 0.5,
        ]);
    }

    public function test_preview_uses_last_close_fx_and_fee_without_persisting(): void
    {
        $i = $this->instrument('USDX', 'USD');
        $this->bar($i, '2026-10-01', 10);
        $this->bar($i, '2026-10-05', 20);
        $this->bar(app(FxService::class)->ensurePair('USD'), '2026-10-01', 2.0);
        $p = $this->portfolio();

        $r = app(DemoTradeService::class)->preview($p, $i, TradeSide::BUY, '2026-10-06', 10);

        $this->assertSame(20.0, $r['price']);
        $this->assertEqualsWithDelta(0.5, $r['fx_rate'], 1e-9);
        $this->assertEqualsWithDelta(100.0, $r['gross'], 1e-6);
        $this->assertEqualsWithDelta(1.5, $r['fee'], 1e-6);
        $this->assertEqualsWithDelta(101.5, $r['total_points'], 1e-6);
        $this->assertSame(0, $p->trades()->count());

        $sell = app(DemoTradeService::class)->preview($p, $i, TradeSide::SELL, '2026-10-06', 10);
        $this->assertEqualsWithDelta(98.5, $sell['total_points'], 1e-6);
    }

    public function test_execute_persists_trade(): void
    {
        $i = $this->instrument();
        $this->bar($i, '2026-10-02', 10);
        $p = $this->portfolio();

        $t = app(DemoTradeService::class)->execute($p, $i, TradeSide::BUY, '2026-10-03', 5);

        $this->assertSame('2026-10-03', $t->date->toDateString());
        $this->assertEqualsWithDelta(51.25, (float) $t->total_points, 1e-6);
        $this->assertSame(1, $p->trades()->count());
    }

    public function test_rejects_insufficient_cash_and_units_and_dates_before_start(): void
    {
        $i = $this->instrument();
        $this->bar($i, '2026-09-01', 10);
        $p = $this->portfolio();
        $s = app(DemoTradeService::class);

        try {
            $s->execute($p, $i, TradeSide::BUY, '2026-10-02', 1000);
            $this->fail('cash');
        } catch (DemoTradeException $e) {
            $this->assertStringContainsString('Puntos insuficientes', $e->getMessage());
        }

        try {
            $s->execute($p, $i, TradeSide::SELL, '2026-10-02', 1);
            $this->fail('units');
        } catch (DemoTradeException $e) {
            $this->assertStringContainsString('unidades suficientes', $e->getMessage());
        }

        try {
            $s->execute($p, $i, TradeSide::BUY, '2026-09-30', 1);
            $this->fail('start');
        } catch (DemoTradeException $e) {
            $this->assertStringContainsString('anterior al inicio', $e->getMessage());
        }

        $this->assertSame(0, $p->trades()->count());
    }

    public function test_backdated_trades_cannot_break_the_timeline(): void
    {
        $i = $this->instrument();
        $this->bar($i, '2026-10-01', 10);
        $p = $this->portfolio(['fee_fixed' => 0, 'fee_percent' => 0]);
        $s = app(DemoTradeService::class);

        $s->execute($p, $i, TradeSide::BUY, '2026-10-02', 50);   // 500
        $s->execute($p, $i, TradeSide::SELL, '2026-10-10', 50);  // vende todo

        // Compra retroactiva de 60 el 10-05 deja la caja en 0 pero la venta posterior del 10-10 ya no cubre... (110 uds, vende 50: ok)
        $s->execute($p, $i, TradeSide::BUY, '2026-10-05', 50);

        // Venta retroactiva entre medias que dejaría el 10-10 sin unidades.
        $this->expectException(DemoTradeException::class);
        $this->expectExceptionMessage('unidades suficientes');
        $s->execute($p, $i, TradeSide::SELL, '2026-10-03', 100);
    }

    public function test_backdated_buy_that_starves_later_cash_is_rejected(): void
    {
        $i = $this->instrument();
        $this->bar($i, '2026-10-01', 10);
        $p = $this->portfolio(['fee_fixed' => 0, 'fee_percent' => 0]);
        $s = app(DemoTradeService::class);

        $s->execute($p, $i, TradeSide::BUY, '2026-10-02', 100); // caja 0
        $s->execute($p, $i, TradeSide::SELL, '2026-10-04', 100); // caja 1000

        // Compra el 10-03 sin caja: aunque el 10-04 repone caja, el 10-03 es negativa.
        $this->expectException(DemoTradeException::class);
        $this->expectExceptionMessage('Puntos insuficientes');
        $s->execute($p, $i, TradeSide::BUY, '2026-10-03', 1);
    }

    public function test_missing_price_or_fx_gives_clear_error(): void
    {
        $p = $this->portfolio();
        $s = app(DemoTradeService::class);
        $i = $this->instrument('NOP');

        try {
            $s->preview($p, $i, TradeSide::BUY, '2026-10-02', 1);
            $this->fail('price');
        } catch (DemoTradeException $e) {
            $this->assertStringContainsString('cotización', $e->getMessage());
        }

        $u = $this->instrument('USDY', 'USD');
        $this->bar($u, '2026-10-01', 5);
        $this->expectException(DemoTradeException::class);
        $this->expectExceptionMessage('EUR/USD');
        $s->preview($p, $u, TradeSide::BUY, '2026-10-02', 1);
    }

    public function test_delete_checks_timeline(): void
    {
        $i = $this->instrument();
        $this->bar($i, '2026-10-01', 10);
        $p = $this->portfolio(['fee_fixed' => 0, 'fee_percent' => 0]);
        $s = app(DemoTradeService::class);

        $buy = $s->execute($p, $i, TradeSide::BUY, '2026-10-02', 10);
        $s->execute($p, $i, TradeSide::SELL, '2026-10-03', 10);

        try {
            $s->delete($buy);
            $this->fail('should reject');
        } catch (DemoTradeException) {
            $this->assertSame(2, $p->trades()->count());
        }
    }
}
