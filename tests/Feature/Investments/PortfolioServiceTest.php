<?php

namespace Tests\Feature\Investments;

use App\Enums\AssetClass;
use App\Enums\InstrumentKind;
use App\Enums\InvestmentTransactionType as T;
use App\Models\FxRate;
use App\Models\TargetAllocation;
use App\Services\Portfolio\PortfolioService;
use App\Services\Portfolio\Xirr;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesInvestments;
use Tests\TestCase;

class PortfolioServiceTest extends TestCase
{
    use CreatesInvestments;
    use RefreshDatabase;

    private function service(): PortfolioService
    {
        return app(PortfolioService::class);
    }

    private function asOf(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-01');
    }

    public function test_buy_creates_position_with_cost_including_fees(): void
    {
        $p = $this->platform();
        $i = $this->instrument();
        $this->tx($p, $i, T::BUY, '2026-01-10', 10, '100', ['fees' => '2.50']);
        $this->price($i, '2026-09-30', '110');

        $position = $this->service()->summary($this->asOf())['positions'][0];

        $this->assertSame('1002.50', $position['cost_eur']);
        $this->assertSame('1100.00', $position['value_eur']);
        $this->assertSame('97.50', $position['unrealized_eur']);
        $this->assertFalse($position['valued_at_cost']);
        $this->assertSame(1, $position['price_age_days']);
    }

    public function test_sell_uses_fifo_and_realizes_gain(): void
    {
        $p = $this->platform();
        $i = $this->instrument();
        $this->tx($p, $i, T::BUY, '2026-01-10', 10, '100');
        $this->tx($p, $i, T::BUY, '2026-02-10', 10, '120');
        $this->tx($p, $i, T::SELL, '2026-03-10', 15, '130', ['fees' => '5']);
        $this->price($i, '2026-09-30', '130');

        $ledger = $this->service()->ledger($this->asOf());
        $sale = $ledger['realized'][0];

        // 10 a 100 + 5 a 120 = 1600 de coste; 15 * 130 - 5 = 1945 de venta.
        $this->assertSame('1600.00', $sale['cost_eur']);
        $this->assertSame('1945.00', $sale['proceeds_eur']);
        $this->assertSame('345.00', $sale['gain_eur']);
        $this->assertSame('2026-01-10', $sale['acquired_from']->toDateString());

        $position = $ledger['positions'][0];
        $this->assertSame(0, bccomp($position['quantity'], '5', 10));
        $this->assertSame('600.00', $position['cost_eur']);
    }

    public function test_crypto_decimals_are_exact(): void
    {
        $p = $this->platform('Ledger', type: \App\Enums\PlatformType::WALLET);
        $btc = $this->instrument('Bitcoin', ['kind' => InstrumentKind::CRYPTO, 'asset_class' => AssetClass::CRYPTO]);
        $this->tx($p, $btc, T::BUY, '2026-01-10', '0.00012345', '80000.1');
        $this->tx($p, $btc, T::BUY, '2026-01-11', '0.00000001', '80000.1');
        $this->tx($p, $btc, T::SELL, '2026-01-12', '0.00012346', '90000');

        $ledger = $this->service()->ledger($this->asOf());

        $this->assertSame([], $ledger['positions']);
        $this->assertSame('1.23', $ledger['realized'][0]['gain_eur']);
    }

    public function test_foreign_currency_uses_transaction_fx_for_cost_and_latest_rate_for_value(): void
    {
        $p = $this->platform('Interactive Brokers', 'IE');
        $i = $this->instrument('Apple', ['kind' => InstrumentKind::STOCK, 'currency' => 'USD']);
        // 1 USD = 0.90 EUR al comprar.
        $this->tx($p, $i, T::BUY, '2026-01-10', 10, '200', ['currency' => 'USD', 'fx_rate_eur' => '0.9']);
        $this->price($i, '2026-09-30', '250');
        // BCE: 1 EUR = 1.25 USD -> 1 USD = 0.80 EUR.
        FxRate::query()->create(['date' => '2026-09-30', 'currency' => 'USD', 'units_per_eur' => '1.25', 'source' => 'ecb']);

        $position = $this->service()->summary($this->asOf())['positions'][0];

        $this->assertSame('1800.00', $position['cost_eur']);
        $this->assertSame('2000.00', $position['value_eur']);
    }

    public function test_missing_fx_rate_falls_back_to_cost_and_warns(): void
    {
        $p = $this->platform();
        $i = $this->instrument('Apple', ['currency' => 'USD']);
        $this->tx($p, $i, T::BUY, '2026-01-10', 1, '100', ['currency' => 'USD', 'fx_rate_eur' => '0.9']);
        $this->price($i, '2026-09-30', '150');

        $summary = $this->service()->summary($this->asOf());

        $this->assertTrue($summary['positions'][0]['valued_at_cost']);
        $this->assertSame('90.00', $summary['positions'][0]['value_eur']);
        $this->assertNotEmpty($summary['warnings']);
    }

