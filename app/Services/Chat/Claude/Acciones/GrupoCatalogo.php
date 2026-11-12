<?php

namespace App\Services\Chat\Claude\Acciones;

use App\Models\Category;
use App\Models\Establishment;
use App\Services\Acciones\GestionCatalogo;
use App\Services\Chat\Claude\Validadores;
use InvalidArgumentException;

/**
 * Categorias y establecimientos.
 *   crear_categoria           {nombre, padre?}
 *   editar_categoria          {categoria, nombre?, padre? (null la deja sin padre)}
 *   borrar_categoria          {categoria}                         (pide confirmacion; borrado suave, se restaura desde el panel)
 *   crear_establecimiento     {nombre, categoria?}                (categoria = la categoria por defecto de sus gastos)
 *   editar_establecimiento    {establecimiento, nombre?, categoria? (null la quita)}
 *   fusionar_establecimientos {destino, origenes}                 (pide confirmacion: los gastos pasan al destino y los origenes se borran)
 * `categoria`, `padre`, `establecimiento` y `destino` son un id o el nombre exacto.
 */
class GrupoCatalogo implements GrupoAcciones
{
    public function __construct(private readonly Validadores $v) {}

    public function claves(): array
    {
        return [
            'crear_categoria' => ['tipo', 'nombre', 'padre'],
            'editar_categoria' => ['tipo', 'categoria', 'nombre', 'padre'],
            'borrar_categoria' => ['tipo', 'categoria'],
            'crear_establecimiento' => ['tipo', 'nombre', 'categoria'],
            'editar_establecimiento' => ['tipo', 'establecimiento', 'nombre', 'categoria'],
            'fusionar_establecimientos' => ['tipo', 'destino', 'origenes'],
        ];
    }

    public function validar(string $tipo, array $accion): array|string
    {
        return match ($tipo) {
            'crear_categoria' => $this->validarCrearCategoria($accion),
            'editar_categoria' => $this->validarEditarCategoria($accion),
            'borrar_categoria' => $this->validarBorrarCategoria($accion),
            'crear_establecimiento' => $this->validarCrearEstablecimiento($accion),
            'editar_establecimiento' => $this->validarEditarEstablecimiento($accion),
            default => $this->validarFusion($accion),
        };
    }

    public function destructiva(array $normalizada): bool
    {
        return in_array($normalizada['tipo'], ['borrar_categoria', 'fusionar_establecimientos'], true);
    }

    /** @param  array<string, mixed>  $a */
    private function validarCrearCategoria(array $a): array|string
    {
        $nombre = $this->v->nombre($a['nombre'] ?? null);
        $padre = $this->v->categoria($a['padre'] ?? null, 'padre', true);
        if (($motivo = $this->v->motivo($nombre, $padre)) !== null) {
            return $motivo;
        }
        if ($existente = app(GestionCatalogo::class)->categoriaPorNombre($nombre, true)) {
            return "Ya existe una categoría llamada «{$existente->name}»".($existente->trashed() ? ' (borrada: se restaura desde el panel).' : '.');
        }

        return ['tipo' => 'crear_categoria', 'nombre' => $nombre, 'padre_id' => $padre?->id, 'quiero' => "Crear la categoría «{$nombre}»".($padre ? " dentro de «{$padre->name}»" : '')];
    }

