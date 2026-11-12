<?php

namespace App\Services\Acciones;

use App\Enums\OcrJobStatus;
use App\Jobs\ProcessOcrJob;
use App\Models\Expense;
use App\Models\OcrJob;
use App\Services\OcrProviderManager;
use Throwable;

/**
 * Lanza el OCR del ticket de un gasto. Es la regla que antes vivia incrustada en la accion «OCR del ticket» de ViewExpense:
 * el gasto necesita foto, no puede tener lineas (salvo el ajuste de descuadre) y no puede tener ya un OCR en cola. La comparten el
 * panel y el chat (bot y agente). No envia notificaciones de pantalla: quien llama decide como avisar (el job avisa al usuario por la
 * base de datos cuando termina).
 */
class LanzaOcr
{
    /** Hay un servicio OCR activo al que mandar tickets. */
    public function disponible(): bool
    {
        try {
            return app(OcrProviderManager::class)->isAvailable();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Crea el OcrJob y lo encola.
     *
     * @return array{ok: bool, mensaje: string}
     */
    public function lanzar(Expense $gasto, ?string $usuarioId = null): array
    {
        if (blank($gasto->ticket_photo_hash)) {
            return ['ok' => false, 'mensaje' => 'El gasto no tiene foto de ticket.'];
        }
        if (! $gasto->isOcrAvailable()) {
            return ['ok' => false, 'mensaje' => 'Este gasto ya tiene líneas. Borra las líneas antes de ejecutar OCR.'];
        }
        if (OcrJob::query()->where('expense_id', $gasto->id)->where('status', OcrJobStatus::PENDING)->exists()) {
            return ['ok' => false, 'mensaje' => 'Ya existe un proceso OCR pendiente para este gasto.'];
        }

        $trabajo = OcrJob::query()->create([
            'expense_id' => $gasto->id,
            'user_id' => $usuarioId,
            'status' => OcrJobStatus::PENDING,
            'ticket_path' => $gasto->ticket_photo_hash,
        ]);
        ProcessOcrJob::dispatch($trabajo->id)->afterCommit();   // el chat lo llama dentro de una transaccion

        return ['ok' => true, 'mensaje' => 'OCR en cola.'];
    }
}
