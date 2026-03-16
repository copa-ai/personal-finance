<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocr_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('expense_id')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('PENDING');
            $table->string('ticket_path', 255)->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('items_imported')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->longText('error_trace')->nullable();
            $table->timestampsTz();

            $table->foreign('expense_id')
                ->references('id')
                ->on('expenses')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_jobs');
    }
};