    /** @param  array<string, mixed>  $a */
    private function validarEditarCategoria(array $a): array|string
    {
        $categoria = $this->v->categoria($a['categoria'] ?? null);
        $cambios = [];
        if (array_key_exists('nombre', $a)) {
            $cambios['nombre'] = $this->v->nombre($a['nombre']);
        }
        if (array_key_exists('padre', $a)) {
            $cambios['padre'] = $this->v->categoria($a['padre'], 'padre', true);
        }
        if (($motivo = $this->v->motivo($categoria, ...array_values($cambios))) !== null) {
            return $motivo;
        }
        if ($cambios === []) {
            return 'La edición no cambia nada.';
        }
        if (isset($cambios['padre']) && ($cambios['padre']->id === $categoria->id || $categoria->descendants()->contains('id', $cambios['padre']->id))) {
            return 'Una categoría no puede colgar de sí misma ni de una de sus subcategorías.';
        }
        $otra = isset($cambios['nombre']) ? app(GestionCatalogo::class)->categoriaPorNombre($cambios['nombre'], true) : null;
        if ($otra && $otra->id !== $categoria->id) {
            return "Ya existe una categoría llamada «{$otra->name}».";
        }

        return ['tipo' => 'editar_categoria', 'categoria_id' => $categoria->id, 'cambios' => array_map(fn ($c) => $c instanceof Category ? $c->id : $c, $cambios),
            'hay_padre' => array_key_exists('padre', $cambios), 'quiero' => "Editar la categoría «{$categoria->name}» (".implode(', ', array_keys($cambios)).')'];
    }

    /** @param  array<string, mixed>  $a */
    private function validarBorrarCategoria(array $a): array|string
    {
        $categoria = $this->v->categoria($a['categoria'] ?? null);
        if (($motivo = $this->v->motivo($categoria)) !== null) {
            return $motivo;
        }
        $uso = app(GestionCatalogo::class)->usoDeCategoria($categoria);
        if ($uso['subcategorias'] > 0) {
            return "La categoría «{$categoria->name}» tiene {$uso['subcategorias']} subcategoría(s): muévelas o bórralas antes.";
        }

        return ['tipo' => 'borrar_categoria', 'categoria_id' => $categoria->id,
            'quiero' => "Borrar la categoría «{$categoria->name}» (la usan {$uso['lineas']} línea(s) y {$uso['establecimientos']} establecimiento(s); se puede restaurar desde el panel)"];
    }

    /** @param  array<string, mixed>  $a */
    private function validarCrearEstablecimiento(array $a): array|string
    {
        $nombre = $this->v->nombre($a['nombre'] ?? null);
        $categoria = $this->v->categoria($a['categoria'] ?? null, 'categoria', true);
        if (($motivo = $this->v->motivo($nombre, $categoria)) !== null) {
            return $motivo;
        }
        if ($existente = app(GestionCatalogo::class)->establecimientoPorNombre($nombre)) {
            return "Ya existe un establecimiento llamado «{$existente->name}».";
        }

        return ['tipo' => 'crear_establecimiento', 'nombre' => $nombre, 'categoria_id' => $categoria?->id,
            'quiero' => "Crear el establecimiento «{$nombre}»".($categoria ? " (categoría por defecto «{$categoria->name}»)" : '')];
    }

    /** @param  array<string, mixed>  $a */
    private function validarEditarEstablecimiento(array $a): array|string
    {
        $e = $this->v->establecimiento($a['establecimiento'] ?? null);
        $cambios = [];
        if (array_key_exists('nombre', $a)) {
            $cambios['nombre'] = $this->v->nombre($a['nombre']);
        }
        if (array_key_exists('categoria', $a)) {
            $cambios['categoria'] = $this->v->categoria($a['categoria'], 'categoria', true);
        }
        if (($motivo = $this->v->motivo($e, ...array_values($cambios))) !== null) {
            return $motivo;
        }
        if ($cambios === []) {
            return 'La edición no cambia nada.';
        }
        $otro = isset($cambios['nombre']) ? app(GestionCatalogo::class)->establecimientoPorNombre($cambios['nombre']) : null;
        if ($otro && $otro->id !== $e->id) {
            return "Ya existe un establecimiento llamado «{$otro->name}»: usa fusionar_establecimientos si es el mismo.";
        }

        return ['tipo' => 'editar_establecimiento', 'establecimiento_id' => $e->id, 'cambios' => array_map(fn ($c) => $c instanceof Category ? $c->id : $c, $cambios),
            'hay_categoria' => array_key_exists('categoria', $cambios), 'quiero' => "Editar el establecimiento «{$e->name}» (".implode(', ', array_keys($cambios)).')'];
    }

