<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Carteras demo (puntos, sin dinero real) y sus operaciones. side es App\Enums\TradeSide como texto. */
    public function up(): void
    {
        Schema::create('demo_portfolios', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->decimal('initial_points', 20, 2);
            $table->date('start_date');
            $table->decimal('fee_fixed', 20, 2)->default(0);
            $table->decimal('fee_percent', 8, 4)->default(0);
            $table->uuid('benchmark_instrument_id')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps(0);

            $table->foreign('benchmark_instrument_id')->references('id')->on('instruments')->nullOnDelete();
        });

        Schema::create('demo_trades', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('demo_portfolio_id');
            $table->uuid('instrument_id')->index();
            $table->date('date');
            $table->string('side', 8);
            $table->decimal('units', 24, 8);
            $table->decimal('price', 20, 8);
            $table->decimal('fx_rate', 20, 8)->default(1);
            $table->decimal('fee', 20, 8)->default(0);
            $table->decimal('total_points', 20, 8);
            $table->timestamps(0);

            $table->foreign('demo_portfolio_id')->references('id')->on('demo_portfolios')->cascadeOnDelete();
            $table->foreign('instrument_id')->references('id')->on('instruments')->cascadeOnDelete();
            $table->index(['demo_portfolio_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_trades');
        Schema::dropIfExists('demo_portfolios');
    }
};
