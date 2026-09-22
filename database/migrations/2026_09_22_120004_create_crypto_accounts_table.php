<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crypto_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('currency', 20);
            $table->string('wallet_reference')->nullable();
            $table->decimal('amount_held', 24, 8)->default(0);
            $table->decimal('current_value_eur', 14, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE crypto_accounts ADD COLUMN tax_status crypto_tax_status_enum NOT NULL DEFAULT 'UNKNOWN'");
    }

    public function down(): void
    {
        Schema::dropIfExists('crypto_accounts');
    }
};
