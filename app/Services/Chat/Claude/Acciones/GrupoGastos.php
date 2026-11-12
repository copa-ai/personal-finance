<?php

namespace App\Services\Chat\Claude\Acciones;

use App\Enums\ExpenseItemType;
use App\Enums\ExpenseStatus;
use App\Enums\Recurrence;
use App\Models\Category;
use App\Models\Establishment;
use App\Models\Expense;
use App\Services\Acciones\GestionCatalogo;
use App\Services\Acciones\GestionGastos;
use App\Services\Acciones\ResumenGastos;
use App\Services\Chat\Claude\Rechazo;
use App\Services\Chat\Claude\Validadores;
use InvalidArgumentException;

/**
 * Los gastos: crear con sus lineas, editar, marcar revisado o pendiente, adjuntar la foto de un ticket y borrar.
 *   crear_gasto     {fecha, establecimiento?, total?, estado?, pendiente_revision?, lineas?: [{concepto, cantidad?, precio_unitario, categoria?, tipo_linea?, recurrencia?}], foto_hash?}
 *   editar_gasto    {gasto_id, establecimiento?, fecha?, total?, estado?}            (establecimiento y total admiten null: lo quitan)
 *   marcar_revisado {gasto_ids}   marcar_pendiente {gasto_ids}
 *   adjuntar_foto   {gasto_id, foto_hash}                                           (lanza el OCR si el gasto no tiene lineas)
 *   borrar_gasto    {gasto_id}                                                      (pide confirmacion)
 * `establecimiento` es un id o un nombre; un nombre que no existe se crea (sin categoria por defecto).
 */
class GrupoGastos implements GrupoAcciones
{
    public function __construct(private readonly Validadores $v) {}

    public function claves(): array
    {
        return [
            'crear_gasto' => ['tipo', 'fecha', 'establecimiento', 'total', 'estado', 'pendiente_revision', 'lineas', 'foto_hash'],
            'editar_gasto' => ['tipo', 'gasto_id', 'establecimiento', 'fecha', 'total', 'estado'],
            'marcar_revisado' => ['tipo', 'gasto_ids'],
            'marcar_pendiente' => ['tipo', 'gasto_ids'],
            'adjuntar_foto' => ['tipo', 'gasto_id', 'foto_hash'],
            'borrar_gasto' => ['tipo', 'gasto_id'],
        ];
    }

    /** Claves que admite cada linea de crear_gasto (las mismas que anadir_linea sin el gasto). */
    public const CLAVES_LINEA = ['concepto', 'cantidad', 'precio_unitario', 'categoria', 'tipo_linea', 'recurrencia'];

    public function validar(string $tipo, array $accion): array|string
    {
        return match ($tipo) {
            'crear_gasto' => $this->validarCrear($accion),
            'editar_gasto' => $this->validarEditar($accion),
            'marcar_revisado', 'marcar_pendiente' => $this->validarRevision($tipo, $accion),
            'adjuntar_foto' => $this->validarFoto($accion),
            default => $this->validarBorrar($accion),
        };
    }

    public function destructiva(array $normalizada): bool
    {
        return $normalizada['tipo'] === 'borrar_gasto';
    }

