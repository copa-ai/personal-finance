<?php

namespace App\Services\Acciones;

use App\Models\Auditoria;
use App\Models\Category;
use App\Models\Establishment;
use App\Models\Expense;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reglas de categorias y establecimientos que comparten el panel y el chat: crear, renombrar, cambiar la categoria por defecto de un
 * establecimiento, borrar una categoria y fusionar establecimientos. Una InvalidArgumentException es un rechazo previsible.
 */
class GestionCatalogo
{
    // ------------------------------------------------------------------ categorias

    /** Categoria por nombre sin distinguir mayusculas (la columna es unica, tambien entre las borradas). */
    public function categoriaPorNombre(string $nombre, bool $conBorradas = false): ?Category
    {
        $consulta = $conBorradas ? Category::query()->withTrashed() : Category::query();

        return $consulta->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($nombre))])->first();
    }

    public function crearCategoria(string $nombre, ?Category $padre = null, string $origen = 'panel'): Category
    {
        $nombre = trim($nombre);
        if ($existente = $this->categoriaPorNombre($nombre, true)) {
            throw new InvalidArgumentException($existente->trashed()
                ? "Ya existió una categoría «{$existente->name}» y está borrada: se restaura desde el panel."
                : "Ya existe una categoría llamada «{$existente->name}».");
        }
        $categoria = Category::query()->create(['name' => $nombre, 'parent_id' => $padre?->id]);
        Auditoria::registrar('categoria.creada', "Categoría creada desde {$origen}", ['categoria_id' => $categoria->id, 'nombre' => $nombre, 'origen' => $origen]);

        return $categoria;
    }

    /**
     * Cambia el nombre y/o el padre. `padre` null la deja sin padre; si la clave no esta, no se toca.
     *
     * @param  array{nombre?: string, padre?: ?Category}  $cambios
     */
    public function editarCategoria(Category $categoria, array $cambios, string $origen = 'panel'): Category
    {
        $atributos = [];
        if (isset($cambios['nombre'])) {
            $nombre = trim($cambios['nombre']);
            $otra = $this->categoriaPorNombre($nombre, true);
            if ($otra && $otra->id !== $categoria->id) {
                throw new InvalidArgumentException("Ya existe una categoría llamada «{$otra->name}».");
            }
            $atributos['name'] = $nombre;
        }
        if (array_key_exists('padre', $cambios)) {
            $padre = $cambios['padre'];
            if ($padre && ($padre->id === $categoria->id || $categoria->descendants()->contains('id', $padre->id))) {
                throw new InvalidArgumentException('Una categoría no puede colgar de sí misma ni de una de sus subcategorías.');
            }
            $atributos['parent_id'] = $padre?->id;
        }
        if ($atributos === []) {
            throw new InvalidArgumentException('No hay nada que cambiar.');
        }
        $categoria->fill($atributos)->save();
        Auditoria::registrar('categoria.editada', "Categoría editada desde {$origen}", ['categoria_id' => $categoria->id, 'campos' => array_keys($atributos), 'origen' => $origen]);

        return $categoria;
    }

    /** @return array{lineas: int, establecimientos: int, subcategorias: int} lo que usa la categoria */
    public function usoDeCategoria(Category $categoria): array
    {
        return [
            'lineas' => DB::table('expense_items')->where('category_id', $categoria->id)->count(),
            'establecimientos' => Establishment::query()->where('category_id', $categoria->id)->count(),
            'subcategorias' => Category::query()->where('parent_id', $categoria->id)->count(),
        ];
    }

    /** Borrado suave (como el panel): se puede restaurar desde el panel. Una categoria con subcategorias no se borra. */
    public function borrarCategoria(Category $categoria, string $origen = 'panel'): void
    {
        if ($this->usoDeCategoria($categoria)['subcategorias'] > 0) {
            throw new InvalidArgumentException("La categoría «{$categoria->name}» tiene subcategorías: muévelas o bórralas antes.");
        }
        $categoria->delete();
        Auditoria::registrar('categoria.borrada', "Categoría borrada desde {$origen}", ['categoria_id' => $categoria->id, 'nombre' => $categoria->name, 'origen' => $origen]);
    }

    // ------------------------------------------------------------------ establecimientos

    public function establecimientoPorNombre(string $nombre): ?Establishment
    {
        return Establishment::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($nombre))])->first();
    }

    public function crearEstablecimiento(string $nombre, ?Category $categoria = null, string $origen = 'panel'): Establishment
    {
        $nombre = trim($nombre);
        if ($existente = $this->establecimientoPorNombre($nombre)) {
            throw new InvalidArgumentException("Ya existe un establecimiento llamado «{$existente->name}».");
        }
        $e = Establishment::query()->create(['name' => $nombre, 'category_id' => $categoria?->id]);
        Auditoria::registrar('establecimiento.creado', "Establecimiento creado desde {$origen}", ['establecimiento_id' => $e->id, 'nombre' => $nombre, 'origen' => $origen]);

        return $e;
    }

    /** El establecimiento con ese nombre (sin distinguir mayusculas) o uno nuevo, sin categoria por defecto. */
    public function establecimientoOCrear(string $nombre, string $origen = 'panel'): Establishment
    {
        return $this->establecimientoPorNombre($nombre) ?? $this->crearEstablecimiento($nombre, null, $origen);
    }

    /**
     * Cambia el nombre y/o la categoria por defecto (`categoria` null la quita; si la clave no esta, no se toca).
     *
     * @param  array{nombre?: string, categoria?: ?Category}  $cambios
     */
    public function editarEstablecimiento(Establishment $e, array $cambios, string $origen = 'panel'): Establishment
    {
        $atributos = [];
        if (isset($cambios['nombre'])) {
            $nombre = trim($cambios['nombre']);
            $otro = $this->establecimientoPorNombre($nombre);
            if ($otro && $otro->id !== $e->id) {
                throw new InvalidArgumentException("Ya existe un establecimiento llamado «{$otro->name}»: usa fusionar_establecimientos si es el mismo.");
            }
            $atributos['name'] = $nombre;
        }
        if (array_key_exists('categoria', $cambios)) {
            $atributos['category_id'] = $cambios['categoria']?->id;
        }
        if ($atributos === []) {
            throw new InvalidArgumentException('No hay nada que cambiar.');
        }
        $e->fill($atributos)->save();
        Auditoria::registrar('establecimiento.editado', "Establecimiento editado desde {$origen}", ['establecimiento_id' => $e->id, 'campos' => array_keys($atributos), 'origen' => $origen]);

        return $e;
    }

    /**
     * Fusiona establecimientos duplicados: los gastos de los origenes pasan al destino y los origenes se borran. Si el destino no
     * tiene categoria por defecto hereda la del primer origen que la tenga.
     *
     * @param  list<Establishment>  $origenes
     * @return int gastos movidos
     */
    public function fusionarEstablecimientos(Establishment $destino, array $origenes, string $origen = 'panel'): int
    {
        $ids = array_values(array_unique(array_map(fn (Establishment $o): string => (string) $o->id, $origenes)));
        if ($ids === [] || in_array((string) $destino->id, $ids, true)) {
            throw new InvalidArgumentException('Los orígenes tienen que ser otros establecimientos distintos del destino.');
        }

        return DB::transaction(function () use ($destino, $origenes, $ids, $origen): int {
            if ($destino->category_id === null) {
                $categoria = collect($origenes)->first(fn (Establishment $o): bool => $o->category_id !== null)?->category_id;
                $categoria !== null && $destino->forceFill(['category_id' => $categoria])->save();
            }
            $movidos = Expense::query()->whereIn('establishment_id', $ids)->update(['establishment_id' => $destino->id]);
            Establishment::query()->whereIn('id', $ids)->get()->each->delete();
            Auditoria::registrar('establecimiento.fusionado', "Establecimientos fusionados desde {$origen}",
                ['destino_id' => $destino->id, 'origenes' => $ids, 'gastos_movidos' => $movidos, 'origen' => $origen]);

            return $movidos;
        });
    }
}
