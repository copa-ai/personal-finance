<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TYPE instrument_type_enum ADD VALUE IF NOT EXISTS 'FX'");

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'trade_side_enum') THEN
                    CREATE TYPE trade_side_enum AS ENUM ('BUY', 'SELL');
                END IF;
            END
            $$;
        SQL);

        Schema::create('demo_portfolios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->decimal('initial_points', 20, 2);
            $table->date('start_date');
            $table->decimal('fee_fixed', 20, 2)->default(0);
            $table->decimal('fee_percent', 8, 4)->default(0);
            $table->uuid('benchmark_instrument_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->foreign('benchmark_instrument_id')
                ->references('id')
                ->on('instruments')
                ->nullOnDelete();
        });

        Schema::create('demo_trades', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('demo_portfolio_id');
            $table->uuid('instrument_id');
            $table->date('date');
            $table->decimal('units', 24, 8);
            $table->decimal('price', 20, 8);
            $table->decimal('fx_rate', 20, 8)->default(1);
            $table->decimal('fee', 20, 8)->default(0);
            $table->decimal('total_points', 20, 8);
            $table->timestampsTz();

            $table->foreign('demo_portfolio_id')
                ->references('id')
                ->on('demo_portfolios')
                ->onDelete('cascade');
            $table->foreign('instrument_id')
                ->references('id')
                ->on('instruments')
                ->onDelete('cascade');
            $table->index(['demo_portfolio_id', 'date']);
        });

        DB::statement('ALTER TABLE demo_trades ADD COLUMN side trade_side_enum NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_trades');
        Schema::dropIfExists('demo_portfolios');

        DB::statement('DROP TYPE IF EXISTS trade_side_enum');
        // Los valores de un enum de Postgres no se pueden quitar: FX permanece en instrument_type_enum.
    }
};