    /** @param  array<string, mixed>  $a */
    private function validarCrear(array $a): array|string
    {
        $v = $this->v;
        $fecha = $v->fechaGasto($a['fecha'] ?? null);
        $establecimiento = ($a['establecimiento'] ?? null) === null ? null : $v->establecimientoOCrear($a['establecimiento']);
        $total = array_key_exists('total', $a) ? $v->importe($a['total'], 'total', true) : null;
        $estado = array_key_exists('estado', $a) ? $v->enum(ExpenseStatus::class, $a['estado'], 'estado') : ExpenseStatus::PAID;
        $pendiente = $a['pendiente_revision'] ?? false;
        $foto = array_key_exists('foto_hash', $a) ? $v->foto($a['foto_hash']) : null;
        if (($motivo = $v->motivo($fecha, $establecimiento, $total, $estado, $foto)) !== null) {
            return $motivo;
        }
        if (! is_bool($pendiente)) {
            return 'pendiente_revision tiene que ser true o false.';
        }
        $lineas = $this->validarLineas($a['lineas'] ?? []);
        if (is_string($lineas)) {
            return $lineas;
        }
        if ($total === null && $lineas === [] && $foto === null) {
            return 'Un gasto nuevo necesita al menos un total, alguna línea o la foto de un ticket.';
        }
        if ($foto !== null && $lineas !== []) {
            return 'Si das líneas no se lanza el OCR de la foto: crea el gasto solo con la foto, o con las líneas y adjunta la foto después con adjuntar_foto.';
        }
        $nombre = $establecimiento instanceof Establishment ? $establecimiento->name : (is_string($establecimiento) ? $establecimiento : null);

        return ['tipo' => 'crear_gasto', 'fecha' => $fecha, 'establecimiento' => $establecimiento, 'total' => $total, 'estado' => $estado,
            'pendiente_revision' => $pendiente, 'lineas' => $lineas, 'foto' => $foto,
            'quiero' => 'Crear un gasto'.($nombre !== null ? " en {$nombre}" : '').' del '.$fecha->format('d/m/Y').($total !== null ? ' por '.ResumenGastos::dinero($total) : '')
                .($lineas !== [] ? ' con '.count($lineas).' línea(s)' : '')];
    }

    /**
     * @return list<array<string, mixed>>|string
     */
    private function validarLineas(mixed $lineas): array|string
    {
        $max = (int) config('chat_claude.acciones.max_lineas_por_gasto');
        if (! is_array($lineas) || ! array_is_list($lineas) || count($lineas) > $max) {
            return "lineas tiene que ser una lista de hasta {$max} líneas.";
        }
        $resultado = [];
        foreach ($lineas as $i => $linea) {
            $n = $i + 1;
            if (! is_array($linea) || array_is_list($linea)) {
                return "La línea {$n} no es un objeto.";
            }
            if (($extra = array_diff(array_keys($linea), self::CLAVES_LINEA)) !== []) {
                return "Campos no admitidos en la línea {$n}: ".implode(', ', array_map(fn ($c): string => mb_substr((string) $c, 0, 40), $extra)).'.';
            }
            $normal = self::validarLinea($this->v, $linea, true);
            if (is_string($normal)) {
                return "Línea {$n}: {$normal}";
            }
            $resultado[] = $normal;
        }

        return $resultado;
    }

    /**
     * Valida los campos de una linea (los de crear/anadir, o solo los presentes si no es completa). Comparte reglas con GrupoLineas.
     *
     * @param  array<string, mixed>  $l
     * @return array<string, mixed>|string  concepto, cantidad, precio_unitario, categoria (?Category), tipo (de `tipo_linea`), recurrencia
     */
    public static function validarLinea(Validadores $v, array $l, bool $completa): array|string
    {
        $campos = [];
        $validadores = [
            'concepto' => fn ($x) => $v->texto($x, 'concepto', 1, 255),
            'cantidad' => fn ($x) => $v->cantidad($x),
            'precio_unitario' => fn ($x) => $v->importe($x, 'precio_unitario', false, true),
            'categoria' => fn ($x) => $v->categoria($x, 'categoria', true),
            'tipo_linea' => fn ($x) => $v->enum(ExpenseItemType::class, $x, 'tipo_linea'),
            'recurrencia' => fn ($x) => $v->enum(Recurrence::class, $x, 'recurrencia'),
        ];
        if ($completa) {
            foreach (['concepto', 'precio_unitario'] as $obligatorio) {
                if (! array_key_exists($obligatorio, $l)) {
                    return "falta {$obligatorio}.";
                }
            }
        }
        foreach ($validadores as $campo => $valida) {
            if (array_key_exists($campo, $l)) {
                $valor = $valida($l[$campo]);
                if ($valor instanceof Rechazo) {
                    return $valor->motivo;
                }
                $campos[$campo === 'tipo_linea' ? 'tipo' : $campo] = $valor;
            }
        }

        return $campos;
    }

