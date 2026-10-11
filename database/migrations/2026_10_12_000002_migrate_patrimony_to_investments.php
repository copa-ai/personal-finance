<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Pasa las cuentas cripto y los activos de inversión (fondos, acciones, planes de
 * pensiones, depósitos) al libro de movimientos. No borra nada: marca el origen con
 * migrated_at y los listados antiguos los ocultan.
 *
 * Como no se conoce el histórico de compras, cada uno entra con un movimiento de
 * "Ajuste de saldo" valorado a su valor actual: ese valor pasa a ser el coste.
 */
return new class extends Migration
{
    private const ASSET_TYPES = [
        'FONDO_INVERSION' => ['platform' => 'FUND_MANAGER', 'kind' => 'FUND', 'class' => 'MIXED'],
        'ACCIONES' => ['platform' => 'BROKER', 'kind' => 'STOCK', 'class' => 'EQUITY'],
        'PLAN_PENSIONES' => ['platform' => 'PENSION_PLAN', 'kind' => 'PENSION_PLAN', 'class' => 'MIXED'],
        'DEPOSITO' => ['platform' => 'BANK', 'kind' => 'DEPOSIT', 'class' => 'CASH'],
    ];

    public function up(): void
    {
        Schema::table('crypto_accounts', fn (Blueprint $table) => $table->timestampTz('migrated_at')->nullable());
        Schema::table('assets', fn (Blueprint $table) => $table->timestampTz('migrated_at')->nullable());

        $now = now();

        foreach (DB::table('crypto_accounts')->whereNull('migrated_at')->get() as $account) {
            $date = Carbon::parse($account->updated_at ?? $now)->toDateString();
            $currency = strtoupper(trim($account->currency));
            $amount = (string) $account->amount_held;
            $value = $account->current_value_eur;
            $price = ($value !== null && bccomp($amount, '0', 10) > 0) ? bcdiv((string) $value, $amount, 10) : null;

            $platformId = $this->platform(
                $account->wallet_reference ?: $account->name,
                'WALLET',
                "Migrada desde Cuentas cripto. Estado fiscal: {$account->tax_status}." . ($account->notes ? "\n{$account->notes}" : ''),
            );

            $instrumentId = $this->instrument([
                'name' => $currency,
                'kind' => 'CRYPTO',
                'asset_class' => 'CRYPTO',
                'ticker' => $currency,
                'currency' => 'EUR',
                'market_symbol' => preg_match('/^[A-Z0-9]{2,10}$/', $currency) ? "crypto:{$currency}-EUR" : null,
            ]);

            $this->adjustment($platformId, $instrumentId, $date, $amount, $price, "Saldo migrado de \"{$account->name}\".");

            if ($price !== null) {
                $this->manualPrice($instrumentId, $date, $price);
            }

            DB::table('crypto_accounts')->where('id', $account->id)->update(['migrated_at' => $now]);
        }

        $assets = DB::table('assets')
            ->join('bank_accounts', 'assets.bank_account_id', '=', 'bank_accounts.id')
            ->whereNull('assets.migrated_at')
            ->whereIn(DB::raw('assets.type::text'), array_keys(self::ASSET_TYPES))
            ->select('assets.*', 'bank_accounts.bank_name', 'bank_accounts.name as account_name')
            ->get();

        foreach ($assets as $asset) {
            $map = self::ASSET_TYPES[$asset->type];
            $date = Carbon::parse($asset->updated_at ?? $now)->toDateString();
            $currency = strtoupper($asset->currency ?: 'EUR');

            $platformId = $this->platform($asset->bank_name ?: $asset->account_name, $map['platform'], 'Migrada desde Bancos y activos.');
            $instrumentId = $this->instrument([
                'name' => $asset->name,
                'kind' => $map['kind'],
                'asset_class' => $map['class'],
                'currency' => $currency,
                'notes' => $asset->notes,
            ]);

            // Sin número de participaciones: una unidad que vale lo que vale el activo.
            $this->adjustment($platformId, $instrumentId, $date, '1', (string) $asset->current_value, 'Activo migrado: 1 unidad = valor total. Si conoces las participaciones, corrige este ajuste.', $currency);
            $this->manualPrice($instrumentId, $date, (string) $asset->current_value, $currency);

            // El histórico de valor pasa a ser histórico de precio de esa unidad.
            $snapshots = DB::table('value_snapshots')
                ->where('valuable_type', 'App\\Models\\Asset')
                ->where('valuable_id', $asset->id)
                ->get();

            foreach ($snapshots as $snapshot) {
                $this->manualPrice($instrumentId, Carbon::parse($snapshot->date)->toDateString(), (string) $snapshot->value, $snapshot->currency ?: $currency);
            }

            DB::table('assets')->where('id', $asset->id)->update(['migrated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::table('crypto_accounts', fn (Blueprint $table) => $table->dropColumn('migrated_at'));
        Schema::table('assets', fn (Blueprint $table) => $table->dropColumn('migrated_at'));
    }

    private function platform(string $name, string $type, string $notes): string
    {
        $existing = DB::table('investment_platforms')->whereRaw('LOWER(name) = LOWER(?)', [$name])->value('id');

        if ($existing) {
            return $existing;
        }

        $id = (string) Str::uuid();
        DB::table('investment_platforms')->insert([
            'id' => $id, 'name' => $name, 'type' => $type, 'country' => 'ES', 'notes' => $notes,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function instrument(array $data): string
    {
        $existing = DB::table('instruments')
            ->whereRaw('LOWER(name) = LOWER(?)', [$data['name']])
            ->where('kind', $data['kind'])
            ->value('id');

        if ($existing) {
            return $existing;
        }

        $id = (string) Str::uuid();
        DB::table('instruments')->insert([
            'id' => $id, ...$data, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function adjustment(string $platformId, string $instrumentId, string $date, string $quantity, ?string $price, string $notes, string $currency = 'EUR'): void
    {
        DB::table('investment_transactions')->insert([
            'id' => (string) Str::uuid(),
            'platform_id' => $platformId,
            'instrument_id' => $instrumentId,
            'type' => 'ADJUSTMENT',
            'date' => $date,
            'quantity' => $quantity,
            'price' => $price,
            'fees' => 0,
            'currency' => $currency,
            // Sin tipo de cambio histórico: si no es EUR, corrígelo en el movimiento.
            'fx_rate_eur' => 1,
            'notes' => $notes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function manualPrice(string $instrumentId, string $date, string $price, string $currency = 'EUR'): void
    {
        DB::table('instrument_prices')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'instrument_id' => $instrumentId,
            'date' => $date,
            'price' => $price,
            'currency' => $currency,
            'source' => 'manual',
            'created_at' => now(),
        ]);
    }
};
