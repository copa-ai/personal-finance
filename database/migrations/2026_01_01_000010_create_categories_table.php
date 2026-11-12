<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Categorias de gasto en arbol (parent_id). El id es un UUIDv5 del nombre (App\Models\Concerns\UuidDeClaveNatural). */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100)->unique();
            $table->uuid('parent_id')->nullable()->index();
            $table->timestamps(0);
            $table->softDeletes('deleted_at', 0);

            $table->foreign('parent_id')->references('id')->on('categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
