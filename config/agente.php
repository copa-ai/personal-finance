<?php

/*
 * Ejecucion del agente desde Laravel: claude -p en la carpeta agente/ (el chat con Claude, ver App\Services\Chat\Claude).
 * La instantanea de solo lectura de cada turno se exporta a agente/encargos/chat-N/ y el agente deja sus acciones propuestas
 * en agente/workspace/chat-acciones-N.json, que Laravel valida y aplica.
 */
return [
    'carpeta' => env('AGENTE_CARPETA', base_path('agente')),

    // Donde viven los archivos que se intercambian con el agente (encargos/, workspace/, base/). «base» = en la base de datos
    // (tabla agente_archivos, ver App\Services\Agente\AlmacenAgente): el runner los recibe y devuelve por HTTP y asi los ve cualquier
    // Laravel que comparta la base. Vacio = solo en la carpeta de arriba (desarrollo y pruebas).
    'almacen' => env('AGENTE_ALMACEN') ?: null,

    'claude' => env('AGENTE_CLAUDE', 'claude'),

    // Modelo que se pasa a `claude -p --model` en todas las ejecuciones.
    'modelo' => env('AGENTE_MODELO', 'claude-sonnet-5-5'),

    // Donde corre claude. Con AGENTE_URL (el runner de docker/agente, 127.0.0.1:9000) Laravel le pide las ejecuciones por HTTP y no
    // ejecuta claude ella misma. Vacio = lo lanza en el propio proceso (desarrollo, pruebas).
    'remoto' => [
        'url' => env('AGENTE_URL') ?: null,
        'secreto' => env('AGENTE_SECRETO'),
        // Milisegundos entre consultas del avance de una ejecucion.
        'espera_ms' => (int) env('AGENTE_ESPERA_MS', 1000),
    ],
];