    /** @param  array<string, mixed>  $a */
    private function validarEditar(array $a): array|string
    {
        $v = $this->v;
        $gasto = $v->gasto($a['gasto_id'] ?? null);
        if (($motivo = $v->motivo($gasto)) !== null) {
            return $motivo;
        }
        $cambios = [];
        if (array_key_exists('establecimiento', $a)) {
            $cambios['establecimiento'] = $a['establecimiento'] === null ? null : $v->establecimientoOCrear($a['establecimiento']);
        }
        if (array_key_exists('fecha', $a)) {
            $cambios['fecha'] = $v->fechaGasto($a['fecha']);
        }
        if (array_key_exists('total', $a)) {
            $cambios['total'] = $v->importe($a['total'], 'total', true);
        }
        if (array_key_exists('estado', $a)) {
            $cambios['estado'] = $v->enum(ExpenseStatus::class, $a['estado'], 'estado');
        }
        if (($motivo = $v->motivo(...array_values($cambios))) !== null) {
            return $motivo;
        }
        if ($cambios === []) {
            return 'La edición no cambia nada.';
        }

        return ['tipo' => 'editar_gasto', 'gasto_id' => $gasto->id, 'cambios' => $cambios,
            'quiero' => 'Editar el gasto del '.$gasto->date?->setTimezone(ResumenGastos::zona())->format('d/m/Y').' ('.implode(', ', array_keys($cambios)).')'];
    }

    /** @param  array<string, mixed>  $a */
    private function validarRevision(string $tipo, array $a): array|string
    {
        $ids = $this->v->ids(Expense::class, $a['gasto_ids'] ?? null, 'gasto_ids', 'un gasto', 50);
        if (($motivo = $this->v->motivo($ids)) !== null) {
            return $motivo;
        }

        return ['tipo' => $tipo, 'gasto_ids' => $ids, 'quiero' => ($tipo === 'marcar_revisado' ? 'Marcar como revisado(s) ' : 'Marcar para revisar ').count($ids).' gasto(s)'];
    }

    /** @param  array<string, mixed>  $a */
    private function validarFoto(array $a): array|string
    {
        $gasto = $this->v->gasto($a['gasto_id'] ?? null);
        $foto = $this->v->foto($a['foto_hash'] ?? null);
        if (($motivo = $this->v->motivo($gasto, $foto)) !== null) {
            return $motivo;
        }

        return ['tipo' => 'adjuntar_foto', 'gasto_id' => $gasto->id, 'foto' => $foto, 'quiero' => 'Adjuntar la foto del ticket al gasto del '.$gasto->date?->setTimezone(ResumenGastos::zona())->format('d/m/Y')];
    }

    /** @param  array<string, mixed>  $a */
    private function validarBorrar(array $a): array|string
    {
        $gasto = $this->v->gasto($a['gasto_id'] ?? null);
        if (($motivo = $this->v->motivo($gasto)) !== null) {
            return $motivo;
        }
        $gasto->loadMissing('establishment');
        $importe = ResumenGastos::importe($gasto);

        return ['tipo' => 'borrar_gasto', 'gasto_id' => $gasto->id,
            'quiero' => 'Borrar el gasto del '.$gasto->date?->setTimezone(ResumenGastos::zona())->format('d/m/Y').($gasto->establishment ? ' en '.$gasto->establishment->name : '')
                .' ('.ResumenGastos::dinero($importe).') y sus '.$gasto->items()->count().' línea(s)'];
    }

