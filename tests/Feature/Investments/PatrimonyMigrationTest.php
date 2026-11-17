<?php

namespace Tests\Feature\Investments;

use App\Enums\AssetType;
use App\Enums\InstrumentType;
use App\Enums\InvestmentTransactionType;
use App\Enums\MarketProvider;
use App\Models\Asset;
use App\Models\BankAccount;
use App\Models\CryptoAccount;
use App\Models\Instrument;
use App\Models\InvestmentTransaction;
use App\Models\PriceBar;
use App\Services\Portfolio\PortfolioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PatrimonyMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migrate(): void
    {
        $migration = require database_path('migrations/2026_01_03_000020_migrate_patrimony_to_investments.php');
        $migration->up();
    }

    public function test_crypto_accounts_and_investment_assets_become_positions_with_same_value(): void
    {
        CryptoAccount::query()->create(['name' => 'Ledger BTC', 'currency' => 'btc', 'wallet_reference' => 'Ledger', 'amount_held' => '0.5', 'current_value_eur' => '37000']);
        $bank = BankAccount::query()->create(['name' => 'Principal', 'bank_name' => 'ING']);
        $fund = Asset::query()->create(['bank_account_id' => $bank->id, 'name' => 'Fondo Naranja', 'type' => AssetType::FONDO_INVERSION, 'current_value' => '12000']);
        $fund->valueSnapshots()->create(['date' => '2026-01-31', 'value' => '11000', 'currency' => 'EUR']);
        $account = Asset::query()->create(['bank_account_id' => $bank->id, 'name' => 'Cuenta nómina', 'type' => AssetType::CUENTA_CORRIENTE, 'current_value' => '3000']);

        $this->migrate();

        $summary = app(PortfolioService::class)->summary(now()->addDay());
        $this->assertSame('49000.00', $summary['value_eur']);
        $this->assertSame(2, InvestmentTransaction::query()->where('type', InvestmentTransactionType::ADJUSTMENT)->count());

        // Sin instrumento enlazado, el origen pasa a un instrumento manual con su valor como precio.
        $btc = Instrument::query()->where('name', 'BTC')->sole();
        $this->assertSame(MarketProvider::MANUAL, $btc->provider);
        $this->assertSame(InstrumentType::CRYPTO, $btc->type);
        $this->assertNull(collect($summary['positions'])->firstWhere('instrument', 'BTC')['market_symbol']);

        $this->assertNotNull(DB::table('crypto_accounts')->value('migrated_at'));
        $this->assertNotNull(DB::table('assets')->where('id', $fund->id)->value('migrated_at'));
        $this->assertNull(DB::table('assets')->where('id', $account->id)->value('migrated_at'));

        // El valor y el historial del activo quedan como velas planas del instrumento manual.
        $fundIds = Instrument::query()->where('name', 'Fondo Naranja')->pluck('id');
        $fundBars = PriceBar::query()->whereIn('instrument_id', $fundIds)->orderBy('date')->get();
        $this->assertCount(2, $fundBars);
        $this->assertSame($fundBars[0]->close, $fundBars[0]->high);
        $this->assertSame('11000.00000000', $fundBars[0]->close);
        $this->assertSame('12000.00000000', $fundBars[1]->close);

        // Las cuentas ya migradas no se vuelven a pasar: solo cuenta lo que no tiene migrated_at.
        $this->assertSame(0, DB::table('crypto_accounts')->whereNull('migrated_at')->count());
        $this->migrate();
        $this->assertSame(2, InvestmentTransaction::query()->count());
    }

    public function test_sources_linked_to_a_market_instrument_use_it_and_its_quotes(): void
    {
        $instrument = Instrument::query()->create([
            'symbol' => 'IWDA', 'name' => 'MSCI World', 'type' => InstrumentType::ETF,
            'provider' => MarketProvider::YAHOO, 'provider_symbol' => 'IWDA.AS', 'currency' => 'EUR',
        ]);
        PriceBar::query()->create(['instrument_id' => $instrument->id, 'date' => now()->toDateString(), 'open' => 100, 'high' => 100, 'low' => 100, 'close' => 100]);

        $bank = BankAccount::query()->create(['name' => 'Broker', 'bank_name' => 'MyInvestor']);
        Asset::query()->create([
            'bank_account_id' => $bank->id, 'instrument_id' => $instrument->id, 'name' => 'ETF MSCI World',
            'type' => AssetType::ACCIONES, 'current_value' => '900', 'units' => '10',
        ]);

        $this->migrate();

        // 10 participaciones apuntadas a 90 € cada una de coste, valoradas hoy a 100 €.
        $position = app(PortfolioService::class)->summary(now()->addDay())['positions'][0];
        $this->assertSame($instrument->id, $position['instrument_id']);
        $this->assertSame('900.00', $position['cost_eur']);
        $this->assertSame('1000.00', $position['value_eur']);
        $this->assertSame(1, Instrument::query()->count());
    }
}
