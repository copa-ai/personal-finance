<?php

namespace Tests\Feature\Demo;

use App\Enums\InstrumentType;
use App\Enums\MarketProvider;
use App\Enums\TradeSide;
use App\Filament\Resources\DemoPortfolios\Pages\CreateDemoPortfolio;
use App\Filament\Resources\DemoPortfolios\Pages\EditDemoPortfolio;
use App\Filament\Resources\DemoPortfolios\RelationManagers\DemoTradesRelationManager;
use App\Models\DemoPortfolio;
use App\Models\Instrument;
use App\Models\PriceBar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DemoPortfolioResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function portfolio(): DemoPortfolio
    {
        return DemoPortfolio::query()->create([
            'name' => 'Escenario A',
            'initial_points' => 10000,
            'start_date' => now()->subDays(10)->toDateString(),
            'fee_fixed' => 1,
            'fee_percent' => 0,
        ]);
    }

    private function instrument(): Instrument
    {
        $instrument = Instrument::query()->create([
            'symbol' => 'SAN',
            'name' => 'Santander',
            'type' => InstrumentType::STOCK,
            'provider' => MarketProvider::YAHOO,
            'provider_symbol' => 'SAN.MC',
        ]);

        foreach (range(10, 0) as $daysAgo) {
            PriceBar::query()->create([
                'instrument_id' => $instrument->id,
                'date' => now()->subDays($daysAgo)->toDateString(),
                'open' => 10, 'high' => 10, 'low' => 10, 'close' => 10, 'volume' => 0,
            ]);
        }

        return $instrument;
    }

    public function test_pages_render(): void
    {
        $portfolio = $this->portfolio();

        foreach (['/demo-portfolios', '/demo-portfolios/create', "/demo-portfolios/{$portfolio->id}/edit"] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_portfolio_can_be_created(): void
    {
        Livewire::test(CreateDemoPortfolio::class)
            ->fillForm([
                'name' => 'Mi escenario',
                'initial_points' => 5000,
                'start_date' => now()->subDays(5)->toDateString(),
                'fee_fixed' => 0,
                'fee_percent' => 0.1,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('Mi escenario', DemoPortfolio::query()->sole()->name);
    }

    public function test_buy_action_registers_a_trade(): void
    {
        $portfolio = $this->portfolio();
        $instrument = $this->instrument();

        Livewire::test(DemoTradesRelationManager::class, [
            'ownerRecord' => $portfolio,
            'pageClass' => EditDemoPortfolio::class,
        ])
            ->callTableAction('buy', data: [
                'instrument_id' => $instrument->id,
                'date' => now()->subDays(3)->toDateString(),
                'units' => 100,
            ])
            ->assertHasNoTableActionErrors();

        $trade = $portfolio->trades()->sole();
        $this->assertSame(TradeSide::BUY, $trade->side);
        $this->assertEqualsWithDelta(1001.0, (float) $trade->total_points, 0.001);
    }

    public function test_sell_without_units_shows_error_and_creates_nothing(): void
    {
        $portfolio = $this->portfolio();
        $instrument = $this->instrument();

        Livewire::test(DemoTradesRelationManager::class, [
            'ownerRecord' => $portfolio,
            'pageClass' => EditDemoPortfolio::class,
        ])->callTableAction('sell', data: [
            'instrument_id' => $instrument->id,
            'date' => now()->subDays(3)->toDateString(),
            'units' => 5,
        ]);

        $this->assertSame(0, $portfolio->trades()->count());
    }
}
