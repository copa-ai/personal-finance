<?php

namespace App\Services\Chat\Claude\Acciones;

use App\Enums\OcrJobStatus;
use App\Models\Category;
use App\Models\Expense;
use App\Models\ExpenseItem;
use App\Models\OcrJob;
use App\Services\Acciones\GestionLineas;
use App\Services\Acciones\ResumenGastos;
use App\Services\Chat\Claude\Validadores;
use InvalidArgumentException;

/**
 * Las lineas de un gasto.
 *   anadir_linea      {gasto_id, concepto, cantidad?, precio_unitario, categoria?, tipo_linea?, recurrencia?}
 *   editar_linea      {linea_id, concepto?, cantidad?, precio_unitario?, categoria? (null la quita), tipo_linea?, recurrencia?}
 *   quitar_linea      {linea_id}
 *   borrar_lineas     {linea_ids}                         (borrado masivo: pide confirmacion)
 *   asignar_categoria {linea_ids, categoria}              (categoria null quita la de las lineas)
 * `categoria` es un id o el nombre exacto de una categoria que existe.
 */
class GrupoLineas implements GrupoAcciones
{
    public function __construct(private readonly Validadores $v) {}

    public function claves(): array
    {
        return [
            'anadir_linea' => ['tipo', 'gasto_id', ...GrupoGastos::CLAVES_LINEA],
            'editar_linea' => ['tipo', 'linea_id', ...GrupoGastos::CLAVES_LINEA],
            'quitar_linea' => ['tipo', 'linea_id'],
            'borrar_lineas' => ['tipo', 'linea_ids'],
            'asignar_categoria' => ['tipo', 'linea_ids', 'categoria'],
        ];
    }

    public function validar(string $tipo, array $accion): array|string
    {
        return match ($tipo) {
            'anadir_linea' => $this->validarAnadir($accion),
            'editar_linea' => $this->validarEditar($accion),
            'quitar_linea' => $this->validarQuitar($accion),
            'borrar_lineas' => $this->validarBorrar($accion),
            default => $this->validarCategoria($accion),
        };
    }

    public function destructiva(array $normalizada): bool
    {
        return $normalizada['tipo'] === 'borrar_lineas';
    }

    /** Un gasto con un OCR en cola no admite lineas nuevas: el OCR falla si el gasto ya las tiene. */
    private function ocrEnCola(Expense $gasto): bool
    {
        return OcrJob::query()->where('expense_id', $gasto->id)->where('status', OcrJobStatus::PENDING)->exists();
    }

    /** @param  array<string, mixed>  $a */
    private function validarAnadir(array $a): array|string
    {
        $gasto = $this->v->gasto($a['gasto_id'] ?? null);
        if (($motivo = $this->v->motivo($gasto)) !== null) {
            return $motivo;
        }
        if ($this->ocrEnCola($gasto)) {
            return 'Ese gasto tiene un OCR en cola: espera a que termine para añadirle líneas.';
        }
        $datos = GrupoGastos::validarLinea($this->v, array_diff_key($a, ['tipo' => 1, 'gasto_id' => 1]), true);
        if (is_string($datos)) {
            return $datos;
        }

        return ['tipo' => 'anadir_linea', 'gasto_id' => $gasto->id, 'datos' => $datos,
            'quiero' => "Añadir la línea «{$datos['concepto']}» (".ResumenGastos::dinero(($datos['cantidad'] ?? 1) * $datos['precio_unitario']).') al gasto del '
                .$gasto->date?->setTimezone(ResumenGastos::zona())->format('d/m/Y')];
    }

    /** @param  array<string, mixed>  $a */
    private function validarEditar(array $a): array|string
    {
        $linea = $this->v->linea($a['linea_id'] ?? null);
        if (($motivo = $this->v->motivo($linea)) !== null) {
            return $motivo;
        }
        $datos = GrupoGastos::validarLinea($this->v, array_diff_key($a, ['tipo' => 1, 'linea_id' => 1]), false);
        if (is_string($datos)) {
            return $datos;
        }
        if ($datos === []) {
            return 'La edición no cambia nada.';
        }

        return ['tipo' => 'editar_linea', 'linea_id' => $linea->id, 'cambios' => $datos, 'quiero' => 'Editar la línea «'.$linea->concept.'» ('.implode(', ', array_keys($datos)).')'];
    }

