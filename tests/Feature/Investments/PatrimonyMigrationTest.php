<?php

namespace Tests\Feature\Investments;

use App\Enums\AssetType;
use App\Enums\InvestmentTransactionType;
use App\Models\Asset;
use App\Models\BankAccount;
use App\Models\CryptoAccount;
use App\Models\InvestmentTransaction;
use App\Services\Portfolio\PortfolioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PatrimonyMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_crypto_accounts_and_investment_assets_become_positions_with_same_value(): void
    {
        $migration = require database_path('migrations/2026_10_12_000002_migrate_patrimony_to_investments.php');
        // Se deshace para cargar datos "de antes" y volver a migrar.
        $migration->down();

        CryptoAccount::query()->create(['name' => 'Ledger BTC', 'currency' => 'btc', 'wallet_reference' => 'Ledger', 'amount_held' => '0.5', 'current_value_eur' => '37000']);
        $bank = BankAccount::query()->create(['name' => 'Principal', 'bank_name' => 'ING']);
        $fund = Asset::query()->create(['bank_account_id' => $bank->id, 'name' => 'Fondo Naranja', 'type' => AssetType::FONDO_INVERSION, 'current_value' => '12000']);
        $fund->valueSnapshots()->create(['date' => '2026-01-31', 'value' => '11000', 'currency' => 'EUR']);
        $account = Asset::query()->create(['bank_account_id' => $bank->id, 'name' => 'Cuenta nómina', 'type' => AssetType::CUENTA_CORRIENTE, 'current_value' => '3000']);

        $migration->up();

        $summary = app(PortfolioService::class)->summary(now()->addDay());
        $this->assertSame('49000.00', $summary['value_eur']);
        $this->assertSame(2, InvestmentTransaction::query()->where('type', InvestmentTransactionType::ADJUSTMENT)->count());
        $this->assertSame('crypto:BTC-EUR', collect($summary['positions'])->firstWhere('instrument', 'BTC')['market_symbol']);

        $this->assertNotNull(DB::table('crypto_accounts')->value('migrated_at'));
        $this->assertNotNull(DB::table('assets')->where('id', $fund->id)->value('migrated_at'));
        $this->assertNull(DB::table('assets')->where('id', $account->id)->value('migrated_at'));
        $this->assertSame(2, DB::table('instrument_prices')->where('price', '>', 10000)->where('price', '<', 13000)->count());

        // Las cuentas ya migradas no se vuelven a pasar: solo cuenta lo que no tiene migrated_at.
        $this->assertSame(0, DB::table('crypto_accounts')->whereNull('migrated_at')->count());
    }
}
