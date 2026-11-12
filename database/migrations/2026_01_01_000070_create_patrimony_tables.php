<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Patrimonio: bancos, activos, documentos, cripto y fotos de valor. type y tax_status son App\Enums\AssetType y CryptoTaxStatus. */
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('bank_name');
            $table->string('iban', 34)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->text('notes')->nullable();
            $table->timestamps(0);
        });

        Schema::create('assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('bank_account_id')->index();
            $table->uuid('instrument_id')->nullable()->index();
            $table->string('name');
            $table->string('type', 32)->default('OTRO');
            $table->decimal('current_value', 14, 2)->default(0);
            $table->decimal('units', 24, 8)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->text('notes')->nullable();
            $table->timestamps(0);

            $table->foreign('bank_account_id')->references('id')->on('bank_accounts')->cascadeOnDelete();
            $table->foreign('instrument_id')->references('id')->on('instruments')->nullOnDelete();
        });

        Schema::create('asset_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('asset_id')->index();
            $table->string('title');
            $table->string('file_path')->nullable();
            $table->date('issued_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps(0);

            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
        });

        Schema::create('crypto_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('instrument_id')->nullable()->index();
            $table->string('name');
            $table->string('currency', 20);
            $table->string('wallet_reference')->nullable();
            $table->decimal('amount_held', 24, 8)->default(0);
            $table->decimal('current_value_eur', 14, 2)->nullable();
            $table->string('tax_status', 16)->default('UNKNOWN');
            $table->text('notes')->nullable();
            $table->timestamps(0);

            $table->foreign('instrument_id')->references('id')->on('instruments')->nullOnDelete();
        });

        // Fotos de valor de un activo o de una cuenta cripto (relacion polimorfica: valuable_type + valuable_id uuid).
        Schema::create('value_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuidMorphs('valuable');
            $table->date('date');
            $table->decimal('value', 14, 2);
            $table->string('currency', 3)->default('EUR');
            $table->text('notes')->nullable();
            $table->timestamp('created_at', 0)->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('value_snapshots');
        Schema::dropIfExists('crypto_accounts');
        Schema::dropIfExists('asset_documents');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('bank_accounts');
    }
};
