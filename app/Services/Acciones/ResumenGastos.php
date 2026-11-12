<?php

namespace App\Services\Acciones;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Consultas de gasto de solo lectura que comparten el bot basico y la instantanea de Claude. Los gastos cuentan cuando estan PAID
 * (igual que el widget «Total Gastos Pagados»); las fechas se guardan en UTC y los periodos son locales (app.display_timezone).
 * El importe de una linea es cantidad x precio unitario.
 */
class ResumenGastos
{
    public const PERIODOS = ['hoy', 'ayer', 'semana', 'mes', 'mes_pasado', 'anio'];

    public static function zona(): string
    {
        return (string) config('app.display_timezone', 'UTC');
    }

    /** Inicio de un periodo local. @return array{desde: CarbonImmutable, hasta: CarbonImmutable, etiqueta: string} (desde incluido, hasta excluido, en UTC) */
    public function periodo(string $clave, ?CarbonImmutable $ahora = null): array
    {
        $ahora = ($ahora ?? CarbonImmutable::now())->setTimezone(self::zona());
        [$desde, $hasta, $etiqueta] = match ($clave) {
            'hoy' => [$ahora->startOfDay(), $ahora->startOfDay()->addDay(), 'hoy'],
            'ayer' => [$ahora->startOfDay()->subDay(), $ahora->startOfDay(), 'ayer'],
            'semana' => [$ahora->startOfWeek(), $ahora->startOfWeek()->addWeek(), 'esta semana'],
            'mes_pasado' => [$ahora->startOfMonth()->subMonth(), $ahora->startOfMonth(), 'el mes pasado'],
            'anio' => [$ahora->startOfYear(), $ahora->startOfYear()->addYear(), 'este año'],
            default => [$ahora->startOfMonth(), $ahora->startOfMonth()->addMonth(), 'este mes'],
        };

        return ['desde' => $desde->utc(), 'hasta' => $hasta->utc(), 'etiqueta' => $etiqueta];
    }

    /** Total pagado y numero de gastos en un rango. @return array{total: float, gastos: int, sin_total: int} */
    public function total(CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $base = Expense::query()->where('status', ExpenseStatus::PAID)->where('date', '>=', $desde)->where('date', '<', $hasta);

        return [
            'total' => round((float) (clone $base)->sum('total'), 2),
            'gastos' => (clone $base)->count(),
            'sin_total' => (clone $base)->whereNull('total')->count(),
        ];
    }

    /**
     * Importe de las lineas por categoria efectiva (la de la linea o, si no tiene, la de su establecimiento) en un rango.
     *
     * @return list<array{categoria_id: ?string, categoria: string, total: float}> de mayor a menor
     */
    public function porCategoria(CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $filas = DB::table('expense_items')
            ->join('expenses', 'expense_items.expense_id', '=', 'expenses.id')
            ->leftJoin('establishments', 'expenses.establishment_id', '=', 'establishments.id')
            ->leftJoin('categories', 'categories.id', '=', DB::raw('COALESCE(expense_items.category_id, establishments.category_id)'))
            ->where('expenses.status', ExpenseStatus::PAID->value)
            ->where('expenses.date', '>=', $desde)->where('expenses.date', '<', $hasta)
            ->groupBy('categories.id', 'categories.name')
            ->selectRaw('categories.id as categoria_id, categories.name as categoria, SUM(expense_items.quantity * expense_items.unit_price) as total')
            ->get();

        return $filas->map(fn (object $f): array => [
            'categoria_id' => $f->categoria_id,
            'categoria' => $f->categoria ?? 'Sin categoría',
            'total' => round((float) $f->total, 2),
        ])->sortByDesc('total')->values()->all();
    }

    /** Gastos marcados para revisar, los mas recientes primero. @return Collection<int, Expense> */
    public function pendientes(int $limite = 10): Collection
    {
        return Expense::query()->with('establishment')->where('pending_review', true)->orderByDesc('date')->limit($limite)->get();
    }

    public function numeroPendientes(): int
    {
        return Expense::query()->where('pending_review', true)->count();
    }

    /** Importe de un gasto: su total, o la suma de sus lineas si aun no tiene total. */
    public static function importe(Expense $gasto): float
    {
        if ($gasto->total !== null) {
            return (float) $gasto->total;
        }

        return round((float) $gasto->items()->sum(DB::raw('quantity * unit_price')), 2);
    }

    public static function dinero(float|string|null $valor): string
    {
        return Expense::formatMoney($valor);
    }
}
