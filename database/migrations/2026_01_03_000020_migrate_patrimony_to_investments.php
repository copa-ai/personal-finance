<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pasa las cuentas cripto y los activos de inversion (fondos, acciones, planes de pensiones, depositos) al libro de movimientos. No
 * borra nada: marca el origen con migrated_at y los listados antiguos lo ocultan.
 *
 * Como no se conoce el historico de compras, cada uno entra con un movimiento de «Ajuste de saldo» valorado a su valor actual: ese
 * valor pasa a ser el coste. Si el origen ya esta enlazado a un instrumento de mercado (con unidades), se usa ese instrumento y su
 * precio sale de las velas; si no, se crea un instrumento manual con el valor apuntado como precio.
 */
return new class extends Migration
{
    private const ASSET_TYPES = [
        'FONDO_INVERSION' => ['platform' => 'FUND_MANAGER', 'type' => 'FUND', 'class' => 'MIXED'],
        'ACCIONES' => ['platform' => 'BROKER', 'type' => 'STOCK', 'class' => 'EQUITY'],
        'PLAN_PENSIONES' => ['platform' => 'PENSION_PLAN', 'type' => 'PENSION_PLAN', 'class' => 'MIXED'],
        'DEPOSITO' => ['platform' => 'BANK', 'type' => 'DEPOSIT', 'class' => 'CASH'],
    ];

    public function up(): void
    {
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
                "Migrada desde Cuentas cripto. Estado fiscal: {$account->tax_status}.".($account->notes ? "\n{$account->notes}" : ''),
            );

            $instrumentId = $account->instrument_id ?? $this->manualInstrument([
                'name' => $currency,
                'type' => 'CRYPTO',
                'asset_class' => 'CRYPTO',
                'currency' => 'EUR',
            ]);

            $this->adjustment($platformId, $instrumentId, $date, $amount, $price, "Saldo migrado de \"{$account->name}\".");

            if ($account->instrument_id === null && $price !== null) {
                $this->manualPrice($instrumentId, $date, $price);
            }

            DB::table('crypto_accounts')->where('id', $account->id)->update(['migrated_at' => $now]);
        }

        $assets = DB::table('assets')
            ->join('bank_accounts', 'assets.bank_account_id', '=', 'bank_accounts.id')
            ->whereNull('assets.migrated_at')
            ->whereIn('assets.type', array_keys(self::ASSET_TYPES))
            ->select('assets.*', 'bank_accounts.bank_name', 'bank_accounts.name as account_name')
            ->get();

        foreach ($assets as $asset) {
            $map = self::ASSET_TYPES[$asset->type];
            $date = Carbon::parse($asset->updated_at ?? $now)->toDateString();
            $currency = strtoupper($asset->currency ?: 'EUR');
            $platformId = $this->platform($asset->bank_name ?: $asset->account_name, $map['platform'], 'Migrada desde Bancos y activos.');

            $units = $asset->units !== null ? (string) $asset->units : '0';
            $linked = $asset->instrument_id !== null && bccomp($units, '0', 8) > 0;

            if ($linked) {
                // Instrumento de mercado con sus unidades: se valora con su cotizacion.
                $this->adjustment(
                    $platformId, $asset->instrument_id, $date, $units, bcdiv((string) $asset->current_value, $units, 10),
                    'Activo migrado: unidades al valor que tenia. Corrige este ajuste si conoces el coste real.', $currency,
                );

                DB::table('assets')->where('id', $asset->id)->update(['migrated_at' => $now]);

                continue;
            }

            $instrumentId = $this->manualInstrument([
                'name' => $asset->name,
                'type' => $map['type'],
                'asset_class' => $map['class'],
                'currency' => $currency,
                'notes' => $asset->notes,
            ]);

            // Sin numero de participaciones: una unidad que vale lo que vale el activo.
            $this->adjustment($platformId, $instrumentId, $date, '1', (string) $asset->current_value, 'Activo migrado: 1 unidad = valor total. Si conoces las participaciones, corrige este ajuste.', $currency);
            $this->manualPrice($instrumentId, $date, (string) $asset->current_value);

            // El historico de valor pasa a ser historico de precio de esa unidad.
            $snapshots = DB::table('value_snapshots')
                ->where('valuable_type', 'App\\Models\\Asset')
                ->where('valuable_id', $asset->id)
                ->get();

            foreach ($snapshots as $snapshot) {
                $this->manualPrice($instrumentId, Carbon::parse($snapshot->date)->toDateString(), (string) $snapshot->value);
            }

            DB::table('assets')->where('id', $asset->id)->update(['migrated_at' => $now]);
        }
    }

    public function down(): void
    {
        // Lo migrado queda en el libro: se pudo corregir a mano. Solo se vuelve a mostrar el modelo antiguo.
        DB::table('crypto_accounts')->update(['migrated_at' => null]);
        DB::table('assets')->update(['migrated_at' => null]);
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

    /**
     * Instrumento sin cotizacion (provider MANUAL): se reutiliza el que tenga el mismo nombre y tipo.
     *
     * @param  array{name: string, type: string, asset_class: string, currency: string, notes?: ?string}  $data
     */
    private function manualInstrument(array $data): string
    {
        $existing = DB::table('instruments')
            ->whereRaw('LOWER(name) = LOWER(?)', [$data['name']])
            ->where('type', $data['type'])
            ->where('provider', 'MANUAL')
            ->value('id');

        if ($existing) {
            return $existing;
        }

        $base = Str::upper(Str::limit(Str::slug($data['name'], '-'), 28, ''));
        $id = (string) Str::uuid();

        DB::table('instruments')->insert([
            'id' => $id,
            'symbol' => ($base !== '' ? $base : 'MANUAL').'-'.Str::upper(Str::random(5)),
            'name' => $data['name'],
            'type' => $data['type'],
            'provider' => 'MANUAL',
            'provider_symbol' => null,
            'currency' => $data['currency'],
            'active' => false,
            'asset_class' => $data['asset_class'],
            'notes' => $data['notes'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
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
            // Sin tipo de cambio historico: si no es EUR, corrigelo en el movimiento.
            'fx_rate_eur' => 1,
            'notes' => $notes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Precio manual: una vela con open = high = low = close. */
    private function manualPrice(string $instrumentId, string $date, string $price): void
    {
        DB::table('price_bars')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'instrument_id' => $instrumentId,
            'date' => $date,
            'open' => $price,
            'high' => $price,
            'low' => $price,
            'close' => $price,
        ]);
    }
};
