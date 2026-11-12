<?php

namespace App\Services\Chat\Bot;

use App\Services\Chat\Marcado\Tecla;
use App\Services\Chat\Marcado\Teclado;

/** Los teclados (bloques keyboard) que ofrece el bot. Todo se genera con Teclado y Tecla: nunca a mano. */
final class Formularios
{
    /** El teclado de las acciones principales (saludo, ayuda, no entiendo). */
    public static function acciones(): Teclado
    {
        return Teclado::nuevo()
            ->fila(Tecla::de('Gasto de hoy', 'cuánto he gastado hoy')->enviando(), Tecla::de('Gasto del mes', 'cuánto he gastado este mes')->enviando())
            ->fila(Tecla::de('Por categoría', 'gasto por categoría este mes')->enviando(), Tecla::de('Pendiente de revisar', 'qué hay pendiente de revisar')->enviando())
            ->fila(Tecla::de('Registrar un ticket', 'quiero registrar un ticket')->enviando(), Tecla::de('Revisar tickets', 'revisar tickets')->enviando(), Tecla::de('Ayuda', 'ayuda')->enviando());
    }
}