    public function aplicar(array $a): string
    {
        $gastos = app(GestionGastos::class);
        switch ($a['tipo']) {
            case 'crear_gasto':
                $establecimiento = $this->resolverEstablecimiento($a['establecimiento']);
                $gasto = $gastos->crear([
                    'establecimiento' => $establecimiento, 'fecha' => $a['fecha'], 'total' => $a['total'], 'estado' => $a['estado'],
                    'pendiente_revision' => $a['pendiente_revision'],
                    'lineas' => array_map(fn (array $l): array => $this->lineaParaGuardar($l), $a['lineas']),
                ], 'chat');
                $texto = "Creado el gasto {$gasto->id}".($establecimiento ? " en {$establecimiento->name}" : '').' del '.$a['fecha']->format('d/m/Y')
                    .($a['total'] !== null ? ' por '.ResumenGastos::dinero($a['total']) : '').($a['lineas'] !== [] ? ', con '.count($a['lineas']).' línea(s)' : '').'.';
                if ($a['foto'] !== null) {
                    $r = $gastos->adjuntarFoto($gasto, $gastos->guardarFotoDeChat($a['foto']), 'chat');
                    $texto .= ' Foto del ticket guardada ('.$r['mensaje'].').';
                }
                if ($a['total'] !== null && $a['lineas'] !== []) {
                    $dif = $gasto->subexpensesDifferenceCents();
                    $dif !== null && abs($dif) > 10 && $texto .= ' Ojo: las líneas no suman el total (diferencia '.Expense::formatCents($dif).').';
                }

                return $texto;

            case 'editar_gasto':
                $gasto = Expense::query()->lockForUpdate()->findOrFail($a['gasto_id']);
                $c = $a['cambios'];
                if (array_key_exists('establecimiento', $c)) {
                    $c['establecimiento'] = $this->resolverEstablecimiento($c['establecimiento']);
                }
                $gastos->editar($gasto, $c, 'chat');

                return "Editado el gasto {$gasto->id} (".implode(', ', array_keys($a['cambios'])).').';

            case 'marcar_revisado':
            case 'marcar_pendiente':
                $pendiente = $a['tipo'] === 'marcar_pendiente';
                foreach (Expense::query()->whereIn('id', $a['gasto_ids'])->get() as $gasto) {
                    $gastos->marcarRevision($gasto, $pendiente, 'chat');
                }

                return count($a['gasto_ids']).' gasto(s) '.($pendiente ? 'marcado(s) para revisar.' : 'marcado(s) como revisados.');

            case 'adjuntar_foto':
                $gasto = Expense::query()->findOrFail($a['gasto_id']);
                $r = $gastos->adjuntarFoto($gasto, $gastos->guardarFotoDeChat($a['foto']), 'chat');

                return "Foto del ticket adjuntada al gasto {$gasto->id}: {$r['mensaje']}.";

            default:
                $gasto = Expense::query()->findOrFail($a['gasto_id']);
                $gastos->borrar($gasto, 'chat');

                return "Borrado el gasto {$a['gasto_id']}.";
        }
    }

    /** @param  Establishment|string|null  $valor  un establecimiento ya validado, el nombre de uno que hay que crear, o null */
    private function resolverEstablecimiento(Establishment|string|null $valor): ?Establishment
    {
        if ($valor === null) {
            return null;
        }
        if ($valor instanceof Establishment) {
            return Establishment::query()->find($valor->id) ?? throw new InvalidArgumentException('El establecimiento ya no existe.');
        }

        return app(GestionCatalogo::class)->establecimientoOCrear($valor, 'chat');
    }

    /**
     * @param  array<string, mixed>  $l
     * @return array<string, mixed>
     */
    private function lineaParaGuardar(array $l): array
    {
        if (($l['categoria'] ?? null) instanceof Category) {
            $l['categoria'] = Category::query()->find($l['categoria']->id) ?? throw new InvalidArgumentException('Una categoría de las líneas ya no existe.');
        }

        return $l;
    }
}