    /** @param  array<string, mixed>  $a */
    private function validarQuitar(array $a): array|string
    {
        $linea = $this->v->linea($a['linea_id'] ?? null);
        if (($motivo = $this->v->motivo($linea)) !== null) {
            return $motivo;
        }

        return ['tipo' => 'quitar_linea', 'linea_id' => $linea->id, 'quiero' => 'Quitar la línea «'.$linea->concept.'»'];
    }

    /** @param  array<string, mixed>  $a */
    private function validarBorrar(array $a): array|string
    {
        $ids = $this->v->ids(ExpenseItem::class, $a['linea_ids'] ?? null, 'linea_ids', 'una línea de gasto', (int) config('chat_claude.acciones.max_lineas_masivo'));
        if (($motivo = $this->v->motivo($ids)) !== null) {
            return $motivo;
        }
        $importe = ExpenseItem::query()->whereIn('id', $ids)->get()->sum(fn (ExpenseItem $l): float => GestionLineas::importe($l));

        return ['tipo' => 'borrar_lineas', 'linea_ids' => $ids, 'quiero' => 'Borrar '.count($ids).' línea(s) de gasto ('.ResumenGastos::dinero($importe).' en total)'];
    }

    /** @param  array<string, mixed>  $a */
    private function validarCategoria(array $a): array|string
    {
        if (! array_key_exists('categoria', $a)) {
            return 'Falta categoria (un id o nombre; null para quitarla).';
        }
        $ids = $this->v->ids(ExpenseItem::class, $a['linea_ids'] ?? null, 'linea_ids', 'una línea de gasto', (int) config('chat_claude.acciones.max_lineas_masivo'));
        $categoria = $this->v->categoria($a['categoria'], 'categoria', true);
        if (($motivo = $this->v->motivo($ids, $categoria)) !== null) {
            return $motivo;
        }

        return ['tipo' => 'asignar_categoria', 'linea_ids' => $ids, 'categoria_id' => $categoria?->id,
            'quiero' => $categoria ? 'Asignar la categoría «'.$categoria->name.'» a '.count($ids).' línea(s)' : 'Quitar la categoría a '.count($ids).' línea(s)'];
    }

    public function aplicar(array $a): string
    {
        $lineas = app(GestionLineas::class);
        switch ($a['tipo']) {
            case 'anadir_linea':
                $gasto = Expense::query()->findOrFail($a['gasto_id']);
                $datos = $a['datos'];
                $datos['categoria'] = $this->categoria($datos['categoria'] ?? null);
                $linea = $lineas->anadir($gasto, $datos, 'chat');

                return "Añadida la línea «{$linea->concept}» al gasto {$gasto->id}.";

            case 'editar_linea':
                $linea = ExpenseItem::query()->lockForUpdate()->findOrFail($a['linea_id']);
                $c = $a['cambios'];
                array_key_exists('categoria', $c) && $c['categoria'] = $this->categoria($c['categoria']);
                $lineas->editar($linea, $c, 'chat');

                return "Editada la línea «{$linea->concept}» (".implode(', ', array_keys($a['cambios'])).').';

            case 'quitar_linea':
                $linea = ExpenseItem::query()->findOrFail($a['linea_id']);
                $lineas->quitar($linea, 'chat');

                return "Quitada la línea «{$linea->concept}».";

            case 'borrar_lineas':
                $n = $lineas->borrarVarias($a['linea_ids'], 'chat');

                return "Borradas {$n} línea(s).";

            default:
                $categoria = $this->categoria($a['categoria_id'] === null ? null : Category::query()->find($a['categoria_id']) ?? throw new InvalidArgumentException('La categoría ya no existe.'));
                $n = $lineas->asignarCategoria($a['linea_ids'], $categoria, 'chat');

                return $categoria ? "Asignada la categoría «{$categoria->name}» a {$n} línea(s)." : "Quitada la categoría a {$n} línea(s).";
        }
    }

    /** La categoria validada, vuelta a leer (pudo borrarse entre la validacion y ahora). */
    private function categoria(?Category $categoria): ?Category
    {
        if ($categoria === null) {
            return null;
        }

        return Category::query()->find($categoria->id) ?? throw new InvalidArgumentException('La categoría ya no existe.');
    }
}
