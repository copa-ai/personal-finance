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
        });

        // Aparte de la creacion: en PostgreSQL la clave foranea a la propia tabla se emitia antes que la clave primaria
        // (los indices de columna van despues de los comandos explicitos) y fallaba con «no unique constraint matching given keys».
        Schema::table('categories', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
