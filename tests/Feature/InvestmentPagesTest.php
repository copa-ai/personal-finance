<?php

namespace Tests\Feature;

use App\Enums\InvestmentTransactionType as T;
use App\Filament\Pages\Markets;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
        config(['services.market_data.url' => 'http://market.test']);
        Http::fake([
            'market.test/quote*' => Http::response(['quotes' => [[
                'symbol' => 'index:^GSPC', 'name' => 'S&P 500', 'price' => 6012.5, 'currency' => 'USD',
                'previous_close' => 5990.0, 'change' => 22.5, 'change_pct' => 0.38,
                'as_of' => '2026-10-12T10:00:00Z', 'source' => 'yahoo', 'stale' => false,
            ]], 'errors' => [['symbol' => 'crypto:BTC-USD', 'error' => 'proveedores no disponibles']]]),
            'market.test/history*' => Http::response(['symbol' => 'index:^GSPC', 'currency' => 'USD', 'source' => 'yahoo', 'candles' => [
                ['t' => '2026-10-09T00:00:00Z', 'o' => 1, 'h' => 1, 'l' => 1, 'c' => 5990.0, 'v' => null],
                ['t' => '2026-10-10T00:00:00Z', 'o' => 1, 'h' => 1, 'l' => 1, 'c' => 6012.5, 'v' => null],
            ]]),
            'market.test/search*' => Http::response(['results' => [['symbol' => 'stock:AAPL', 'name' => 'Apple Inc.', 'type' => 'stock', 'exchange' => 'NASDAQ']]]),
        ]);
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

    public function test_markets_page_shows_quotes_and_errors(): void
    {
        $list = Watchlist::query()->create(['name' => 'Mi lista']);
        WatchlistItem::query()->create(['watchlist_id' => $list->id, 'market_symbol' => 'index:^GSPC']);
        WatchlistItem::query()->create(['watchlist_id' => $list->id, 'market_symbol' => 'crypto:BTC-USD']);

        $this->get('/mercados')
            ->assertOk()
            ->assertSee('Mi lista')
            ->assertSee('6.012,50')
            ->assertSee('+0,38 %')
            ->assertSee('proveedores no disponibles');
    }

    public function test_markets_page_without_service_explains_why(): void
    {
        config(['services.market_data.url' => null]);

        $this->get('/mercados')->assertOk()->assertSee('MARKET_DATA_URL');
    }

    public function test_add_symbol_to_watchlist(): void
    {
        $list = Watchlist::query()->create(['name' => 'Mi lista']);

        Livewire::test(Markets::class)
            ->callAction('addSymbol', data: ['watchlist_id' => $list->id, 'manual_symbol' => 'etf:IWDA.AS'])
            ->assertHasNoActionErrors();

        Livewire::test(Markets::class)
            ->callAction('addSymbol', data: ['watchlist_id' => $list->id, 'manual_symbol' => 'IWDA'])
            ->assertHasActionErrors(['manual_symbol']);

        $this->assertSame(['etf:IWDA.AS'], $list->items()->pluck('market_symbol')->all());
    }

    public function test_symbol_page(): void
    {
        $this->get('/mercados/simbolo?s=' . urlencode('index:^GSPC'))
            ->assertOk()
            ->assertSee('S&amp;P 500', false)
            ->assertSee('SP:SPX');

        $this->get('/mercados/simbolo?s=' . urlencode('rm -rf'))->assertNotFound();
    }

    public function test_explore_page(): void
    {
        $this->get('/mercados/explorar')->assertOk()->assertSee('embed-widget-advanced-chart.js');
    }

    public function test_default_watchlists_are_seeded_by_migration(): void
    {
        $migration = require database_path('migrations/2026_10_12_000003_seed_default_watchlists.php');
        $migration->up();

        $this->assertSame(3, Watchlist::query()->count());
        $this->assertTrue(WatchlistItem::query()->where('market_symbol', 'index:^IBEX')->exists());
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
        Livewire::test(\App\Filament\Widgets\Markets\SymbolQuote::class, ['symbol' => 'index:^GSPC'])->assertSee('6.012,50');
        Livewire::test(\App\Filament\Widgets\Markets\SymbolHistoryChart::class, ['symbol' => 'index:^GSPC'])->assertSee('yahoo');
    }

    public function test_warnings_widget_lists_problems(): void
    {
        $p = $this->platform();
        $i = $this->instrument();
        $this->tx($p, $i, T::SELL, '2025-06-10', 5, 120);

        Livewire::test(\App\Filament\Widgets\Investments\PortfolioWarnings::class)->assertSee('sin saldo suficiente');
    }
}
