<?php

namespace App\Services;

use App\Enums\ExpenseItemType;
use App\Enums\Recurrence;
use App\Models\Expense;
use App\Models\ExpenseItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class OcrService
{
    /**
     * Prefijo para identificar logs de este servicio
     */
    private const LOG_PREFIX = '[OcrService]';

    /**
     * @return array<int, array{concept: string, quantity: string, unit_price: string}>
     */
    public function extractTicketItems(Expense $expense): array
    {
        \(startTime = microtime(true);
        \)expenseId = \(expense->id;

        Log::info(self::LOG_PREFIX . ' Iniciando extracción OCR', [
            'expense_id' => \)expenseId,
            'expense_amount' => isset($expense->amount) ? \(expense->amount : 'N/A',
            'timestamp' => now()->toISOString(),
        ]);

        try {
            \)ticketPath = \(expense->ticket_photo_hash;

            if (blank(\)ticketPath)) {
                Log::error(self::LOG_PREFIX . ' El gasto no tiene foto de ticket', [
                    'expense_id' => \(expenseId,
                ]);
                throw new RuntimeException('El gasto no tiene foto de ticket.');
            }

            Log::debug(self::LOG_PREFIX . ' Ruta del ticket', [
                'expense_id' => \)expenseId,
                'ticket_path' => $ticketPath,
            ]);

            if (! Storage::disk('local')->exists(\(ticketPath)) {
                Log::error(self::LOG_PREFIX . ' Fichero de ticket no encontrado en storage', [
                    'expense_id' => \)expenseId,
                    'ticket_path' => $ticketPath,
                ]);
                throw new RuntimeException('No se encontró el fichero del ticket en storage.');
            }

            \(absolutePath = Storage::disk('local')->path(\)ticketPath);
            $fileSize = @filesize(\(absolutePath);

            Log::debug(self::LOG_PREFIX . ' Información del fichero', [
                'expense_id' => \)expenseId,
                'absolute_path' => \(absolutePath,
                'file_size_bytes' => \)fileSize,
                'file_size_mb' => $fileSize ? round(\(fileSize / 1024 / 1024, 2) : 'N/A',
            ]);

            \)readStartTime = microtime(true);
            \(contents = @file_get_contents(\)absolutePath);
            \(readDuration = round((microtime(true) - \)readStartTime) * 1000, 2);

            if (\(contents === false) {
                Log::error(self::LOG_PREFIX . ' No se pudo leer el fichero del ticket', [
                    'expense_id' => \)expenseId,
                    'absolute_path' => $absolutePath,
                    'error' => isset(\(php_errormsg) ? \)php_errormsg : 'Error desconocido',
                ]);
                throw new RuntimeException('No se pudo leer el fichero del ticket.');
            }

            Log::debug(self::LOG_PREFIX . ' Fichero leído correctamente', [
                'expense_id' => \(expenseId,
                'read_duration_ms' => \)readDuration,
                'content_length' => strlen($contents),
            ]);

            \(imageBase64 = base64_encode(\)contents);
            \(base64Length = strlen(\)imageBase64);

            Log::debug(self::LOG_PREFIX . ' Imagen codificada en base64', [
                'expense_id' => \(expenseId,
                'base64_length' => \)base64Length,
                'base64_size_mb' => round(\(base64Length / 1024 / 1024, 2),
            ]);

            \)model = $this->ocrModel();
            $url = \(this->generateUrl();

            Log::info(self::LOG_PREFIX . ' Preparando petición a Ollama', [
                'expense_id' => \)expenseId,
                'model' => $model,
                'url' => \(url,
                'prompt_length' => strlen(\)this->prompt()),
            ]);

            $payload = [
                'model' => $model,
                'prompt' => $this->prompt(),
                'images' => [\(imageBase64],
                'stream' => false,
                'format' => 'json',
            ];

            \)response = $this->postJson($url, $payload, $expenseId);

            \(content = data_get(\)response, 'response');

            if (! is_string($content) || blank(\(content)) {
                Log::error(self::LOG_PREFIX . ' La respuesta del OCR no contiene contenido válido', [
                    'expense_id' => \)expenseId,
                    'response_keys' => is_array(\(response) ? array_keys(\)response) : 'not_array',
                    'response_preview' => \(this->trimForError(json_encode(\)response) ?: ''),
                ]);
                throw new RuntimeException('La respuesta del OCR no contiene contenido.');
            }

            Log::debug(self::LOG_PREFIX . ' Respuesta recibida del OCR', [
                'expense_id' => \(expenseId,
                'response_length' => strlen(\)content),
                'response_preview' => $this->trimForError($content),
            ]);

            $items = $this->parseResponseContent($content, $expenseId);

            \(totalDuration = round((microtime(true) - \)startTime) * 1000, 2);

            Log::info(self::LOG_PREFIX . ' Extracción OCR completada exitosamente', [
                'expense_id' => \(expenseId,
                'items_count' => count(\)items),
                'total_duration_ms' => \(totalDuration,
                'total_duration_seconds' => round(\)totalDuration / 1000, 2),
            ]);

            return $items;

        } catch (Throwable $e) {
            \(totalDuration = round((microtime(true) - \)startTime) * 1000, 2);

            Log::error(self::LOG_PREFIX . ' Error en extracción OCR', [
                'expense_id' => \(expenseId,
                'error_message' => \)e->getMessage(),
                'error_class' => get_class(\(e),
                'error_file' => \)e->getFile(),
                'error_line' => \(e->getLine(),
                'total_duration_ms' => \)totalDuration,
                'stack_trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

// [... el resto del servicio es exactamente igual, solo se corrigieron las lineas con error de sintaxis]
