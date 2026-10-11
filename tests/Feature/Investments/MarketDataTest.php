<?php

namespace Tests\Feature\Investments;

use App\Enums\PriceAlertCondition;
use App\Enums\ReminderKind;
use App\Models\FxRate;
use App\Models\InstrumentPrice;
use App\Models\PriceAlert;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Investments\MarketPriceUpdater;
use App\Services\Investments\PriceAlertService;
use App\Services\MarketData\MarketDataClient;
use App\Services\MarketData\MarketDataUnavailable;
use App\Services\MarketData\MarketSymbol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesInvestments;
use Tests\TestCase;

class MarketDataTest extends TestCase
{
    use CreatesInvestments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.market_data.url' => 'market.test:8080']);
        $this->travelTo(now()->setTimezone('Europe/Madrid')->setDate(2026, 10, 12)->setTime(12, 0));
    }

    private function quote(string $symbol, float $price, string $currency = 'EUR', array $extra = []): array
    {
        return [
            'symbol' => $symbol, 'name' => $symbol, 'price' => $price, 'currency' => $currency,
            'previous_close' => null, 'change' => null, 'change_pct' => null,
            'as_of' => '2026-10-12T10:00:00Z', 'source' => 'yahoo', 'stale' => false, ...$extra,
        ];
    }

    public function test_symbol_validation(): void
    {
        $this->assertTrue(MarketSymbol::isValid('crypto:BTC-USD'));
        $this->assertTrue(MarketSymbol::isValid('index:^GSPC'));
        $this->assertTrue(MarketSymbol::isValid('commodity:GC=F'));
        $this->assertTrue(MarketSymbol::isValid('etf:IWDA.AS'));
        $this->assertFalse(MarketSymbol::isValid('BTC-USD'));
        $this->assertFalse(MarketSymbol::isValid('stock:AAPL;rm -rf'));
        $this->assertFalse(MarketSymbol::isValid('foo:BAR'));
        // Las mismas reglas que el servicio: en minúsculas no es válido, pero se normaliza.
        $this->assertFalse(MarketSymbol::isValid('stock:aapl'));
        $this->assertSame('stock:AAPL', MarketSymbol::normalize(' Stock:aapl '));
        $this->assertSame('crypto:BTC-EUR', MarketSymbol::normalize('crypto:btc-eur'));
        $this->assertNull(MarketSymbol::normalize('crypto:BTC'));
        $this->assertSame('index:^GSPC', $this->instrument('S&P', ['market_symbol' => 'index:^gspc'])->market_symbol);
        $this->assertSame('SP:SPX', MarketSymbol::toTradingView('index:^GSPC'));
        $this->assertSame('BITSTAMP:BTCUSD', MarketSymbol::toTradingView('crypto:BTC-USD'));
    }

    public function test_client_is_disabled_without_url(): void
    {
        config(['services.market_data.url' => null]);

        $this->assertFalse(app(MarketDataClient::class)->enabled());
        $this->expectException(MarketDataUnavailable::class);
        app(MarketDataClient::class)->fx();
    }

    public function test_client_reports_service_errors(): void
    {
        Http::fake(['market.test:8080/*' => Http::response(['error' => 'símbolo inválido'], 400)]);

        $this->expectExceptionMessage('símbolo inválido');
        app(MarketDataClient::class)->history('index:^GSPC');
    }

    public function test_quotes_filter_invalid_symbols_and_batch_by_fifty(): void
    {
        Http::fake(['market.test:8080/quote*' => Http::response(['quotes' => [], 'errors' => []])]);
        $symbols = array_map(fn (int $i) => "stock:S{$i}", range(1, 120));

        app(MarketDataClient::class)->quotes([...$symbols, 'no-valido']);

        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => ! str_contains(urldecode($r->url()), 'no-valido'));
    }

    public function test_refresh_prices_stores_one_price_per_day_and_source(): void
    {
        $etf = $this->instrument('MSCI World', ['market_symbol' => 'etf:IWDA.AS']);
        $btc = $this->instrument('Bitcoin', ['market_symbol' => 'crypto:BTC-EUR']);
        $manual = $this->instrument('Fondo sin símbolo');
        Http::fakeSequence('market.test:8080/quote*')
            ->push(['quotes' => [$this->quote('etf:IWDA.AS', 101.5), $this->quote('crypto:BTC-EUR', 74000, 'EUR', ['source' => 'kraken'])], 'errors' => []])
            ->push(['quotes' => [$this->quote('etf:IWDA.AS', 102.0)], 'errors' => [['symbol' => 'crypto:BTC-EUR', 'error' => 'sin datos']]]);

        $updater = app(MarketPriceUpdater::class);
        $updater->refreshPrices();
        $second = $updater->refreshPrices();

        $this->assertSame(['crypto:BTC-EUR: sin datos'], $second['errors']);
        $this->assertSame('102.0000000000', InstrumentPrice::query()->where('instrument_id', $etf->id)->sole()->price);
        $this->assertSame('kraken', InstrumentPrice::query()->where('instrument_id', $btc->id)->sole()->source);
        $this->assertSame(0, InstrumentPrice::query()->where('instrument_id', $manual->id)->count());
    }

    public function test_refresh_fx_stores_ecb_rates(): void
    {
        Http::fake(['market.test:8080/fx' => Http::response([
            'date' => '2026-10-10', 'base' => 'EUR', 'source' => 'ecb',
            'rates' => ['USD' => 1.1712, 'GBP' => 0.8650, 'bad' => 'x'],
        ])]);

        $this->assertSame(2, app(MarketPriceUpdater::class)->refreshFx());
        $this->assertSame('1.1712000000', FxRate::query()->where('currency', 'USD')->sole()->units_per_eur);
    }

    public function test_price_alerts_fire_once_within_cooldown(): void
    {
        User::factory()->create();
        PriceAlert::query()->create(['market_symbol' => 'crypto:BTC-USD', 'condition' => PriceAlertCondition::ABOVE, 'threshold' => 100000]);
        PriceAlert::query()->create(['market_symbol' => 'index:^GSPC', 'condition' => PriceAlertCondition::CHANGE_PCT, 'threshold' => 2]);
        PriceAlert::query()->create(['market_symbol' => 'commodity:GC=F', 'condition' => PriceAlertCondition::BELOW, 'threshold' => 2000]);
        Http::fake(['market.test:8080/quote*' => Http::response(['quotes' => [
            $this->quote('crypto:BTC-USD', 101000, 'USD'),
            $this->quote('index:^GSPC', 6000, 'USD', ['change_pct' => -2.4]),
            $this->quote('commodity:GC=F', 2500, 'USD'),
        ], 'errors' => []])]);

        $service = app(PriceAlertService::class);

        $this->assertSame(2, $service->check());
        $this->assertSame(0, $service->check());
        $this->assertSame(2, Reminder::query()->where('kind', ReminderKind::PRICE_ALERT)->count());

        $this->travel(25)->hours();
        $this->assertSame(2, $service->check());
    }

    public function test_stale_quotes_do_not_fire_alerts(): void
    {
        PriceAlert::query()->create(['market_symbol' => 'crypto:BTC-USD', 'condition' => PriceAlertCondition::ABOVE, 'threshold' => 1]);
        Http::fake(['market.test:8080/quote*' => Http::response(['quotes' => [
            $this->quote('crypto:BTC-USD', 101000, 'USD', ['stale' => true]),
        ], 'errors' => []])]);

        $this->assertSame(0, app(PriceAlertService::class)->check());
    }

    public function test_commands_do_nothing_without_market_service(): void
    {
        config(['services.market_data.url' => null]);
        Http::fake();

        $this->artisan('market:refresh')->assertSuccessful();
        $this->artisan('market:check-alerts')->assertSuccessful();
        Http::assertNothingSent();
    }
}
