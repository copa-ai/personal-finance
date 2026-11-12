<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Notas rapidas (rarq/filament-quick-notes): la nota pertenece a un usuario (uuid). Su modelo es del paquete: clave numerica. */
    public function up(): void
    {
        Schema::create(config('filament-quick-notes.table_name', 'filament_quick_notes'), function (Blueprint $table): void {
            $table->id();
            $table->uuidMorphs('user');
            $table->string('title')->nullable();
            $table->text('content')->nullable();
            $table->string('color');
            $table->integer('order')->default(0);
            $table->timestamps(0);
            $table->softDeletes('deleted_at', 0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('filament-quick-notes.table_name', 'filament_quick_notes'));
    }
};
