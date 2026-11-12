<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Gastos. `status` es App\Enums\ExpenseStatus guardado como texto (sin enums nativos de PostgreSQL). */
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('establishment_id')->nullable()->index();
            $table->timestampTz('date', 0);
            $table->decimal('total', 10, 2)->nullable();
            $table->string('import_csv_path')->nullable();
            $table->string('ticket_photo_hash')->nullable();
            $table->string('status', 16)->default('PAID');
            $table->boolean('pending_review')->default(false);   // creado por OCR: queda para revision manual aunque cuadre
            $table->timestamps(0);

            $table->foreign('establishment_id')->references('id')->on('establishments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
