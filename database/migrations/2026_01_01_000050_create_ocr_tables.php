<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estado local del OCR de tickets: los servicios OCR subidos como .bee (de este dispositivo) y los trabajos de lectura. No se
     * fusionan ni se auditan entre bases: rutas de disco, URIs y estados que solo valen en la instancia que los creo.
     */
    public function up(): void
    {
        Schema::create('ocr_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('expense_id')->index();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('PENDING');
            $table->string('ticket_path')->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('items_imported')->nullable();
            $table->timestamp('started_at', 0)->nullable();
            $table->timestamp('finished_at', 0)->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->longText('error_trace')->nullable();
            $table->timestamps(0);

            $table->foreign('expense_id')->references('id')->on('expenses')->cascadeOnDelete();
        });

        Schema::create('ocr_providers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100)->nullable();
            $table->string('service_hash', 128)->nullable();
            $table->string('bee_path');
            $table->string('uri')->nullable();
            $table->string('status', 20)->default('STOPPED')->index();
            $table->string('error_message', 1000)->nullable();
            $table->timestamps(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_providers');
        Schema::dropIfExists('ocr_jobs');
    }
};
