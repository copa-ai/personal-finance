<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_platforms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            // País de la plataforma: las que están fuera de España cuentan para el Modelo 720/721.
            $table->string('country', 2)->default('ES');
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE investment_platforms ADD COLUMN type platform_type_enum NOT NULL DEFAULT 'OTHER'");

        Schema::create('instruments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('isin', 12)->nullable()->index();
            $table->string('ticker', 30)->nullable();
            $table->string('currency', 3)->default('EUR');
            // Símbolo canónico de pf-market-data (p. ej. "crypto:BTC-EUR", "etf:IWDA.AS").
            // Si existe, el precio se actualiza solo; si no, se apunta a mano.
            $table->string('market_symbol', 60)->nullable()->index();
            // Depósitos y bonos: fecha de vencimiento para el recordatorio.
            $table->date('maturity_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE instruments ADD COLUMN kind instrument_kind_enum NOT NULL DEFAULT 'OTHER'");
        DB::statement("ALTER TABLE instruments ADD COLUMN asset_class asset_class_enum NOT NULL DEFAULT 'OTHER'");

        Schema::create('investment_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('platform_id');
            $table->uuid('instrument_id');
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
            $table->timestampsTz();

            $table->foreign('platform_id')->references('id')->on('investment_platforms')->cascadeOnDelete();
            $table->foreign('instrument_id')->references('id')->on('instruments')->cascadeOnDelete();
            $table->index(['instrument_id', 'date']);
        });
        DB::statement('ALTER TABLE investment_transactions ADD COLUMN type investment_transaction_type_enum NOT NULL');

        Schema::create('instrument_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('instrument_id');
            $table->date('date');
            $table->decimal('price', 24, 10);
            $table->string('currency', 3);
            // "manual" o el proveedor que dio el precio (yahoo, kraken, …).
            $table->string('source', 30)->default('manual');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('instrument_id')->references('id')->on('instruments')->cascadeOnDelete();
            $table->unique(['instrument_id', 'date', 'source']);
        });

        Schema::create('fx_rates', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('currency', 3);
            // Como publica el BCE: unidades de la divisa por 1 EUR.
            $table->decimal('units_per_eur', 20, 10);
            $table->string('source', 30)->default('manual');
            $table->unique(['date', 'currency']);
        });

        Schema::create('target_allocations', function (Blueprint $table) {
            $table->id();
            $table->decimal('target_pct', 5, 2);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE target_allocations ADD COLUMN asset_class asset_class_enum NOT NULL UNIQUE');

        Schema::create('contribution_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->uuid('platform_id');
            $table->uuid('instrument_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('EUR');
            $table->date('next_due_on');
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->foreign('platform_id')->references('id')->on('investment_platforms')->cascadeOnDelete();
            $table->foreign('instrument_id')->references('id')->on('instruments')->nullOnDelete();
        });
        DB::statement("ALTER TABLE contribution_plans ADD COLUMN frequency contribution_frequency_enum NOT NULL DEFAULT 'MONTHLY'");

        Schema::create('reminders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('body')->nullable();
            $table->date('due_on');
            $table->nullableUuidMorphs('remindable');
            // Evita duplicar el mismo aviso cada vez que corre el comando diario.
            $table->string('dedupe_key')->nullable()->unique();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE reminders ADD COLUMN kind reminder_kind_enum NOT NULL DEFAULT 'CUSTOM'");
        DB::statement("ALTER TABLE reminders ADD COLUMN status reminder_status_enum NOT NULL DEFAULT 'PENDING'");

        Schema::create('price_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('market_symbol', 60);
            $table->decimal('threshold', 24, 10);
            $table->boolean('active')->default(true);
            $table->timestampTz('last_triggered_at')->nullable();
            $table->decimal('last_price', 24, 10)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE price_alerts ADD COLUMN condition price_alert_condition_enum NOT NULL');

        Schema::create('watchlists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampsTz();
        });

        Schema::create('watchlist_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('watchlist_id');
            $table->string('market_symbol', 60);
            $table->string('label')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampsTz();

            $table->foreign('watchlist_id')->references('id')->on('watchlists')->cascadeOnDelete();
            $table->unique(['watchlist_id', 'market_symbol']);
        });

        Schema::create('portfolio_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->decimal('value_eur', 16, 2);
            $table->decimal('invested_eur', 16, 2);
            $table->jsonb('by_asset_class')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
        });

        // Las transferencias al broker no son gasto: se excluyen de las estadísticas
        // si el establecimiento está enlazado a una plataforma de inversión.
        Schema::table('establishments', function (Blueprint $table) {
            $table->uuid('investment_platform_id')->nullable()->index();
            $table->foreign('investment_platform_id')->references('id')->on('investment_platforms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            $table->dropForeign(['investment_platform_id']);
            $table->dropColumn('investment_platform_id');
        });

        foreach ([
            'portfolio_snapshots', 'watchlist_items', 'watchlists', 'price_alerts', 'reminders',
            'contribution_plans', 'target_allocations', 'fx_rates', 'instrument_prices',
            'investment_transactions', 'instruments', 'investment_platforms',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
