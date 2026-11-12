<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Notificaciones del panel (campana). `data` es jsonb: Filament la consulta con `data->>'format'`, que en PostgreSQL no existe sobre text. */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->uuidMorphs('notifiable');
            $table->jsonb('data');
            $table->timestamp('read_at', 0)->nullable();
            $table->timestamps(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
