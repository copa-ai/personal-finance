<?php

namespace App\Services;

use App\Enums\ExpenseItemType;
use App\Enums\Recurrence;
use App\Models\Expense;
use App\Models\ExpenseItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;
use RuntimeException;

class OcrService
{
    private const LOG_PREFIX = '[OcrService]';

    /**
     * @return array<int, array{concept: string, quantity: string, unit_price: string}>
     */
    public function extractTicketItems(Expense $expense): array
    {
        $startTime = microtime(true);
        $expenseId = $expense->id ?? null;

        Log::info(self::LOG_PREFIX . ' Iniciando extracción OCR', [
            'expense_id' => $expenseId,
            'expense_amount' => $expense->amount ?? 'N/A',
            'timestamp' => now()->toISOString(),
        ]);

        try {
            $ticketPath = $expense->ticket_photo_hash;

            if (blank($ticketPath)) {
                Log::error(self::LOG_PREFIX . ' El gasto no tiene foto de ticket', [
                    'expense_id' => $expenseId,
                ]);
                throw new RuntimeException('El gasto no tiene foto de ticket.');
            }

            if (! Storage::disk('local')->exists($ticketPath)) {
                Log::error(self::LOG_PREFIX . ' Fichero de ticket no encontrado', [
                    'expense_id' => $expenseId,
                    'ticket_path' => $ticketPath,
                ]);
                throw new RuntimeException('No se encontró el fichero del ticket en storage.');
            }

            $absolutePath = Storage::disk('local')->path($ticketPath);
            $fileSize = @filesize($absolutePath) ?: 0;

            Log::debug(self::LOG_PREFIX . ' Archivo cargado', [
                'expense_id' => $expenseId,
                'file_size_mb' => round($fileSize / 1024 / 1024, 2),
            ]);

            $contents = @file_get_contents($absolutePath);
            if ($contents === false) {
                Log::error(self::LOG_PREFIX . ' No se pudo leer el archivo', ['expense_id' => $expenseId]);
                throw new RuntimeException('No se pudo leer el fichero del ticket.');
            }

            $imageBase64 = base64_encode($contents);

            $url = $this->generateUrl();
            $model = $this->ocrModel();

            Log::info(self::LOG_PREFIX . ' Enviando solicitud a Ollama', [
                'expense_id' => $expenseId,
                'model' => $model,
                'url' => $url,
            ]);

            $payload = [
                'model' => $model,
                'prompt' => $this->prompt(),
                'images' => [$imageBase64],
                'stream' => false,
                'format' => 'json',
            ];

            $response = $this->postJson($url, $payload, $expenseId);
            $content = isset($response['response']) ? $response['response'] : null;

            if (! is_string($content) || blank($content)) {
                Log::error(self::LOG_PREFIX . ' Respuesta vacía del OCR', ['expense_id' => $expenseId]);
                throw new RuntimeException('La respuesta del OCR no contiene contenido.');
            }

            $items = $this->parseResponseContent($content, $expenseId);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Log::info(self::LOG_PREFIX . ' OCR completado correctamente', [
                'expense_id' => $expenseId,
                'items_count' => count($items),
                'duration_ms' => $duration,
            ]);

            return $items;

        } catch (Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Log::error(self::LOG_PREFIX . ' Error durante proceso OCR', [
                'expense_id' => $expenseId,
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'duration_ms' => $duration,
            ]);

            throw $e;
        }
    }

    // ... (mantengo los otros métodos casi iguales, solo cambio Throwable por Exception y limpio ??)

