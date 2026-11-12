<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Los archivos que se intercambian con el agente del chat (encargos/chat-N/ de entrada y workspace/chat-acciones-N.json de salida),
     * en la base de datos en vez de en una carpeta compartida entre el usuario de Laravel y el del agente. Son efimeros (se borran al
     * acabar cada turno), por eso no se auditan ni se fusionan. El contenido va en base64: asi es texto en cualquier motor.
     */
    public function up(): void
    {
        Schema::create('agente_archivos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('ruta')->unique();          // relativa a agente/: «encargos/chat-ID/LEEME.json», «workspace/chat-acciones-ID.json»
            $table->longText('contenido');             // base64
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agente_archivos');
    }
};
