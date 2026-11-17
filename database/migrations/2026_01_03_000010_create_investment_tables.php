<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inversiones: plataformas, libro de movimientos, asignacion objetivo, aportaciones periodicas y recordatorios. Los tipos son enums de
     * App\Enums (PlatformType, InvestmentTransactionType, AssetClass, ContributionFrequency, ReminderKind y ReminderStatus) guardados
     * como texto. Los precios y los tipos de cambio no tienen tabla propia: son las velas de `price_bars` (un precio manual es una vela
     * con open = high = low = close de un instrumento con proveedor MANUAL). `portfolio_snapshots` es una foto derivada que se rehace con
     * `investments:snapshot`: como `price_bars`, ni se fusiona ni se audita.
     */
    private const AUDITADAS = [
        'investment_platforms' => 'App\Models\InvestmentPlatform',
        'investment_transactions' => 'App\Models\InvestmentTransaction',
        'target_allocations' => 'App\Models\TargetAllocation',
        'contribution_plans' => 'App\Models\ContributionPlan',
        'reminders' => 'App\Models\Reminder',
    ];

    /** fusion_tablas() antes de esta migracion. */
    private const FUSION_ANTES = ['users', 'categories', 'establishments', 'expenses', 'expense_items', 'instruments', 'bank_accounts', 'assets',
        'asset_documents', 'crypto_accounts', 'value_snapshots', 'demo_portfolios', 'demo_trades', 'comments', 'notifications', 'ajustes'];

    /** En orden de dependencias: lo referenciado antes que lo que referencia. */
    private const FUSION_DESPUES = ['users', 'categories', 'investment_platforms', 'establishments', 'expenses', 'expense_items', 'instruments',
        'investment_transactions', 'contribution_plans', 'target_allocations', 'reminders', 'bank_accounts', 'assets', 'asset_documents',
        'crypto_accounts', 'value_snapshots', 'demo_portfolios', 'demo_trades', 'comments', 'notifications', 'ajustes'];

    public function up(): void
    {
        // Un instrumento manual no tiene simbolo en ningun proveedor.
        Schema::table('instruments', function (Blueprint $table): void {
            $table->string('provider_symbol', 80)->nullable()->change();
            $table->string('asset_class', 16)->nullable();
            $table->date('maturity_date')->nullable();
            $table->text('notes')->nullable();
        });

        Schema::create('investment_platforms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('type', 16)->default('OTHER');
            // Pais de la plataforma: las que estan fuera de Espana cuentan para el Modelo 720/721.
            $table->string('country', 2)->default('ES');
            $table->text('notes')->nullable();
            $table->timestamps(0);
        });

        Schema::create('investment_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('platform_id');
            $table->uuid('instrument_id');
            $table->string('type', 16);
            $table->date('date');
            // Unidades del movimiento (siempre positivas salvo ADJUSTMENT, que lleva signo).
            // En SPLIT es el factor: 2 para un split 2:1, 0.1 para un contrasplit 1:10.
            $table->decimal('quantity', 28, 10)->default(0);
            // Precio por unidad en la divisa del movimiento.
            $table->decimal('price', 24, 10)->nullable();
            // Importe en efectivo de dividendos, intereses y comisiones sueltas.
            $table->decimal('amount', 16, 2)->nullable();
            $table->decimal('fees', 14, 2)->default(0);
            $table->string('currency', 3)->default('EUR');
            // Euros por unidad de la divisa del movimiento en esa fecha (1 si es EUR).
            $table->decimal('fx_rate_eur', 20, 10)->default(1);
            $table->text('notes')->nullable();
            $table->timestamps(0);

            $table->foreign('platform_id')->references('id')->on('investment_platforms')->cascadeOnDelete();
            $table->foreign('instrument_id')->references('id')->on('instruments')->cascadeOnDelete();
            $table->index(['instrument_id', 'date']);
        });

        Schema::create('target_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('asset_class', 16)->unique();
            $table->decimal('target_pct', 5, 2);
            $table->timestamps(0);
        });

        Schema::create('contribution_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->uuid('platform_id');
            $table->uuid('instrument_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('EUR');
            $table->string('frequency', 16)->default('MONTHLY');
            $table->date('next_due_on');
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps(0);

            $table->foreign('platform_id')->references('id')->on('investment_platforms')->cascadeOnDelete();
            $table->foreign('instrument_id')->references('id')->on('instruments')->nullOnDelete();
        });

        Schema::create('reminders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind', 24)->default('CUSTOM');
            $table->string('status', 16)->default('PENDING');
            $table->string('title');
            $table->text('body')->nullable();
            $table->date('due_on');
            $table->nullableUuidMorphs('remindable');
            // Evita duplicar el mismo aviso cada vez que corre el comando diario.
            $table->string('dedupe_key')->nullable()->unique();
            $table->timestamp('resolved_at', 0)->nullable();
            $table->timestamps(0);
        });

        Schema::create('portfolio_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->date('date')->unique();
            $table->decimal('value_eur', 16, 2);
            $table->decimal('invested_eur', 16, 2);
            $table->jsonb('by_asset_class')->default('{}');
            $table->timestamp('created_at', 0)->useCurrent();
        });

        // Las transferencias al broker no son gasto: se excluyen de las estadisticas
        // si el establecimiento esta enlazado a una plataforma de inversion.
        Schema::table('establishments', function (Blueprint $table): void {
            $table->uuid('investment_platform_id')->nullable()->index();
            $table->foreign('investment_platform_id')->references('id')->on('investment_platforms')->nullOnDelete();
        });

        // Lo que ya se paso al libro (ver la migracion siguiente) queda marcado y las pantallas antiguas lo ocultan.
        Schema::table('assets', fn (Blueprint $table) => $table->timestamp('migrated_at', 0)->nullable());
        Schema::table('crypto_accounts', fn (Blueprint $table) => $table->timestamp('migrated_at', 0)->nullable());

        foreach (self::AUDITADAS as $tabla => $modelo) {
            DB::select('SELECT auditar_tabla(?, ?)', [$tabla, $modelo]);
        }

        $this->fusionTablas(self::FUSION_DESPUES);
    }

    public function down(): void
    {
        $this->fusionTablas(self::FUSION_ANTES);

        DB::table('auditoria_tablas')->whereIn('tabla', array_keys(self::AUDITADAS))->delete();

        Schema::table('crypto_accounts', fn (Blueprint $table) => $table->dropColumn('migrated_at'));
        Schema::table('assets', fn (Blueprint $table) => $table->dropColumn('migrated_at'));

        Schema::table('establishments', function (Blueprint $table): void {
            $table->dropForeign(['investment_platform_id']);
            $table->dropColumn('investment_platform_id');
        });

        foreach (['portfolio_snapshots', 'reminders', 'contribution_plans', 'target_allocations', 'investment_transactions', 'investment_platforms'] as $tabla) {
            Schema::dropIfExists($tabla);
        }

        Schema::table('instruments', function (Blueprint $table): void {
            $table->dropColumn(['asset_class', 'maturity_date', 'notes']);
        });
        // provider_symbol vuelve a ser obligatorio solo si no hay instrumentos manuales (sin simbolo).
        DB::table('instruments')->whereNull('provider_symbol')->update(['provider_symbol' => DB::raw('symbol')]);
        Schema::table('instruments', fn (Blueprint $table) => $table->string('provider_symbol', 80)->nullable(false)->change());
    }

    /** @param  list<string>  $tablas */
    private function fusionTablas(array $tablas): void
    {
        $lista = implode(', ', array_map(fn (string $tabla): string => "'{$tabla}'", $tablas));

        DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION fusion_tablas() RETURNS text[] LANGUAGE sql IMMUTABLE AS \$f\$
    -- En orden de dependencias: lo referenciado antes que lo que referencia.
    SELECT ARRAY[{$lista}]
\$f\$
SQL);
    }
};
