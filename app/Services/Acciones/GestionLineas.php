<?php

namespace App\Services\Acciones;

use App\Enums\ExpenseItemType;
use App\Enums\Recurrence;
use App\Models\Auditoria;
use App\Models\Category;
use App\Models\Expense;
use App\Models\ExpenseItem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reglas de las lineas de un gasto (ExpenseItem) que comparten el panel y el chat: anadir, editar, quitar, asignar categoria en bloque
 * y borrar varias. El importe de una linea (line_total) lo calcula el modelo (cantidad x precio unitario). El ExpenseItemObserver sigue
 * rellenando el tipo de movimiento.
 */
class GestionLineas
{
    /**
     * @param  array{concepto: string, cantidad?: float|int, precio_unitario: float|int, categoria?: ?Category, tipo?: ExpenseItemType, recurrencia?: Recurrence}  $datos
     */
    public function anadir(Expense $gasto, array $datos, string $origen = 'panel', bool $auditar = true): ExpenseItem
    {
        $linea = new ExpenseItem;
        $linea->fill([
            'expense_id' => $gasto->id,
            'category_id' => ($datos['categoria'] ?? null)?->id,
            'concept' => $datos['concepto'],
            'quantity' => $datos['cantidad'] ?? 1,
            'unit_price' => $datos['precio_unitario'],
            'tags' => [],
            'item_type' => $datos['tipo'] ?? ExpenseItemType::VARIABLE_IRREGULAR,
            'recurrence' => $datos['recurrencia'] ?? Recurrence::NONE,
            'is_consumable' => true,
        ]);
        $linea->save();
        $gasto->forgetSubexpensesTotal();
        $auditar && Auditoria::registrar('gasto.linea_anadida', "Línea añadida desde {$origen}", ['gasto_id' => $gasto->id, 'linea_id' => $linea->id, 'origen' => $origen]);

        return $linea;
    }

    /**
     * Solo se tocan las claves presentes (`categoria` puede ser null: la quita).
     *
     * @param  array<string, mixed>  $cambios  concepto, cantidad, precio_unitario, categoria (?Category), tipo, recurrencia
     */
    public function editar(ExpenseItem $linea, array $cambios, string $origen = 'panel'): ExpenseItem
    {
        $mapa = ['concepto' => 'concept', 'cantidad' => 'quantity', 'precio_unitario' => 'unit_price', 'tipo' => 'item_type', 'recurrencia' => 'recurrence'];
        $atributos = [];
        foreach ($mapa as $clave => $columna) {
            if (array_key_exists($clave, $cambios)) {
                $atributos[$columna] = $cambios[$clave];
            }
        }
        if (array_key_exists('categoria', $cambios)) {
            $atributos['category_id'] = $cambios['categoria']?->id;
        }
        if ($atributos === []) {
            throw new InvalidArgumentException('No hay nada que cambiar.');
        }
        $linea->fill($atributos)->save();
        $linea->expense?->forgetSubexpensesTotal();
        Auditoria::registrar('gasto.linea_editada', "Línea editada desde {$origen}", ['gasto_id' => $linea->expense_id, 'linea_id' => $linea->id, 'campos' => array_keys($atributos), 'origen' => $origen]);

        return $linea;
    }

    public function quitar(ExpenseItem $linea, string $origen = 'panel'): void
    {
        $gastoId = $linea->expense_id;
        $id = $linea->id;
        $linea->delete();
        Auditoria::registrar('gasto.linea_quitada', "Línea quitada desde {$origen}", ['gasto_id' => $gastoId, 'linea_id' => $id, 'origen' => $origen]);
    }

    /**
     * Borra varias lineas de una vez.
     *
     * @param  list<string>  $ids
     * @return int cuantas se borraron
     */
    public function borrarVarias(array $ids, string $origen = 'panel'): int
    {
        $n = DB::transaction(fn (): int => ExpenseItem::query()->whereIn('id', $ids)->get()->each->delete()->count());
        Auditoria::registrar('gasto.lineas_borradas', "Líneas borradas en bloque desde {$origen}", ['cantidad' => $n, 'lineas' => $ids, 'origen' => $origen]);

        return $n;
    }

    /**
     * Pone (o quita, con null) la categoria de varias lineas.
     *
     * @param  list<string>  $ids
     * @return int cuantas se cambiaron
     */
    public function asignarCategoria(array $ids, ?Category $categoria, string $origen = 'panel'): int
    {
        $n = ExpenseItem::query()->whereIn('id', $ids)->update(['category_id' => $categoria?->id]);
        Auditoria::registrar('gasto.lineas_categorizadas', "Categoría asignada a líneas desde {$origen}",
            ['cantidad' => $n, 'lineas' => $ids, 'categoria_id' => $categoria?->id, 'origen' => $origen]);

        return $n;
    }

    /** Importe de una linea: cantidad x precio unitario, a 2 decimales. */
    public static function importe(ExpenseItem $linea): float
    {
        return round((float) $linea->quantity * (float) $linea->unit_price, 2);
    }
}
