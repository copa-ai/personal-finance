<?php

namespace App\Services\Chat\Claude;

use App\Models\Category;
use App\Models\ChatMensaje;
use App\Models\Establishment;
use App\Models\Expense;
use App\Models\ExpenseItem;
use App\Services\Acciones\GestionCatalogo;
use App\Services\Chat\Archivos;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Validadores estrictos que comparten los grupos de acciones del chat. Cada uno devuelve el valor ya normalizado o un Rechazo con el
 * motivo (en castellano, para la persona). Nunca se fia de lo que propone el agente: ids uuid que existan, enums, fechas reales,
 * importes acotados, tamaños.
 */
class Validadores
{
    /** El primer Rechazo de una lista de valores validados, o null si todos son validos. */
    public function motivo(mixed ...$valores): ?string
    {
        foreach ($valores as $valor) {
            if ($valor instanceof Rechazo) {
                return $valor->motivo;
            }
        }

        return null;
    }

    public function rechazo(string $texto): Rechazo
    {
        return new Rechazo($texto);
    }

    /** Fecha mas antigua que se admite en un gasto. */
    public function desde(): CarbonImmutable
    {
        return CarbonImmutable::today()->subDays((int) config('chat_claude.acciones.dias_atras'));
    }

    /**
     * Un registro por su id uuid.
     *
     * @param  class-string<Model>  $clase
     */
    public function porId(string $clase, mixed $valor, string $campo, string $que): Model|Rechazo
    {
        if (! is_string($valor) || ! Str::isUuid($valor)) {
            return $this->rechazo("{$campo} tiene que ser el id de {$que}.");
        }

        return $clase::query()->find($valor) ?? $this->rechazo("No existe {$que} con id {$valor}.");
    }

    public function gasto(mixed $valor, string $campo = 'gasto_id'): Expense|Rechazo
    {
        return $this->porId(Expense::class, $valor, $campo, 'un gasto');
    }

    public function linea(mixed $valor, string $campo = 'linea_id'): ExpenseItem|Rechazo
    {
        return $this->porId(ExpenseItem::class, $valor, $campo, 'una línea de gasto');
    }

    /**
     * Una lista de ids de un modelo (sin repetidos, de 1 a $max) que existen todos.
     *
     * @param  class-string<Model>  $clase
     * @return list<string>|Rechazo
     */
    public function ids(string $clase, mixed $valor, string $campo, string $que, int $max): array|Rechazo
    {
        if (! is_array($valor) || ! array_is_list($valor) || $valor === [] || count($valor) > $max) {
            return $this->rechazo("{$campo} tiene que ser una lista de 1 a {$max} ids de {$que}.");
        }
        foreach ($valor as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                return $this->rechazo("{$campo} solo admite ids de {$que}.");
            }
        }
        $ids = array_values(array_unique($valor));
        $existentes = $clase::query()->whereIn('id', $ids)->pluck('id')->map(fn ($i): string => (string) $i)->all();
        $faltan = array_diff($ids, $existentes);
        if ($faltan !== []) {
            return $this->rechazo("En {$campo} no existe {$que} con id ".reset($faltan).'.');
        }

