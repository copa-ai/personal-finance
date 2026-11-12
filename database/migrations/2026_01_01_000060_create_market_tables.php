<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Instrumentos de mercado y sus velas diarias. type es App\Enums\InstrumentType y provider App\Enums\MarketProvider, como texto.
     * Los precios (price_bars) se resincronizan con `php artisan` (SyncMarketData): no se fusionan ni se auditan.
     */
    public function up(): void
    {
        Schema::create('instruments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('symbol', 40)->unique();
            $table->string('isin', 12)->nullable();
            $table->string('name');
            $table->string('type', 16);
            $table->string('provider', 16);
            $table->string('provider_symbol', 80);
            $table->string('currency', 20)->default('EUR');
            $table->boolean('active')->default(true);
            $table->timestamps(0);
        });

        Schema::create('price_bars', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('instrument_id');
            $table->date('date');
            $table->decimal('open', 20, 8);
            $table->decimal('high', 20, 8);
            $table->decimal('low', 20, 8);
            $table->decimal('close', 20, 8);
            $table->decimal('volume', 28, 8)->nullable();

            $table->foreign('instrument_id')->references('id')->on('instruments')->cascadeOnDelete();
            $table->unique(['instrument_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_bars');
        Schema::dropIfExists('instruments');
    }
};
