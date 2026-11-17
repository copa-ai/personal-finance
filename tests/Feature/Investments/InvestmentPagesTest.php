<?php

namespace Tests\Feature\Investments;

use App\Enums\InvestmentTransactionType as T;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesInvestments;
use Tests\TestCase;

class InvestmentPagesTest extends TestCase
{
    use CreatesInvestments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_portfolio_and_tax_pages_render_with_data(): void
    {
        $p = $this->platform();
        $i = $this->instrument();
        $this->tx($p, $i, T::BUY, '2025-01-10', 10, 100);
        $this->tx($p, $i, T::SELL, '2025-06-10', 5, 120);
        $this->price($i, now()->toDateString(), 130);

        $this->get('/cartera')->assertOk()->assertSee('Cartera');
        $this->get('/fiscal?year=2025')->assertOk()->assertSee('Ejercicio 2025');
    }

    public function test_portfolio_page_renders_empty(): void
    {
        $this->get('/cartera')->assertOk();
    }

    public function test_every_widget_renders_with_data(): void
    {
        $p = $this->platform();
        $i = $this->instrument('MSCI World');
        $this->tx($p, $i, T::BUY, '2025-01-10', 10, 100);
        $this->tx($p, $i, T::SELL, '2025-06-10', 5, 120);
        $this->tx($p, $i, T::DIVIDEND, '2025-07-10', 0, null, ['amount' => 12]);
        $this->price($i, now()->toDateString(), 130);
        app(\App\Services\Portfolio\PortfolioService::class)->takeSnapshot(now()->subMonth());
        \App\Models\Reminder::query()->create(['kind' => \App\Enums\ReminderKind::CUSTOM, 'title' => 'Revisar cartera', 'due_on' => now()->toDateString()]);

        foreach ([
            \App\Filament\Widgets\Investments\PortfolioStats::class => ['650,00 €', '150,00 €'],
            \App\Filament\Widgets\Investments\PositionsTable::class => ['MSCI World', '650,00'],
            \App\Filament\Widgets\Investments\AllocationTable::class => ['Renta variable'],
            \App\Filament\Widgets\Investments\AllocationChart::class => [],
            \App\Filament\Widgets\Investments\PlatformChart::class => [],
            \App\Filament\Widgets\Investments\EvolutionChart::class => [],
            \App\Filament\Widgets\Investments\PendingReminders::class => ['Revisar cartera'],
        ] as $widget => $texts) {
            $test = Livewire::test($widget)->assertOk();
            foreach ($texts as $text) {
                $test->assertSee($text);
            }
        }

        Livewire::test(\App\Filament\Widgets\Investments\TaxTotals::class, ['year' => 2025])->assertSee('100,00 €')->assertSee('12,00 €');
        Livewire::test(\App\Filament\Widgets\Investments\TaxSalesTable::class, ['year' => 2025])->assertSee('600,00');
        Livewire::test(\App\Filament\Widgets\Investments\TaxIncomeTable::class, ['year' => 2025])->assertSee('12,00');
    }

    public function test_warnings_widget_lists_problems(): void
    {
        $p = $this->platform();
        $i = $this->instrument();
        $this->tx($p, $i, T::SELL, '2025-06-10', 5, 120);

        Livewire::test(\App\Filament\Widgets\Investments\PortfolioWarnings::class)->assertSee('sin saldo suficiente');
    }
}