        return $ids;
    }

    /** Categoria por id o por nombre exacto (no distingue mayusculas). null = «ninguna» solo si `$permiteNulo`. */
    public function categoria(mixed $valor, string $campo = 'categoria', bool $permiteNulo = false): Category|Rechazo|null
    {
        if ($valor === null && $permiteNulo) {
            return null;
        }
        if (is_string($valor) && Str::isUuid($valor)) {
            return Category::query()->find($valor) ?? $this->rechazo("No existe una categoría con id {$valor}.");
        }
        if (is_string($valor) && trim($valor) !== '') {
            return app(GestionCatalogo::class)->categoriaPorNombre($valor)
                ?? $this->rechazo('No existe una categoría llamada «'.mb_substr($valor, 0, 60).'» (créala antes con crear_categoria).');
        }

        return $this->rechazo("Falta {$campo} (el id o el nombre de la categoría).");
    }

    /** Establecimiento existente por id o por nombre exacto. */
    public function establecimiento(mixed $valor, string $campo = 'establecimiento'): Establishment|Rechazo
    {
        if (is_string($valor) && Str::isUuid($valor)) {
            return Establishment::query()->find($valor) ?? $this->rechazo("No existe un establecimiento con id {$valor}.");
        }
        if (is_string($valor) && trim($valor) !== '') {
            return app(GestionCatalogo::class)->establecimientoPorNombre($valor)
                ?? $this->rechazo('No existe un establecimiento llamado «'.mb_substr($valor, 0, 60).'».');
        }

        return $this->rechazo("Falta {$campo} (el id o el nombre del establecimiento).");
    }

    /**
     * Establecimiento de un gasto: un id existente o un nombre. Un nombre que no existe NO es un rechazo: se crea al aplicar (sin
     * categoria por defecto). Devuelve el existente, o el nombre limpio (string) del que hay que crear.
     */
    public function establecimientoOCrear(mixed $valor, string $campo = 'establecimiento'): Establishment|string|Rechazo
    {
        if (is_string($valor) && Str::isUuid($valor)) {
            return $this->establecimiento($valor, $campo);
        }
        $nombre = $this->nombre($valor, $campo);
        if ($nombre instanceof Rechazo) {
            return $nombre;
        }

        return app(GestionCatalogo::class)->establecimientoPorNombre($nombre) ?? $nombre;
    }

    /** Nombre de categoria o establecimiento: 2 a 100 caracteres. */
    public function nombre(mixed $valor, string $campo = 'nombre'): string|Rechazo
    {
        return $this->texto($valor, $campo, 2, 100);
    }

    /**
     * Fecha de un gasto: AAAA-MM-DD o AAAA-MM-DD HH:MM en la hora local de la app (sin hora se usa el mediodia, para que la zona horaria
     * no la cambie de dia). No futura y no mas antigua que `dias_atras`.
     */
    public function fechaGasto(mixed $valor, string $campo = 'fecha'): CarbonImmutable|Rechazo
    {
        $zona = (string) config('app.display_timezone', 'UTC');
        if (! is_string($valor) || ! preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}):(\d{2}))?$/', $valor, $m)) {
            return $this->rechazo("{$campo} tiene que ser AAAA-MM-DD o AAAA-MM-DD HH:MM.");
        }
        $dia = $this->fechaReal($m[1], $campo);
        if ($dia instanceof Rechazo) {
            return $dia;
        }
        $hora = isset($m[2]) ? (int) $m[2] : 12;
        $minuto = isset($m[3]) ? (int) $m[3] : 0;
        if ($hora > 23 || $minuto > 59) {
            return $this->rechazo("La hora de {$valor} no existe.");
        }
        $fecha = CarbonImmutable::create((int) $dia->year, (int) $dia->month, (int) $dia->day, $hora, $minuto, 0, $zona);
        if ($fecha->gt(CarbonImmutable::now($zona)->endOfDay())) {
            return $this->rechazo("La fecha {$valor} es futura: solo se registran gastos ya hechos.");
        }
        if ($fecha->lt($this->desde())) {
            return $this->rechazo("La fecha {$valor} es demasiado antigua (máximo ".(int) config('chat_claude.acciones.dias_atras').' días).');
        }

        return $fecha;
    }

    /** Fecha real AAAA-MM-DD, sin mas limites. */
    public function fechaReal(mixed $valor, string $campo = 'fecha'): CarbonImmutable|Rechazo
    {
        if (! is_string($valor) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return $this->rechazo("{$campo} tiene que ser AAAA-MM-DD.");
        }
        $fecha = CarbonImmutable::createFromFormat('!Y-m-d', $valor);
        if ($fecha === false || $fecha->toDateString() !== $valor) {
            return $this->rechazo("La fecha {$valor} no existe.");
        }

        return $fecha;
    }

    /** @param  class-string<\BackedEnum>  $clase */
    public function enum(string $clase, mixed $valor, string $campo): \BackedEnum|Rechazo
    {
        $enum = is_string($valor) || is_int($valor) ? $clase::tryFrom($valor) : null;

        return $enum ?? $this->rechazo("{$campo} tiene que ser uno de: ".implode(', ', array_map(fn ($c): string => (string) $c->value, $clase::cases())).'.');
    }

    /** Importe en euros: numero entre -1.000.000 y 1.000.000 (un precio unitario puede ser negativo: un descuento), a 2 decimales. null si `$permiteNulo`. */
    public function importe(mixed $valor, string $campo, bool $permiteNulo = false, bool $negativo = false): float|Rechazo|null
    {
        if ($valor === null && $permiteNulo) {
            return null;
        }
        $min = $negativo ? -1_000_000 : 0;
        if ((! is_int($valor) && ! is_float($valor)) || $valor < $min || $valor > 1_000_000) {
            return $this->rechazo("{$campo} tiene que ser un número entre {$min} y 1000000".($permiteNulo ? ' (o null)' : '').' (euros, con punto decimal).');
        }

        return round((float) $valor, 2);
    }

    /** Cantidad de una linea: numero entre 0,001 y 100.000, a 3 decimales. */
    public function cantidad(mixed $valor, string $campo = 'cantidad'): float|Rechazo
    {
        if ((! is_int($valor) && ! is_float($valor)) || $valor < 0.001 || $valor > 100_000) {
            return $this->rechazo("{$campo} tiene que ser un número entre 0,001 y 100000.");
        }

        return round((float) $valor, 3);
    }

    /** Un texto de la longitud pedida, sin caracteres de control. Devuelve el texto recortado. */
    public function texto(mixed $valor, string $campo, int $min, int $max): string|Rechazo
    {
        if (! is_string($valor) || ! $this->textoLimpio($valor, $min, $max)) {
            return $this->rechazo("{$campo} tiene que ser un texto de {$min} a {$max} caracteres.");
        }

        return trim(preg_replace('/\s+/u', ' ', $valor));
    }

    /** Entero entre dos limites; null si `$permiteNulo` y viene null. */
    public function entero(mixed $valor, string $campo, int $min, int $max, bool $permiteNulo = false): int|Rechazo|null
    {
        if ($valor === null && $permiteNulo) {
            return null;
        }
        if (! is_int($valor) || $valor < $min || $valor > $max) {
            return $this->rechazo("{$campo} tiene que ser un número entero entre {$min} y {$max}".($permiteNulo ? ' (o null)' : '').'.');
        }

        return $valor;
    }

    /** Texto sin caracteres de control y de la longitud pedida. */
    public function textoLimpio(string $texto, int $min, int $max): bool
    {
        $largo = mb_strlen(trim($texto));

        return $largo >= $min && $largo <= $max && ! preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $texto) && mb_check_encoding($texto, 'UTF-8');
    }

    /** foto_hash: una foto de la persona en esta conversacion (y guardada). Devuelve el hash. */
    public function foto(mixed $valor): string|Rechazo
    {
        $hash = is_string($valor) ? Archivos::normalizarHash($valor) : null;
        if ($hash === null) {
            return $this->rechazo('foto_hash tiene que ser el SHA3-256 (64 caracteres hexadecimales) de una foto de la conversación.');
        }

        return $this->fotoDeLaConversacion($hash) ? $hash : $this->rechazo('foto_hash no es una foto enviada en esta conversación.');
    }

    private function fotoDeLaConversacion(string $hash): bool
    {
        if (! app(Archivos::class)->metadatos($hash)) {
            return false;
        }
        // CAST a texto vale para json; el hash (64 hex, ya validado) es unico, y luego se confirma en PHP.
        $candidatos = ChatMensaje::query()->where('from_service', false)->whereRaw('CAST(contenido AS TEXT) LIKE ?', ['%'.$hash.'%'])->get();
        foreach ($candidatos as $mensaje) {
            foreach ($mensaje->imagenes() as $parte) {
                if (($parte['hash'] ?? null) === $hash) {
                    return true;
                }
            }
        }

        return false;
    }
}
