<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'chat';

    /**
     * Estado por instancia de la revision de gastos (interfaz «cards»): el mazo actual (los gastos pendientes de revisar de ese momento),
     * las decisiones de cada tarjeta y los eventos versionados de Watch. Vive en el SQLite del disco de la instancia.
     */
    public function up(): void
    {
        Schema::connection('chat')->create('cards_mazos', function (Blueprint $table): void {
            $table->string('deck_id', 64)->primary();
            $table->string('titulo', 256);
            $table->json('tarjetas');
            $table->unsignedBigInteger('creado_ms');
            $table->unsignedBigInteger('actualizado_ms');
            $table->timestamps();
        });

        // La version de cada evento es su id autoincremental: crece siempre y no se reutiliza, ni tras reiniciar.
        Schema::connection('chat')->create('cards_eventos', function (Blueprint $table): void {
            $table->id();
            $table->string('deck_id', 64);
            $table->string('tipo', 16);
            $table->json('carga');
            $table->unsignedBigInteger('creado_ms');
        });

        Schema::connection('chat')->create('cards_decisiones', function (Blueprint $table): void {
            $table->id();
            $table->string('deck_id', 64)->index();
            $table->string('item_id', 64);
            $table->string('action_id', 64);
            $table->string('idempotency_key', 64)->unique();
            $table->boolean('undo')->default(false);
            $table->boolean('deshecha')->default(false);
            $table->unsignedBigInteger('acted_ms')->nullable();
            $table->json('efecto')->nullable();      // lo que cambio en el gasto, para poder deshacerlo exactamente
            $table->unsignedBigInteger('creado_ms');

            $table->index(['deck_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('chat')->dropIfExists('cards_decisiones');
        Schema::connection('chat')->dropIfExists('cards_eventos');
        Schema::connection('chat')->dropIfExists('cards_mazos');
    }
};
