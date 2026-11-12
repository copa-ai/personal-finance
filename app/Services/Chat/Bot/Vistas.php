<?php

namespace App\Services\Chat\Bot;

use App\Models\Expense;
use App\Services\Acciones\ResumenGastos;
use App\Services\Chat\Marcado\Reglas;
use App\Services\Chat\Marcado\Widget;
use Illuminate\Support\Collection;

/** Los widgets (bloques html) del bot: gasto de un periodo, gastos pendientes de revisar y confirmacion de tickets guardados. Todo con Widget. */
final class Vistas
{
    private const AMBAR = '#b45309';

    /**
     * El gasto pagado de un periodo, con el desglose por categoria si lo hay.
     *
     * @param  array{total: float, gastos: int, sin_total: int}  $total
     * @param  list<array{categoria_id: ?string, categoria: string, total: float}>  $categorias
     */
    public static function gasto(string $etiqueta, array $total, array $categorias, bool $detalle, int $maxCategorias = 12): Widget
    {
        $widget = Widget::nuevo()->titulo('Gasto '.$etiqueta, 3)->tabla([], [
            [Widget::resaltar('Total pagado', null, true), Widget::resaltar(ResumenGastos::dinero($total['total']), null, true)],
            [Widget::resaltar('Gastos', null, true), (string) $total['gastos']],
        ]);
        if ($total['sin_total'] > 0) {
            $widget->parrafo(Widget::resaltar('Ojo: ', self::AMBAR, true), "{$total['sin_total']} gasto(s) aún no tienen total y no suman.");
        }
        if ($detalle && $categorias !== []) {
            $filas = [];
            foreach (array_slice($categorias, 0, $maxCategorias) as $c) {
                $filas[] = [Reglas::cortar($c['categoria'], 50), ResumenGastos::dinero($c['total'])];
            }
            if (count($categorias) > $maxCategorias) {
                $resto = array_sum(array_map(fn (array $c): float => $c['total'], array_slice($categorias, $maxCategorias)));
                $filas[] = ['Otras', ResumenGastos::dinero($resto)];
            }
            $widget->titulo('Por categoría', 4)->tabla(['Categoría', 'Importe'], $filas);
        }

        return $widget;
    }

    /**
     * Gastos marcados para revisar.
     *
     * @param  Collection<int, Expense>  $gastos  con su establecimiento
     */
    public static function pendientes(Collection $gastos, int $totalPendientes): Widget
    {
        $widget = Widget::nuevo()->titulo('Pendiente de revisar', 3);
        if ($gastos->isEmpty()) {
            return $widget->parrafo('No hay ningún gasto pendiente de revisar.');
        }
        $filas = [];
        foreach ($gastos as $g) {
            $filas[] = [
                $g->date?->setTimezone(ResumenGastos::zona())->format('d/m/Y') ?? '—',
                Reglas::cortar((string) ($g->establishment?->name ?? 'Sin establecimiento'), 40),
                $g->total === null ? '—' : ResumenGastos::dinero($g->total),
                $g->ticket_photo_hash ? 'con ticket' : '—',
            ];
        }
        $widget->tabla(['Fecha', 'Establecimiento', 'Total', 'Ticket'], $filas);
        if ($totalPendientes > $gastos->count()) {
            $widget->parrafo('… y '.($totalPendientes - $gastos->count()).' más. Revísalos en el panel (Gastos, filtro «pendientes de revisar»).');
        }

        return $widget;
    }

    /**
     * Confirmacion de tickets guardados desde el chat.
     *
     * @param  list<array{gasto: Expense, ocr: bool, mensaje: string}>  $resultados
     */
    public static function tickets(array $resultados): Widget
    {
        $filas = [];
        foreach ($resultados as $r) {
            $filas[] = [
                $r['gasto']->date?->setTimezone(ResumenGastos::zona())->format('d/m/Y H:i') ?? '—',
                $r['ocr'] ? Widget::resaltar('OCR en cola', null, true) : Widget::resaltar('Sin OCR', self::AMBAR, true),
                Reglas::cortar(ucfirst($r['mensaje']), 90),
            ];
        }

        return Widget::nuevo()->titulo(count($resultados) === 1 ? 'Ticket guardado' : 'Tickets guardados', 3)->tabla(['Gasto', 'Estado', 'Detalle'], $filas)
            ->parrafo('Quedan marcados como pendientes de revisar.');
    }
}
