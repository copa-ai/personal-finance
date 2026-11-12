<?php

namespace App\Services\Acciones;

use App\Enums\ExpenseStatus;
use App\Models\Auditoria;
use App\Models\Category;
use App\Models\ChatArchivo;
use App\Models\Establishment;
use App\Models\Expense;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Reglas de los gastos que comparten el panel, el bot del chat y el agente de Claude: crear con sus lineas, editar, marcar como
 * revisado o pendiente, guardar la foto de un ticket (y lanzar el OCR) y borrar. Todo queda en la auditoria. Respeta los Observers
 * de Expense y ExpenseItem: un gasto se crea SIN foto y la foto se anade despues, asi el observer de «creado» no encola un OCR
 * por su cuenta (y no manda notificaciones de pantalla desde la cola).
 *
 * Una InvalidArgumentException es un rechazo previsible (se dice a la persona tal cual); cualquier otra es un error.
 */
class GestionGastos
{
    /** Carpeta del disco `local` donde van los tickets (la misma que usa el formulario del gasto). */
    public const CARPETA_TICKETS = 'tickets';

    public function __construct(private readonly GestionLineas $lineas, private readonly GestionCatalogo $catalogo, private readonly LanzaOcr $ocr) {}

    /**
     * @param  array{establecimiento?: ?Establishment, fecha: CarbonInterface, total?: ?float, estado?: ExpenseStatus, pendiente_revision?: bool, lineas?: list<array<string, mixed>>}  $datos
     */
    public function crear(array $datos, string $origen = 'panel'): Expense
    {
        return DB::transaction(function () use ($datos, $origen): Expense {
            $gasto = new Expense;
            $gasto->fill([
                'establishment_id' => ($datos['establecimiento'] ?? null)?->id,
                'date' => $datos['fecha']->copy()->utc(),
                'total' => $datos['total'] ?? null,
                'status' => $datos['estado'] ?? ExpenseStatus::PAID,
                'pending_review' => $datos['pendiente_revision'] ?? false,
                'created_at' => now(),
            ]);
            $gasto->save();
            foreach ($datos['lineas'] ?? [] as $linea) {
                $this->lineas->anadir($gasto, $linea, auditar: false);
            }
            Auditoria::registrar('gasto.creado', "Gasto creado desde {$origen}", ['gasto_id' => $gasto->id, 'lineas' => count($datos['lineas'] ?? []), 'origen' => $origen]);

            return $gasto->refresh();
        });
    }

    /**
     * Cambia establecimiento, fecha, total o estado. Solo se tocan las claves presentes (`establecimiento` puede ser null: lo quita).
     *
     * @param  array<string, mixed>  $cambios  establecimiento (?Establishment), fecha (CarbonInterface), total (?float), estado (ExpenseStatus)
     */
    public function editar(Expense $gasto, array $cambios, string $origen = 'panel'): Expense
    {
        $atributos = [];
        if (array_key_exists('establecimiento', $cambios)) {
            $atributos['establishment_id'] = $cambios['establecimiento']?->id;
        }
        if (array_key_exists('fecha', $cambios)) {
            $atributos['date'] = $cambios['fecha']->copy()->utc();
        }
        if (array_key_exists('total', $cambios)) {
            $atributos['total'] = $cambios['total'];
        }
        if (array_key_exists('estado', $cambios)) {
            $atributos['status'] = $cambios['estado'];
        }
        if ($atributos === []) {
            throw new InvalidArgumentException('No hay nada que cambiar.');
        }
        $gasto->fill($atributos)->save();
        $gasto->forgetSubexpensesTotal();
        Auditoria::registrar('gasto.editado', "Gasto editado desde {$origen}", ['gasto_id' => $gasto->id, 'campos' => array_keys($atributos), 'origen' => $origen]);

        return $gasto;
    }

