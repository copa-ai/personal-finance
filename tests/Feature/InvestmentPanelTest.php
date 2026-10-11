<?php

namespace Tests\Feature;

use App\Enums\ContributionFrequency;
use App\Enums\InvestmentTransactionType as T;
use App\Enums\PriceAlertCondition;
use App\Enums\ReminderStatus;
use App\Filament\Resources\InvestmentTransactions\Pages\CreateInvestmentTransaction;
use App\Filament\Resources\PriceAlerts\Pages\CreatePriceAlert;
use App\Filament\Resources\Reminders\Pages\ListReminders;
use App\Models\ContributionPlan;
use App\Models\InvestmentTransaction;
use App\Models\PriceAlert;
use App\Models\Reminder;
use App\Models\TargetAllocation;
use App\Models\User;
use App\Services\Investments\ReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesInvestments;
use Tests\TestCase;

class InvestmentPanelTest extends TestCase
{
    use CreatesInvestments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.market_data.url' => null]);
        $this->actingAs(User::factory()->create());
    }

    private function contributionReminder(?string $instrumentId = 'default'): Reminder
    {
        $platform = $this->platform();
        $plan = ContributionPlan::query()->create([
            'name' => 'Indexado mensual',
            'platform_id' => $platform->id,
            'instrument_id' => $instrumentId === 'default' ? $this->instrument()->id : $instrumentId,
            'amount' => 300,
            'frequency' => ContributionFrequency::MONTHLY,
            'next_due_on' => now()->toDateString(),
        ]);

        app(ReminderService::class)->contributions(CarbonImmutable::now());

        return Reminder::query()->where('remindable_id', $plan->id)->sole();
    }

    public function test_resource_pages_render(): void
    {
        $platform = $this->platform();
        $instrument = $this->instrument('MSCI World', ['market_symbol' => 'etf:IWDA.AS']);
        $tx = $this->tx($platform, $instrument, T::BUY, '2026-01-10', 10, 100);
        $this->price($instrument, '2026-09-30', 110);
        $plan = ContributionPlan::query()->create([
            'name' => 'Plan', 'platform_id' => $platform->id, 'amount' => 100,
            'frequency' => ContributionFrequency::MONTHLY, 'next_due_on' => '2026-11-01',
        ]);
        $target = TargetAllocation::query()->create(['asset_class' => 'EQUITY', 'target_pct' => 70]);
        $alert = PriceAlert::query()->create(['market_symbol' => 'crypto:BTC-USD', 'condition' => PriceAlertCondition::ABOVE, 'threshold' => 1]);
        $this->contributionReminder();

        foreach ([
            '/investment-platforms', '/investment-platforms/create', "/investment-platforms/{$platform->id}/edit",
            '/instruments', '/instruments/create', "/instruments/{$instrument->id}/edit",
            '/investment-transactions', '/investment-transactions/create', "/investment-transactions/{$tx->id}/edit",
            '/contribution-plans', '/contribution-plans/create', "/contribution-plans/{$plan->id}/edit",
            '/target-allocations', '/target-allocations/create', "/target-allocations/{$target->id}/edit",
            '/price-alerts', '/price-alerts/create', "/price-alerts/{$alert->id}/edit",
            '/reminders',
            '/establishments',
            '/assets',
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_platform_list_shows_value(): void
    {
        $platform = $this->platform('MyInvestor');
        $instrument = $this->instrument();
        $this->tx($platform, $instrument, T::BUY, '2026-01-10', 10, 100);
        $this->price($instrument, now()->toDateString(), 123.45);

        $this->get('/investment-platforms')->assertOk()->assertSee('1.234,50');
    }

    public function test_create_transaction_from_panel(): void
    {
        $platform = $this->platform();
        $instrument = $this->instrument();

        Livewire::test(CreateInvestmentTransaction::class)
            ->fillForm([
                'platform_id' => $platform->id,
                'instrument_id' => $instrument->id,
                'type' => T::BUY->value,
                'date' => '2026-10-01',
                'quantity' => '2.5',
                'price' => '101.20',
                'fees' => '1',
                'currency' => 'eur',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $tx = InvestmentTransaction::query()->sole();
        $this->assertSame(T::BUY, $tx->type);
        $this->assertSame(0, bccomp($tx->quantity, '2.5', 10));
        $this->assertSame('EUR', $tx->currency);
        $this->assertNull($tx->amount);
    }

    public function test_dividend_from_panel_saves_zero_quantity_and_no_price(): void
    {
        $platform = $this->platform();
        $instrument = $this->instrument();

        Livewire::test(CreateInvestmentTransaction::class)
            ->fillForm([
                'platform_id' => $platform->id,
                'instrument_id' => $instrument->id,
                'type' => T::DIVIDEND->value,
                'date' => '2026-10-01',
                'amount' => '12.30',
                'currency' => 'EUR',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $tx = InvestmentTransaction::query()->sole();
        $this->assertSame(T::DIVIDEND, $tx->type);
        $this->assertSame(0, bccomp($tx->quantity, '0', 10));
        $this->assertNull($tx->price);
        $this->assertSame('12.30', $tx->amount);
    }

    public function test_complete_contribution_from_reminders_list(): void
    {
        $reminder = $this->contributionReminder();

        Livewire::test(ListReminders::class)
            ->callTableAction('complete', $reminder, data: [
                'date' => '2026-10-06',
                'quantity' => '2.5',
                'price' => '119.80',
                'fees' => 0,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(ReminderStatus::DONE, $reminder->fresh()->status);
        $this->assertSame(T::BUY, InvestmentTransaction::query()->sole()->type);
    }

    public function test_complete_requires_instrument_when_plan_has_none(): void
    {
        $reminder = $this->contributionReminder(null);
        $instrument = $this->instrument('Fondo elegido');

        Livewire::test(ListReminders::class)
            ->callTableAction('complete', $reminder, data: ['date' => '2026-10-06', 'quantity' => 1, 'price' => 300])
            ->assertHasTableActionErrors(['instrument_id' => 'required']);

        Livewire::test(ListReminders::class)
            ->callTableAction('complete', $reminder, data: ['date' => '2026-10-06', 'quantity' => 1, 'price' => 300, 'instrument_id' => $instrument->id])
            ->assertHasNoTableActionErrors();

        $this->assertSame($instrument->id, InvestmentTransaction::query()->sole()->instrument_id);
    }

    public function test_skip_contribution_from_reminders_list(): void
    {
        $reminder = $this->contributionReminder();

        Livewire::test(ListReminders::class)
            ->callTableAction('skip', $reminder);

        $this->assertSame(ReminderStatus::SKIPPED, $reminder->fresh()->status);
        $this->assertSame(0, InvestmentTransaction::query()->count());
    }

    public function test_price_alert_rejects_invalid_symbol(): void
    {
        Livewire::test(CreatePriceAlert::class)
            ->fillForm([
                'market_symbol' => 'BTC',
                'condition' => PriceAlertCondition::ABOVE->value,
                'threshold' => 100000,
            ])
            ->call('create')
            ->assertHasFormErrors(['market_symbol' => 'regex']);

        Livewire::test(CreatePriceAlert::class)
            ->fillForm([
                'market_symbol' => 'crypto:BTC-USD',
                'condition' => PriceAlertCondition::ABOVE->value,
                'threshold' => 100000,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, PriceAlert::query()->count());
    }

    public function test_migrated_assets_are_hidden_from_assets_list(): void
    {
        $bank = \App\Models\BankAccount::query()->create(['name' => 'Principal', 'bank_name' => 'ING']);
        \App\Models\Asset::query()->create(['bank_account_id' => $bank->id, 'name' => 'Cuenta nómina', 'type' => 'CUENTA_CORRIENTE', 'current_value' => 100]);
        $migrated = \App\Models\Asset::query()->create(['bank_account_id' => $bank->id, 'name' => 'Fondo migrado', 'type' => 'FONDO_INVERSION', 'current_value' => 900]);
        \Illuminate\Support\Facades\DB::table('assets')->where('id', $migrated->id)->update(['migrated_at' => now()]);

        $this->get('/assets')->assertOk()->assertSee('Cuenta nómina')->assertDontSee('Fondo migrado');
        $this->assertSame('100', rtrim(rtrim($bank->fresh()->total_value, '0'), '.'));
    }
}