    public function test_position_without_price_is_valued_at_cost(): void
    {
        $p = $this->platform();
        $i = $this->instrument('Depósito 12m', ['kind' => InstrumentKind::DEPOSIT, 'asset_class' => AssetClass::CASH]);
        $this->tx($p, $i, T::BUY, '2026-01-10', 5000, 1);

        $position = $this->service()->summary($this->asOf())['positions'][0];

        $this->assertTrue($position['valued_at_cost']);
        $this->assertSame('5000.00', $position['value_eur']);
    }

    public function test_manual_price_wins_over_market_price_on_the_same_day(): void
    {
        $p = $this->platform();
        $i = $this->instrument();
        $this->tx($p, $i, T::BUY, '2026-01-10', 1, '100');
        $this->price($i, '2026-09-30', '120', 'yahoo');
        $this->price($i, '2026-09-30', '118', 'manual');

        $position = $this->service()->summary($this->asOf())['positions'][0];

        $this->assertSame('118.00', $position['value_eur']);
        $this->assertSame('manual', $position['price_source']);
    }

    public function test_newer_market_price_wins_over_older_manual_price(): void
    {
        $p = $this->platform();
        $i = $this->instrument();
        $this->tx($p, $i, T::BUY, '2026-01-10', 1, '100');
        $this->price($i, '2026-09-01', '118', 'manual');
        $this->price($i, '2026-09-30', '125', 'yahoo');

        $this->assertSame('125.00', $this->service()->summary($this->asOf())['positions'][0]['value_eur']);
    }

    public function test_transfer_between_own_platforms_keeps_cost_basis(): void
    {
        $a = $this->platform('Binance');
        $b = $this->platform('Ledger');
        $btc = $this->instrument('Bitcoin', ['kind' => InstrumentKind::CRYPTO, 'asset_class' => AssetClass::CRYPTO]);
        $this->tx($a, $btc, T::BUY, '2026-01-10', '1', '50000');
        $this->tx($a, $btc, T::TRANSFER_OUT, '2026-02-10', '1');
        $this->tx($b, $btc, T::TRANSFER_IN, '2026-02-10', '1');

        $summary = $this->service()->summary($this->asOf());

        $this->assertCount(1, $summary['positions']);
        $this->assertSame('Ledger', $summary['positions'][0]['platform']);
        $this->assertSame('50000.00', $summary['positions'][0]['cost_eur']);
        $this->assertSame('50000.00', $summary['invested_eur']);
    }

    public function test_split_keeps_total_cost(): void
    {
        $p = $this->platform();
        $i = $this->instrument();
        $this->tx($p, $i, T::BUY, '2026-01-10', 10, '100');
        $this->tx($p, $i, T::SPLIT, '2026-02-10', 4);
        $this->tx($p, $i, T::SELL, '2026-03-10', 20, '30');

        $ledger = $this->service()->ledger($this->asOf());

        $this->assertSame('500.00', $ledger['realized'][0]['cost_eur']);
        $this->assertSame('100.00', $ledger['realized'][0]['gain_eur']);
        $this->assertSame(0, bccomp($ledger['positions'][0]['quantity'], '20', 10));
    }

    public function test_negative_adjustment_reduces_position(): void
    {
        $p = $this->platform();
        $i = $this->instrument();
        $this->tx($p, $i, T::ADJUSTMENT, '2026-01-10', 10, '100');
        $this->tx($p, $i, T::ADJUSTMENT, '2026-02-10', -4);

        $position = $this->service()->summary($this->asOf())['positions'][0];

        $this->assertSame(0, bccomp($position['quantity'], '6', 10));
        $this->assertSame('600.00', $position['cost_eur']);
    }

    public function test_selling_more_than_held_warns(): void
    {
        $p = $this->platform();
        $i = $this->instrument();
        $this->tx($p, $i, T::BUY, '2026-01-10', 1, '100');
        $this->tx($p, $i, T::SELL, '2026-02-10', 2, '100');

        $this->assertNotEmpty($this->service()->ledger($this->asOf())['warnings']);
    }

    public function test_dividends_interest_and_staking_are_income(): void
    {
        $p = $this->platform();
        $i = $this->instrument();
        $this->tx($p, $i, T::BUY, '2026-01-10', 10, '100');
        $this->tx($p, $i, T::DIVIDEND, '2026-03-10', 0, null, ['amount' => '12.50', 'fees' => '2.50']);
        $this->tx($p, $i, T::INTEREST, '2026-04-10', 0, null, ['amount' => '3']);
        $this->tx($p, $i, T::STAKING_REWARD, '2026-05-10', '0.5', '100');

        $summary = $this->service()->summary($this->asOf());

        $this->assertSame('63.00', $summary['income_eur']);
        // El staking entra con coste = valor recibido.
        $this->assertSame('1050.00', $summary['positions'][0]['cost_eur']);
    }