    /** @param  array<string, mixed>  $a */
    private function validarFusion(array $a): array|string
    {
        $destino = $this->v->establecimiento($a['destino'] ?? null, 'destino');
        $origenes = $a['origenes'] ?? null;
        if (! is_array($origenes) || ! array_is_list($origenes) || $origenes === [] || count($origenes) > 20) {
            return 'origenes tiene que ser una lista de 1 a 20 establecimientos (ids o nombres).';
        }
        $modelos = [];
        foreach ($origenes as $o) {
            $e = $this->v->establecimiento($o, 'origenes');
            if (($motivo = $this->v->motivo($e)) !== null) {
                return $motivo;
            }
            $modelos[$e->id] = $e;
        }
        if (($motivo = $this->v->motivo($destino)) !== null) {
            return $motivo;
        }
        if (isset($modelos[$destino->id])) {
            return 'El destino no puede estar también entre los orígenes.';
        }
        $gastos = \App\Models\Expense::query()->whereIn('establishment_id', array_keys($modelos))->count();

        return ['tipo' => 'fusionar_establecimientos', 'destino_id' => $destino->id, 'origenes_ids' => array_keys($modelos),
            'quiero' => 'Fusionar '.implode(', ', array_map(fn (Establishment $e): string => "«{$e->name}»", array_values($modelos)))." en «{$destino->name}» ({$gastos} gasto(s) pasan al destino y los demás se borran)"];
    }

    public function aplicar(array $a): string
    {
        $c = app(GestionCatalogo::class);
        switch ($a['tipo']) {
            case 'crear_categoria':
                $categoria = $c->crearCategoria($a['nombre'], $this->categoria($a['padre_id']), 'chat');

                return "Creada la categoría «{$categoria->name}» ({$categoria->id}).";

            case 'editar_categoria':
                $categoria = Category::query()->lockForUpdate()->findOrFail($a['categoria_id']);
                $cambios = [];
                isset($a['cambios']['nombre']) && $cambios['nombre'] = $a['cambios']['nombre'];
                $a['hay_padre'] && $cambios['padre'] = $this->categoria($a['cambios']['padre'] ?? null);
                $c->editarCategoria($categoria, $cambios, 'chat');

                return "Editada la categoría «{$categoria->name}».";

            case 'borrar_categoria':
                $categoria = Category::query()->findOrFail($a['categoria_id']);
                $c->borrarCategoria($categoria, 'chat');

                return "Borrada la categoría «{$categoria->name}».";

            case 'crear_establecimiento':
                $e = $c->crearEstablecimiento($a['nombre'], $this->categoria($a['categoria_id']), 'chat');

                return "Creado el establecimiento «{$e->name}» ({$e->id}).";

            case 'editar_establecimiento':
                $e = Establishment::query()->lockForUpdate()->findOrFail($a['establecimiento_id']);
                $cambios = [];
                isset($a['cambios']['nombre']) && $cambios['nombre'] = $a['cambios']['nombre'];
                $a['hay_categoria'] && $cambios['categoria'] = $this->categoria($a['cambios']['categoria'] ?? null);
                $c->editarEstablecimiento($e, $cambios, 'chat');

                return "Editado el establecimiento «{$e->name}».";

            default:
                $destino = Establishment::query()->findOrFail($a['destino_id']);
                $origenes = Establishment::query()->whereIn('id', $a['origenes_ids'])->get()->all();
                $movidos = $c->fusionarEstablecimientos($destino, $origenes, 'chat');

                return 'Fusionados '.count($origenes)." establecimiento(s) en «{$destino->name}»: {$movidos} gasto(s) movidos.";
        }
    }

    private function categoria(?string $id): ?Category
    {
        if ($id === null) {
            return null;
        }

        return Category::query()->find($id) ?? throw new InvalidArgumentException('La categoría ya no existe.');
    }
}
