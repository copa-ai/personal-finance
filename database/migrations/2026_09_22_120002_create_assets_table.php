<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('bank_account_id');
            $table->string('name');
            $table->decimal('current_value', 14, 2)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->foreign('bank_account_id')
                ->references('id')
                ->on('bank_accounts')
                ->onDelete('cascade');
        });

        DB::statement("ALTER TABLE assets ADD COLUMN type asset_type_enum NOT NULL DEFAULT 'OTRO'");
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
