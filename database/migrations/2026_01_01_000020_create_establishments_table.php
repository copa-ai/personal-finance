<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Establecimientos (comercios). El id es un UUIDv5 del nombre (App\Models\Concerns\UuidDeClaveNatural). */
    public function up(): void
    {
        Schema::create('establishments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->uuid('category_id')->nullable()->index();
            $table->timestamps(0);

            $table->foreign('category_id')->references('id')->on('categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('establishments');
    }
};
