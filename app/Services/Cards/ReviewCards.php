<?php

namespace App\Services\Cards;

use App\Models\Expense;
use App\Services\Acciones\ResumenGastos;
use App\Services\Chat\Archivos;
use App\Services\Chat\ErrorChat;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Las filas del mazo de revision: una por gasto con pending_review = true, los mas antiguos primero. Es una FOTO del momento en que se
 * pide el mazo (texto, detalle, grupo e imagen ya calculados); durante la sesion el mazo no cambia. Solo lee gastos, lineas, categorias
 * y establecimientos.
 */
class ReviewCards
{
    public function __construct(private readonly Archivos $archivos) {}

    /** @return list<array<string, mixed>> item_id (id del gasto), titulo, texto, detalle (completo, se acorta al servirlo), grupo, imagen */
    public function filas(): array
    {
        $gastos = Expense::query()->with(['establishment.category', 'items.category'])
            ->where('pending_review', true)
            ->orderByRaw('date asc nulls last')->orderBy('created_at')->orderBy('id')
            ->limit((int) config('cards.max_tarjetas'))->get();

        return $gastos->map(fn (Expense $g): array => $this->fila($g))->all();
    }

    /** @return array<string, mixed> */
    private function fila(Expense $gasto): array
    {
        $lineas = $gasto->items;
        $fecha = $gasto->date?->copy()->setTimezone(ResumenGastos::zona())->format('d/m/Y H:i') ?? 'sin fecha';
        $n = $lineas->count();

        return [
            'item_id' => (string) $gasto->id,
            'titulo' => ($gasto->establishment?->name ?? 'Sin establecimiento').' · '.($gasto->total === null ? 'sin total' : ResumenGastos::dinero($gasto->total)),
            'texto' => $fecha.' · '.($n === 0 ? 'sin líneas' : ($n === 1 ? '1 línea' : "{$n} líneas")),
            'lineas' => $lineas->take((int) config('cards.max_lineas_detalle'))->map(fn ($l): string => sprintf(
                '%s: %s x %s = %s%s',
                $l->concept ?: 'Sin concepto',
                rtrim(rtrim(number_format((float) $l->quantity, 3, ',', ''), '0'), ','),
                ResumenGastos::dinero($l->unit_price),
                ResumenGastos::dinero($l->line_total),
                ' ('.($l->category?->name ?? 'sin categoría').')',
            ))->all(),
            'lineas_total' => $n,
            'grupo' => $this->grupo($gasto),
            'imagen' => $this->imagen($gasto),
        ];
    }

    /** Categoria principal: la de la linea de mayor importe, o la del establecimiento si no hay lineas con categoria. */
    private function grupo(Expense $gasto): string
    {
        $mejor = null;
        $importe = -1.0;
        foreach ($gasto->items as $l) {
            $categoria = $l->category ?? $gasto->establishment?->category;
            if ($categoria !== null && (float) $l->line_total > $importe) {
                $mejor = $categoria->name;
                $importe = (float) $l->line_total;
            }
        }

        return $mejor ?? $gasto->establishment?->category?->name ?? 'Sin categoría';
    }

    /**
     * La foto del ticket como archivo del chat (por hash), o null. Solo PNG o JPEG (por su contenido, no por la extension) que quepan
     * en el tope; cualquier otra cosa (HEIC, WebP, falta el fichero...) deja la tarjeta solo con texto.
     *
     * @return array{hash: string, mime: string, tamano: int}|null
     */
    private function imagen(Expense $gasto): ?array
    {
        $ruta = trim((string) $gasto->ticket_photo_hash);
        if ($ruta === '') {
            return null;
        }
        try {
            $disco = Storage::disk('local');
            if (! $disco->exists($ruta) || $disco->size($ruta) > (int) config('cards.max_imagen_bytes')) {
                return null;
            }
            $bytes = (string) $disco->get($ruta);
            $info = @getimagesizefromstring($bytes);
            $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
            if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
                return null;
            }
            $hash = hash('sha3-256', $bytes);
            $archivo = $this->archivos->guardar($hash, $bytes, $mime, (string) strlen($bytes), basename($ruta));

            return ['hash' => $archivo->hash, 'mime' => $archivo->mime, 'tamano' => (int) $archivo->tamano];
        } catch (ErrorChat) {
            return null;   // sin sitio en el chat u otro rechazo: la tarjeta va sin imagen
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