    public function importExpenseItemsFromTicketOcr(Expense $expense): int
    {
        $expenseId = $expense->id ?? null;
        Log::info(self::LOG_PREFIX . ' Iniciando importación desde OCR', ['expense_id' => $expenseId]);

        try {
            $items = $this->extractTicketItems($expense);
            return $this->importExpenseItems($expense, $items);
        } catch (Exception $e) {
            Log::error(self::LOG_PREFIX . ' Falló importación OCR', [
                'expense_id' => $expenseId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * @param  array<int, array{concept: string, quantity: string, unit_price: string}>  $items
     */
    public function importExpenseItems(Expense $expense, array $items): int
    {
        $expenseId = $expense->id ?? null;

        return DB::transaction(function () use ($expense, $items, $expenseId) {
            $expense->refresh();

            if ($expense->items()->exists()) {
                throw new RuntimeException('Este gasto ya tiene líneas. Borra las líneas antes de ejecutar OCR.');
            }

            if (empty($items)) {
                throw new RuntimeException('El OCR no devolvió ninguna línea.');
            }

            foreach ($items as $item) {
                ExpenseItem::create([
                    'expense_id' => $expense->id,
                    'product_id' => null,
                    'concept' => $item['concept'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'tags' => [],
                    'item_type' => ExpenseItemType::VARIABLE_IRREGULAR,
                    'recurrence' => Recurrence::NONE,
                    'is_consumable' => true,
                ]);
            }

            $expense->forceFill(['pending_review' => true])->save();

            Log::info(self::LOG_PREFIX . ' Items importados desde OCR', [
                'expense_id' => $expenseId,
                'count' => count($items)
            ]);

            return count($items);
        });
    }

    /**
     * Método postJson CORREGIDO (sin ?? problemático)
     */
    private function postJson(string $url, array $payload, $expenseId = null): array
    {
        $startTime = microtime(true);

        // Preparación segura para logging (evita ??)
        $payloadForLog = $payload;
        $imageLength = 0;
        if (isset($payload['images']) && is_array($payload['images']) && count($payload['images']) > 0) {
            $imageLength = strlen((string)$payload['images'][0]);
        }
        $payloadForLog['images'] = ['[BASE64_IMAGE_OMITTED - ' . $imageLength . ' chars]'];

        Log::info(self::LOG_PREFIX . ' Enviando petición a Ollama', [
            'expense_id' => $expenseId,
            'url' => $url,
            'model' => isset($payload['model']) ? $payload['model'] : 'unknown',
            'timeout' => 300,
        ]);

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => (int) config('services.ollama.timeout', 300),
            CURLOPT_CONNECTTIMEOUT => 30,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        if ($responseBody === false) {
            Log::error(self::LOG_PREFIX . ' Error cURL', [
                'expense_id' => $expenseId,
                'error' => $curlError,
                'duration_ms' => $duration
            ]);
            throw new RuntimeException('Error cURL: ' . $curlError);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            Log::error(self::LOG_PREFIX . ' Error HTTP del OCR', [
                'expense_id' => $expenseId,
                'http_code' => $httpCode,
                'response' => $this->trimForError($responseBody)
            ]);
            throw new RuntimeException("OCR falló con HTTP {$httpCode}");
        }

        $decoded = json_decode($responseBody, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Respuesta JSON inválida del OCR');
        }

        Log::info(self::LOG_PREFIX . ' Respuesta OCR recibida', [
            'expense_id' => $expenseId,
            'duration_ms' => $duration,
            'http_code' => $httpCode,
        ]);

        return $decoded;
    }

    // Mantengo el resto de métodos (prompt, parseResponseContent, decodeJsonFromContent, etc.)
    // sin cambios importantes, solo eliminando ?? innecesarios.

    private function trimForError(string $value): string
    {
        $value = trim($value);
        return strlen($value) > 500 ? substr($value, 0, 500) . '…' : $value;
    }

    private function ocrModel(): string
    {
        $model = trim((string) config('services.ollama.ocr_model', 'glm-ocr'));
        return $model !== '' ? $model : 'glm-ocr';
    }

    private function generateUrl(): string
    {
        $base = trim((string) config('services.ollama.url', 'http://localhost:11434'));
        if (! str_starts_with($base, 'http')) {
            $base = 'http://' . $base;
        }
        return rtrim($base, '/') . '/api/generate';
    }

    // ... (el resto de métodos como parseResponseContent, decodeJsonFromContent, etc. se mantienen igual)
}