    public function test_two_month_rule_is_flagged_on_losses_with_repurchase(): void
    {
        $p = $this->platform();
        $i = $this->instrument();
        $this->tx($p, $i, T::BUY, '2026-01-10', 10, '100');
        $this->tx($p, $i, T::SELL, '2026-03-10', 10, '80');
        $this->tx($p, $i, T::BUY, '2026-04-20', 10, '82');

        $sale = $this->service()->ledger($this->asOf())['realized'][0];

        $this->assertSame('-200.00', $sale['gain_eur']);
        $this->assertTrue($sale['two_month_rule']);
    }

    public function test_tax_year_groups_gains_losses_and_income(): void
    {
        $p = $this->platform();
        $a = $this->instrument('A');
        $b = $this->instrument('B');
        $this->tx($p, $a, T::BUY, '2025-06-10', 10, '100');
        $this->tx($p, $a, T::SELL, '2026-02-10', 10, '150');
        $this->tx($p, $b, T::BUY, '2025-06-10', 10, '100');
        $this->tx($p, $b, T::SELL, '2026-02-10', 10, '70');
        $this->tx($p, $a, T::DIVIDEND, '2026-02-15', 0, null, ['amount' => '20']);
        $this->tx($p, $b, T::DIVIDEND, '2025-12-15', 0, null, ['amount' => '99']);

        $year = $this->service()->taxYear(2026);

        $this->assertSame('500.00', $year['totals']['gains_eur']);
        $this->assertSame('-300.00', $year['totals']['losses_eur']);
        $this->assertSame('200.00', $year['totals']['net_eur']);
        $this->assertSame('20.00', $year['totals']['income_eur']);
    }

    public function test_allocation_and_drift_against_targets(): void
    {
        $p = $this->platform();
        $eq = $this->instrument('MSCI World');
        $bond = $this->instrument('Bonos', ['kind' => InstrumentKind::BOND, 'asset_class' => AssetClass::FIXED_INCOME]);
        $this->tx($p, $eq, T::BUY, '2026-01-10', 1, '750');
        $this->tx($p, $bond, T::BUY, '2026-01-10', 1, '250');
        TargetAllocation::query()->create(['asset_class' => AssetClass::EQUITY, 'target_pct' => 60]);
        TargetAllocation::query()->create(['asset_class' => AssetClass::FIXED_INCOME, 'target_pct' => 40]);

        $allocation = collect($this->service()->summary($this->asOf())['allocation'])->keyBy(fn ($r) => $r['asset_class']->value);

        $this->assertSame(75.0, $allocation['EQUITY']['pct']);
        $this->assertSame(15.0, $allocation['EQUITY']['drift_pp']);
        $this->assertSame(-15.0, $allocation['FIXED_INCOME']['drift_pp']);
    }

    public function test_transactions_after_as_of_are_ignored(): void
    {
        $p = $this->platform();
        $i = $this->instrument();
        $this->tx($p, $i, T::BUY, '2026-01-10', 1, '100');
        $this->tx($p, $i, T::BUY, '2026-12-10', 1, '100');

        $this->assertSame('100.00', $this->service()->summary($this->asOf())['invested_eur']);
    }

    public function test_snapshot_is_stored_once_per_day(): void
    {
        $p = $this->platform();
        $i = $this->instrument();
        $this->tx($p, $i, T::BUY, '2026-01-10', 1, '100');

        $this->service()->takeSnapshot($this->asOf());
        $snapshot = $this->service()->takeSnapshot($this->asOf());

        $this->assertSame('100.00', $snapshot->value_eur);
        $this->assertSame(['EQUITY' => '100.00'], $snapshot->by_asset_class);
        $this->assertSame(1, \App\Models\PortfolioSnapshot::query()->count());
    }

    public function test_xirr_matches_known_values(): void
    {
        // 1000 invertidos y 1100 un año después: 10 %.
        $this->assertEqualsWithDelta(0.10, Xirr::calculate([
            ['date' => CarbonImmutable::parse('2025-01-01'), 'amount' => -1000],
            ['date' => CarbonImmutable::parse('2026-01-01'), 'amount' => 1100],
        ]), 0.0005);

        // Ejemplo de la documentación de Excel XIRR: 0.373362535.
        $this->assertEqualsWithDelta(0.373362535, Xirr::calculate([
            ['date' => CarbonImmutable::parse('2008-01-01'), 'amount' => -10000],
            ['date' => CarbonImmutable::parse('2008-03-01'), 'amount' => 2750],
            ['date' => CarbonImmutable::parse('2008-10-30'), 'amount' => 4250],
            ['date' => CarbonImmutable::parse('2009-02-15'), 'amount' => 3250],
            ['date' => CarbonImmutable::parse('2009-04-01'), 'amount' => 2750],
        ]), 0.0005);

        $this->assertNull(Xirr::calculate([
            ['date' => CarbonImmutable::parse('2025-01-01'), 'amount' => -1000],
        ]));
    }
}
