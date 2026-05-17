<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Reference to establishment (nullable). Uses unsignedBigInteger
            // to remain compatible with autoincrement id on establishments.
            $table->unsignedBigInteger('establishment_id')->nullable()->index();
            $table->timestampTz('date');
            $table->decimal('total', 10, 2)->nullable();
            $table->string('import_csv_path', 255)->nullable();
            $table->string('ticket_photo_hash', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->boolean('pending_review')->default(false);
        });

        // Add the enum column via raw SQL since Laravel doesn't natively support PG enums in Blueprint
        DB::statement("ALTER TABLE expenses ADD COLUMN status expense_status_enum NOT NULL DEFAULT 'PAID'");
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