    /** Marca o desmarca «pendiente de revisar». */
    public function marcarRevision(Expense $gasto, bool $pendiente, string $origen = 'panel'): Expense
    {
        $gasto->forceFill(['pending_review' => $pendiente])->save();
        Auditoria::registrar($pendiente ? 'gasto.marcado_pendiente' : 'gasto.revisado', $pendiente ? "Gasto marcado para revisar desde {$origen}" : "Gasto marcado como revisado desde {$origen}",
            ['gasto_id' => $gasto->id, 'origen' => $origen]);

        return $gasto;
    }

    /** Borra el gasto (las lineas y los OCR asociados caen en cascada). La foto del ticket se conserva en el disco. */
    public function borrar(Expense $gasto, string $origen = 'panel'): void
    {
        $id = $gasto->id;
        $gasto->delete();
        Auditoria::registrar('gasto.borrado', "Gasto borrado desde {$origen}", ['gasto_id' => $id, 'origen' => $origen]);
    }

    /**
     * Guarda una foto de ticket del chat en el disco `local` (tickets/{hash}.ext, como los tickets que sube el formulario) y devuelve su
     * ruta relativa, que es lo que va en `ticket_photo_hash`. El nombre sale del hash: la misma foto no se guarda dos veces.
     */
    public function guardarFotoDeChat(string $hash): string
    {
        $archivo = ChatArchivo::query()->where('hash', $hash)->first();
        if (! $archivo) {
            throw new InvalidArgumentException('La foto ya no está disponible en el chat.');
        }
        $extension = match ($archivo->mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $ruta = self::CARPETA_TICKETS.'/'.$hash.'.'.$extension;
        if (! Storage::disk('local')->exists($ruta)) {
            Storage::disk('local')->put($ruta, $archivo->bytes());
        }

        return $ruta;
    }

    /**
     * Pone la foto de un ticket al gasto y, si el OCR esta disponible y el gasto aun no tiene lineas, lo encola.
     *
     * @return array{ocr: bool, mensaje: string} si se encolo el OCR y, si no, por que no
     */
    public function adjuntarFoto(Expense $gasto, string $ruta, string $origen = 'panel'): array
    {
        $gasto->forceFill(['ticket_photo_hash' => $ruta])->save();
        Auditoria::registrar('gasto.foto_adjuntada', "Foto de ticket adjuntada desde {$origen}", ['gasto_id' => $gasto->id, 'origen' => $origen]);

        if (! $gasto->isOcrAvailable()) {
            return ['ocr' => false, 'mensaje' => 'el gasto ya tiene líneas, así que no se ha lanzado el OCR'];
        }
        if (! $this->ocr->disponible()) {
            return ['ocr' => false, 'mensaje' => 'no hay un servicio OCR activo'];
        }
        $r = $this->ocr->lanzar($gasto, User::query()->orderBy('id')->value('id'));

        return ['ocr' => $r['ok'], 'mensaje' => $r['ok'] ? 'OCR en cola' : $r['mensaje']];
    }

    /**
     * Un gasto nuevo para una foto de ticket del chat: sin total ni establecimiento, pendiente de revisar, con la foto y el OCR en cola.
     *
     * @return array{gasto: Expense, ocr: bool, mensaje: string}
     */
    public function crearDesdeFotoDeChat(string $hash, string $origen = 'chat'): array
    {
        $ruta = $this->guardarFotoDeChat($hash);
        $existente = Expense::query()->where('ticket_photo_hash', $ruta)->first();
        if ($existente) {
            return ['gasto' => $existente, 'ocr' => false, 'mensaje' => 'esa foto ya estaba registrada en otro gasto'];
        }
        $gasto = $this->crear(['fecha' => now(), 'estado' => ExpenseStatus::PAID, 'pendiente_revision' => true], $origen);
        $r = $this->adjuntarFoto($gasto, $ruta, $origen);

        return ['gasto' => $gasto->refresh(), 'ocr' => $r['ocr'], 'mensaje' => $r['mensaje']];
    }

    /** Categoria por defecto de un establecimiento, para quien cree gastos sin categoria en las lineas. */
    public function categoriaPorDefecto(Expense $gasto): ?Category
    {
        return $gasto->establishment?->category;
    }
}
