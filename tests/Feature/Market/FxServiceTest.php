<?php

namespace Tests\Feature\Market;

use App\Enums\InstrumentType;
use App\Enums\MarketProvider;
use App\Models\Instrument;
use App\Models\PriceBar;
use App\Services\Market\FxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class FxServiceTest extends TestCase
{
    use RefreshDatabase;

    private function pair(string $currency = 'USD'): Instrument
    {
        return app(FxService::class)->ensurePair($currency);
    }

    private function bar(Instrument $pair, string $date, float $close): void
    {
        PriceBar::query()->create([
            'instrument_id' => $pair->id, 'date' => $date,
            'open' => $close, 'high' => $close, 'low' => $close, 'close' => $close,
        ]);
    }

    public function test_eur_is_always_one(): void
    {
        $this->assertSame(1.0, app(FxService::class)->toEur('EUR', '2026-10-01'));
    }

    public function test_converts_with_latest_close_on_or_before_the_date(): void
    {
        $pair = $this->pair();
        $this->bar($pair, '2026-10-01', 1.25);
        $this->bar($pair, '2026-10-05', 2.0);

        $fx = app(FxService::class);

        $this->assertEqualsWithDelta(0.8, $fx->toEur('USD', '2026-10-03'), 1e-9);
        $this->assertEqualsWithDelta(0.5, $fx->toEur('usd', '2026-10-05'), 1e-9);
        $this->assertEqualsWithDelta(0.5, $fx->toEur('USD', now()->setDate(2026, 10, 9)), 1e-9);
    }

    public function test_throws_when_there_is_no_rate_on_or_before_the_date(): void
    {
        $this->bar($this->pair(), '2026-10-05', 1.25);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EUR/USD');

        app(FxService::class)->toEur('USD', '2026-10-04');
    }

    public function test_throws_when_the_pair_does_not_exist(): void
    {
        $this->expectException(RuntimeException::class);

        app(FxService::class)->toEur('JPY', '2026-10-04');
    }

    public function test_saving_a_non_eur_instrument_creates_the_fx_pair_once(): void
    {
        foreach (['AAPL', 'MSFT'] as $symbol) {
            Instrument::query()->create([
                'symbol' => $symbol, 'name' => $symbol, 'type' => InstrumentType::STOCK,
                'provider' => MarketProvider::YAHOO, 'provider_symbol' => $symbol, 'currency' => 'USD',
            ]);
        }

        $pairs = Instrument::query()->where('type', InstrumentType::FX->value)->get();

        $this->assertCount(1, $pairs);
        $this->assertSame('EURUSD', $pairs[0]->symbol);
        $this->assertSame('EURUSD=X', $pairs[0]->provider_symbol);
        $this->assertSame(MarketProvider::YAHOO, $pairs[0]->provider);
    }

    public function test_eur_instruments_do_not_create_a_pair(): void
    {
        Instrument::query()->create([
            'symbol' => 'SAN', 'name' => 'Santander', 'type' => InstrumentType::STOCK,
            'provider' => MarketProvider::YAHOO, 'provider_symbol' => 'SAN.MC',
        ]);

        $this->assertSame(0, Instrument::query()->where('type', InstrumentType::FX->value)->count());
    }
}
