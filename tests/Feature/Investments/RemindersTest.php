<?php

namespace Tests\Feature\Investments;

use App\Enums\AssetClass;
use App\Enums\ContributionFrequency;
use App\Enums\InstrumentKind;
use App\Enums\InvestmentTransactionType as T;
use App\Enums\ReminderKind;
use App\Enums\ReminderStatus;
use App\Models\ContributionPlan;
use App\Models\InvestmentTransaction;
use App\Models\Reminder;
use App\Models\TargetAllocation;
use App\Models\User;
use App\Services\Investments\ContributionService;
use App\Services\Investments\ReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\Concerns\CreatesInvestments;
use Tests\TestCase;

class RemindersTest extends TestCase
{
    use CreatesInvestments;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        config(['services.market_data.url' => null]);
    }

    private function run_(string $date): array
    {
        return app(ReminderService::class)->run(CarbonImmutable::parse($date));
    }

    private function plan(string $nextDue, ContributionFrequency $frequency = ContributionFrequency::MONTHLY): ContributionPlan
    {
        return ContributionPlan::query()->create([
            'name' => 'Indexado mensual',
            'platform_id' => $this->platform()->id,
            'instrument_id' => $this->instrument()->id,
            'amount' => 300,
            'frequency' => $frequency,
            'next_due_on' => $nextDue,
        ]);
    }

    public function test_due_contribution_creates_one_reminder_and_notifies(): void
    {
        $plan = $this->plan('2026-10-05');

        $first = $this->run_('2026-10-05');
        $second = $this->run_('2026-10-05');

        $this->assertSame(1, $first['contributions']);
        $this->assertSame(0, $second['contributions']);
        $this->assertSame('2026-11-05', $plan->fresh()->next_due_on->toDateString());
        $this->assertSame(1, $this->user->notifications()->count());

        $reminder = Reminder::query()->sole();
        $this->assertSame(ReminderKind::CONTRIBUTION, $reminder->kind);
        $this->assertTrue($reminder->remindable->is($plan));
    }

    public function test_missed_periods_create_one_reminder_each(): void
    {
        $this->plan('2026-07-31');

        $this->assertSame(3, $this->run_('2026-10-15')['contributions']);
        // Fin de mes sin desbordar: 31/07 -> 31/08 -> 30/09 -> 30/10.
        $this->assertSame(
            ['2026-07-31', '2026-08-31', '2026-09-30'],
            Reminder::query()->orderBy('due_on')->pluck('due_on')->map->toDateString()->all(),
        );
    }

    public function test_future_and_inactive_plans_do_not_remind(): void
    {
        $this->plan('2026-11-01');
        $inactive = $this->plan('2026-10-01');
        $inactive->update(['active' => false]);

        $this->assertSame(0, $this->run_('2026-10-15')['contributions']);
    }

    public function test_complete_creates_buy_and_resolves(): void
    {
        $plan = $this->plan('2026-10-05');
        $this->run_('2026-10-05');
        $reminder = Reminder::query()->sole();

        $tx = app(ContributionService::class)->complete($reminder, [
            'date' => '2026-10-06',
            'quantity' => '2.5',
            'price' => '119.80',
            'fees' => '0',
        ]);

        $this->assertSame(T::BUY, $tx->type);
        $this->assertSame($plan->platform_id, $tx->platform_id);
        $this->assertSame($plan->instrument_id, $tx->instrument_id);
        $this->assertSame(ReminderStatus::DONE, $reminder->fresh()->status);

        $this->expectException(InvalidArgumentException::class);
        app(ContributionService::class)->complete($reminder->fresh(), ['date' => '2026-10-06', 'quantity' => 1, 'price' => 1]);
    }

    public function test_skip_resolves_without_transaction(): void
    {
        $this->plan('2026-10-05');
        $this->run_('2026-10-05');
        $reminder = Reminder::query()->sole();

        app(ContributionService::class)->skip($reminder);

        $this->assertSame(ReminderStatus::SKIPPED, $reminder->fresh()->status);
        $this->assertSame(0, InvestmentTransaction::query()->count());
    }

    public function test_stale_price_reminder_once_a_month_and_not_for_market_symbols(): void
    {
        $p = $this->platform();
        $fund = $this->instrument('Fondo sin cotización', ['kind' => InstrumentKind::FUND]);
        $etf = $this->instrument('ETF con símbolo', ['market_symbol' => 'etf:IWDA.AS']);
        $fresh = $this->instrument('Fondo al día', ['kind' => InstrumentKind::FUND]);
        $this->tx($p, $fund, T::BUY, '2026-01-10', 1, 100);
        $this->tx($p, $etf, T::BUY, '2026-01-10', 1, 100);
        $this->tx($p, $fresh, T::BUY, '2026-01-10', 1, 100);
        $this->price($fund, '2026-08-01', 105);
        $this->price($fresh, '2026-10-09', 105);

        $this->assertSame(1, $this->run_('2026-10-10')['stale_prices']);
        $this->assertSame(0, $this->run_('2026-10-20')['stale_prices']);
        $this->assertSame(1, $this->run_('2026-11-02')['stale_prices']);
    }

    public function test_maturity_reminder_within_notice_window(): void
    {
        $this->instrument('Depósito 6m', ['kind' => InstrumentKind::DEPOSIT, 'maturity_date' => '2026-10-20']);
        $this->instrument('Depósito 12m', ['kind' => InstrumentKind::DEPOSIT, 'maturity_date' => '2027-03-20']);

        $this->assertSame(1, $this->run_('2026-10-10')['maturities']);
        $this->assertSame(0, $this->run_('2026-10-11')['maturities']);
    }

    public function test_rebalance_reminder_when_drift_exceeds_threshold(): void
    {
        $p = $this->platform();
        $this->tx($p, $this->instrument('MSCI World'), T::BUY, '2026-01-10', 1, 800);
        $this->tx($p, $this->instrument('Bonos', ['kind' => InstrumentKind::BOND, 'asset_class' => AssetClass::FIXED_INCOME]), T::BUY, '2026-01-10', 1, 200);
        TargetAllocation::query()->create(['asset_class' => AssetClass::EQUITY, 'target_pct' => 70]);
        TargetAllocation::query()->create(['asset_class' => AssetClass::FIXED_INCOME, 'target_pct' => 30]);

        $this->assertSame(1, $this->run_('2026-10-10')['rebalance']);
        $this->assertStringContainsString('Renta variable: 80,0 %', Reminder::query()->where('kind', ReminderKind::REBALANCE)->sole()->body);
    }

    public function test_tax_reminders_in_march_and_april(): void
    {
        $foreign = $this->platform('Trade Republic', 'DE');
        $this->tx($foreign, $this->instrument(), T::BUY, '2025-06-10', 1, 100);

        $this->assertSame(1, $this->run_('2026-03-02')['tax']);
        $this->assertSame(1, $this->run_('2026-04-08')['tax']);
        $this->assertSame(0, $this->run_('2026-04-09')['tax']);
        $this->assertSame(0, $this->run_('2026-05-09')['tax']);
    }

    public function test_ntfy_is_used_when_enabled_and_its_failure_is_harmless(): void
    {
        config([
            'services.market_data.url' => 'http://market.test',
            'investments.ntfy' => true,
        ]);
        Http::fake(['market.test/notify' => Http::response(['error' => 'ntfy caído'], 502)]);
        $this->plan('2026-10-05');

        $this->assertSame(1, $this->run_('2026-10-05')['contributions']);

        Http::assertSent(fn ($request) => $request->url() === 'http://market.test/notify'
            && $request['title'] === 'Aportación: Indexado mensual');
        $this->assertSame(1, $this->user->notifications()->count());
    }
}
