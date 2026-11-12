<?php

namespace App\Services\Chat\Bot;

/** Lo que el bot ha entendido de un mensaje: un tipo y los datos que ya se pudieron sacar. */
final class Intencion
{
    public const SALUDO = 'saludo';

    public const GRACIAS = 'gracias';

    public const ADIOS = 'adios';

    public const AYUDA = 'ayuda';

    /** Cuanto se ha gastado en un periodo (datos: periodo, por_categoria). */
    public const GASTO = 'gasto';

    /** Que gastos estan pendientes de revisar. */
    public const PENDIENTES = 'pendientes';

    /** Quiere revisar los tickets pendientes con el mazo de tarjetas (slot «cards»). */
    public const REVISAR = 'revisar';

    /** Quiere registrar un gasto: con foto de ticket (datos: con_imagenes) o diciendolo con palabras. */
    public const REGISTRAR = 'registrar';

    /** Pide algo que requiere IA (analisis, consejos, presupuestos...). */
    public const IA = 'ia';

    public const NO_ENTIENDO = 'no_entiendo';

    /** @param  array<string, mixed>  $datos */
    public function __construct(public readonly string $tipo, public readonly array $datos = []) {}
}
