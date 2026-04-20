<?php

namespace App\Services;

use App\Enums\ExpenseItemType;
use App\Enums\Recurrence;
use App\Models\Expense;
use App\Models\ExpenseItem;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class OcrService
{
    /**
     * Prefijo para identificar logs de este servicio
     */
    private const LOG_PREFIX = '[OcrService]';

    public function getOcrModel(): string
    {
        return $this->ocrModel();
    }

    /**
     * @return array<int, array{concept: string, quantity: string, unit_price: string}>
     */
    public function extractTicketItems(Expense $expense): array
    {
        $startTime = microtime(true);
        $expenseId = $expense->id; // Puede ser string o int

        Log::info(self::LOG_PREFIX . ' Iniciando extracción OCR', [
            'expense_id' => $expenseId,
            'expense_amount' => $expense->amount,
            'timestamp' => now()->toISOString(),
        ]);

        try {
            // Validar ticket path
            $ticketPath = $expense->ticket_photo_hash;

            if (blank($ticketPath)) {
                Log::error(self::LOG_PREFIX . ' El gasto no tiene foto de ticket', [
                    'expense_id' => $expenseId,
                ]);
                throw new RuntimeException('El gasto no tiene foto de ticket.');
            }

            Log::debug(self::LOG_PREFIX . ' Ruta del ticket', [
                'expense_id' => $expenseId,
                'ticket_path' => $ticketPath,
            ]);

            // Verificar existencia del archivo
            if (!Storage::disk('local')->exists($ticketPath)) {
                Log::error(self::LOG_PREFIX . ' Fichero de ticket no encontrado en storage', [
                    'expense_id' => $expenseId,
                    'ticket_path' => $ticketPath,
                    'storage_disk' => 'local',
                ]);
                throw new RuntimeException('No se encontró el fichero del ticket en storage.');
            }

            $absolutePath = Storage::disk('local')->path($ticketPath);
            $fileSize = @filesize($absolutePath);
            $fileSizeValue = $fileSize !== false ? $fileSize : 0;

            Log::debug(self::LOG_PREFIX . ' Información del fichero', [
                'expense_id' => $expenseId,
                'absolute_path' => $absolutePath,
                'file_size_bytes' => $fileSizeValue,
                'file_size_mb' => round($fileSizeValue / 1024 / 1024, 2),
            ]);

            // Leer contenido del archivo
            $readStartTime = microtime(true);
            $contents = @file_get_contents($absolutePath);
            $readDuration = round((microtime(true) - $readStartTime) * 1000, 2);

            if ($contents === false) {
                $lastError = error_get_last();
                $errorMessage = isset($lastError['message']) ? $lastError['message'] : 'Error desconocido';

                Log::error(self::LOG_PREFIX . ' No se pudo leer el fichero del ticket', [
                    'expense_id' => $expenseId,
                    'absolute_path' => $absolutePath,
                    'error' => $errorMessage,
                ]);
                throw new RuntimeException('No se pudo leer el fichero del ticket.');
            }

            Log::debug(self::LOG_PREFIX . ' Fichero leído correctamente', [
                'expense_id' => $expenseId,
                'read_duration_ms' => $readDuration,
                'content_length' => strlen($contents),
            ]);

            // Codificar imagen en base64
            $cmd = sprintf(
                'base64 -w 0 %s',
                escapeshellarg($absolutePath)
            );

            $imageBase64 = trim(shell_exec($cmd));

            if (empty($imageBase64)) {
                throw new RuntimeException('Error generando base64 con comando del sistema');
            }
            $base64Length = strlen($imageBase64);

            Log::debug(self::LOG_PREFIX . ' Imagen codificada en base64', [
                'expense_id' => $expenseId,
                'base64_length' => $base64Length,
                'base64_size_mb' => round($base64Length / 1024 / 1024, 2),
            ]);

            // Preparar payload
            $model = $this->ocrModel();
            $url = $this->generateUrl();

            Log::info(self::LOG_PREFIX . ' Preparando petición a Ollama', [
                'expense_id' => $expenseId,
                'model' => $model,
                'url' => $url,
                'prompt_length' => strlen($this->prompt()),
            ]);

            $payload = [
                'model' => $model,
                'prompt' => $this->prompt(),
                'images' => [$imageBase64],
                'stream' => false,
                // ELIMINADO: 'format' => 'json' - Causa el error GGML_ASSERT
            ];

            // Enviar petición
            $response = $this->postJson($url, $payload, $expenseId);

            $content = isset($response['response']) ? $response['response'] : null;

            if (!is_string($content) || blank($content)) {
                $responseKeys = is_array($response) ? array_keys($response) : [];
                $responsePreview = $this->trimForError(json_encode($response));

                Log::error(self::LOG_PREFIX . ' La respuesta del OCR no contiene contenido válido', [
                    'expense_id' => $expenseId,
                    'response_keys' => $responseKeys,
                    'response_preview' => $responsePreview,
                ]);
                throw new RuntimeException('La respuesta del OCR no contiene contenido.');
            }

            Log::debug(self::LOG_PREFIX . ' Respuesta recibida del OCR', [
                'expense_id' => $expenseId,
                'response_length' => strlen($content),
                'response_preview' => $this->trimForError($content),
            ]);

            // Parsear respuesta
            $items = $this->parseResponseContent($content, $expenseId);

            $totalDuration = round((microtime(true) - $startTime) * 1000, 2);

            Log::info(self::LOG_PREFIX . ' Extracción OCR completada exitosamente', [
                'expense_id' => $expenseId,
                'items_count' => count($items),
                'total_duration_ms' => $totalDuration,
                'total_duration_seconds' => round($totalDuration / 1000, 2),
            ]);

            return $items;

        } catch (Exception $e) {
            $totalDuration = round((microtime(true) - $startTime) * 1000, 2);

            Log::error(self::LOG_PREFIX . ' Error en extracción OCR', [
                'expense_id' => $expenseId,
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'total_duration_ms' => $totalDuration,
                'stack_trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function importExpenseItemsFromTicketOcr(Expense $expense): int
    {
        $expenseId = $expense->id;

        Log::info(self::LOG_PREFIX . ' Iniciando importación de items desde OCR', [
            'expense_id' => $expenseId,
        ]);

        try {
            $items = $this->extractTicketItems($expense);
            $count = $this->importExpenseItems($expense, $items);

            Log::info(self::LOG_PREFIX . ' Importación desde OCR completada', [
                'expense_id' => $expenseId,
                'imported_count' => $count,
            ]);

            return $count;

        } catch (Exception $e) {
            Log::error(self::LOG_PREFIX . ' Error en importación desde OCR', [
                'expense_id' => $expenseId,
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            throw $e;
        }
    }

    /**
     * @param array<int, array{concept: string, quantity: string, unit_price: string}> $items
     */
    public function importExpenseItems(Expense $expense, array $items): int
    {
        $expenseId = $expense->id;

        Log::info(self::LOG_PREFIX . ' Iniciando importación de items', [
            'expense_id' => $expenseId,
            'items_to_import' => count($items),
        ]);

        return DB::transaction(function () use ($expense, $items, $expenseId): int {
            $expense->refresh();

            if ($expense->items()->exists()) {
                $existingCount = $expense->items()->count();
                Log::warning(self::LOG_PREFIX . ' El gasto ya tiene líneas existentes', [
                    'expense_id' => $expenseId,
                    'existing_items_count' => $existingCount,
                ]);
                throw new RuntimeException('Este gasto ya tiene líneas. Borra las líneas antes de ejecutar OCR.');
            }

            if (empty($items)) {
                Log::warning(self::LOG_PREFIX . ' No hay items para importar', [
                    'expense_id' => $expenseId,
                ]);
                throw new RuntimeException('El OCR no devolvió ninguna línea.');
            }

            $importedCount = 0;
            foreach ($items as $index => $item) {
                Log::debug(self::LOG_PREFIX . ' Creando ExpenseItem', [
                    'expense_id' => $expenseId,
                    'item_index' => $index,
                    'concept' => $item['concept'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                ]);

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

                $importedCount++;
            }

            $expense->forceFill(['pending_review' => true])->save();

            Log::info(self::LOG_PREFIX . ' Items importados correctamente', [
                'expense_id' => $expenseId,
                'imported_count' => $importedCount,
                'pending_review' => true,
            ]);

            return $importedCount;
        });
    }

    /**
     * @return array<int, array{concept: string, quantity: string, unit_price: string}>
     */
    public function parseResponseContent(string $content, $expenseId = null): array
    {
        Log::debug(self::LOG_PREFIX . ' Parseando contenido de respuesta', [
            'expense_id' => $expenseId,
            'content_length' => strlen($content),
        ]);

        $decoded = $this->decodeJsonFromContent($content, $expenseId);

        $items = isset($decoded['items']) ? $decoded['items'] : null;

        if (!is_array($items)) {
            Log::error(self::LOG_PREFIX . ' JSON no contiene clave "items" válida', [
                'expense_id' => $expenseId,
                'decoded_keys' => array_keys($decoded),
                'items_type' => gettype($items),
            ]);
            throw new RuntimeException('El OCR no devolvió un JSON válido con la clave "items".');
        }

        Log::debug(self::LOG_PREFIX . ' Items encontrados en respuesta', [
            'expense_id' => $expenseId,
            'raw_items_count' => count($items),
        ]);

        $normalized = [];
        $skippedCount = 0;
        $skippedReasons = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $skippedCount++;
                $skippedReasons[] = "Item {$index}: no es un array";
                continue;
            }

            $conceptRaw = isset($item['concept']) ? $item['concept'] : '';
            $concept = trim((string) $conceptRaw);
            if (function_exists('mb_substr')) {
                $concept = mb_substr($concept, 0, 255);
            } else {
                $concept = substr($concept, 0, 255);
            }

            $quantityRaw = isset($item['quantity']) ? $item['quantity'] : null;
            $unitPriceRaw = isset($item['unit_price']) ? $item['unit_price'] : null;

            $quantity = $this->parseDecimal($quantityRaw);
            $unitPrice = $this->parseDecimal($unitPriceRaw);

            if (blank($concept)) {
                $skippedCount++;
                $skippedReasons[] = "Item {$index}: concept vacío";
                continue;
            }

            if ($quantity === null) {
                $skippedCount++;
                $skippedReasons[] = "Item {$index}: quantity inválido (" . var_export($quantityRaw, true) . ")";
                continue;
            }

            if ($unitPrice === null) {
                $skippedCount++;
                $skippedReasons[] = "Item {$index}: unit_price inválido (" . var_export($unitPriceRaw, true) . ")";
                continue;
            }

            if ($quantity <= 0) {
                $skippedCount++;
                $skippedReasons[] = "Item {$index}: quantity <= 0 ({$quantity})";
                continue;
            }

            if ($unitPrice < 0) {
                $skippedCount++;
                $skippedReasons[] = "Item {$index}: unit_price < 0 ({$unitPrice})";
                continue;
            }

            $normalized[] = [
                'concept' => $concept,
                'quantity' => $this->formatDecimal($quantity, 3),
                'unit_price' => $this->formatDecimal($unitPrice, 2),
            ];
        }

        if ($skippedCount > 0) {
            Log::warning(self::LOG_PREFIX . ' Algunos items fueron omitidos', [
                'expense_id' => $expenseId,
                'skipped_count' => $skippedCount,
                'skipped_reasons' => $skippedReasons,
            ]);
        }

        if (empty($normalized)) {
            Log::error(self::LOG_PREFIX . ' No se obtuvieron líneas válidas después del parsing', [
                'expense_id' => $expenseId,
                'raw_items_count' => count($items),
                'skipped_count' => $skippedCount,
            ]);
            throw new RuntimeException('El OCR no devolvió ninguna línea válida.');
        }

        Log::info(self::LOG_PREFIX . ' Parsing completado', [
            'expense_id' => $expenseId,
            'valid_items_count' => count($normalized),
            'skipped_count' => $skippedCount,
        ]);

        return $normalized;
    }

    private function prompt(): string
    {
        return 'Analiza la imagen del ticket y extrae todas las líneas de productos o servicios. Devuelve únicamente JSON con items.';
        /*return <<<'PROMPT'
Analiza la imagen del ticket y extrae todas las líneas de productos o servicios.

Devuelve ÚNICAMENTE un objeto JSON válido con este formato exacto (sin texto adicional, sin markdown, sin bloques de código, sin ninguna clave adicional):

{
  "items": [
    {
      "concept": "Nombre del producto o servicio",
      "quantity": 1.0,
      "unit_price": 0.0
    }
  ]
}

Reglas obligatorias:
- "concept": El nombre completo o descripción del producto/servicio (sin incluir precio ni cantidad)
- "quantity": Cantidad numérica del producto (si no aparece, usar 1)
- "unit_price": Precio unitario numérico en formato decimal (si no aparece, usar 0)
- Incluir todas las líneas visibles en el ticket
- No incluir totales, subtotales, impuestos o información de pago
- Usar números decimales con punto (.), no coma
- La respuesta debe ser JSON válido sin formato markdown
- NO envuelvas la respuesta en ninguna clave adicional como "text"

Ejemplo de respuesta:
{
  "items": [
    {"concept": "Pan integral", "quantity": 2, "unit_price": 1.50},
    {"concept": "Leche entera 1L", "quantity": 1, "unit_price": 0.95}
  ]
}
PROMPT;*/
    }

    private function generateUrl(): string
    {
        $base = config('services.ollama.url', 'http://localhost:11434');
        $base = trim((string) $base);

        if ($base === '') {
            $base = 'http://localhost:11434';
        }

        if (!str_starts_with($base, 'http://') && !str_starts_with($base, 'https://')) {
            $base = 'http://' . $base;
        }

        $base = rtrim($base, '/');

        return $base . '/api/generate';
    }

    private function ocrModel(): string
    {
        $model = config('services.ollama.ocr_model', 'glm-ocr');
        $model = trim((string) $model);

        if ($model === '') {
            $model = 'glm-ocr';
        }

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $payload, $expenseId = null): array
    {
        $maxRetries = 2;
        $retryDelay = 1; // segundos

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                return $this->executeCurlRequest($url, $payload, $expenseId);
            } catch (RuntimeException $e) {
                // Solo reintentar en errores transitorios
                $isRetryable = str_contains($e->getMessage(), 'HTTP 500')
                            || str_contains($e->getMessage(), 'GGML_ASSERT')
                            || str_contains($e->getMessage(), 'TIMEOUT')
                            || str_contains($e->getMessage(), 'CONNECTION_REFUSED')
                            || str_contains($e->getMessage(), 'EMPTY_RESPONSE');

                if ($attempt === $maxRetries || !$isRetryable) {
                    throw $e;
                }

                Log::warning(self::LOG_PREFIX . ' Reintentando petición OCR', [
                    'expense_id' => $expenseId,
                    'attempt' => $attempt + 1,
                    'error' => $e->getMessage(),
                ]);

                sleep($retryDelay);
            }
        }

        throw new RuntimeException('Número máximo de reintentos alcanzado');
    }

    /**
     * @return array<string, mixed>
     */
    private function executeCurlRequest(string $url, array $payload, $expenseId = null): array
    {
        $requestStartTime = microtime(true);
    
        // Preparar payload para log (sin la imagen en base64)
        $payloadForLog = $payload;
        $imageLength = 0;
        if (isset($payload['images'][0])) {
            $imageLength = strlen((string) $payload['images'][0]);
        }
        $payloadForLog['images'] = ['[BASE64_IMAGE_OMITTED - ' . $imageLength . ' chars]'];
    
        $modelName = $payload['model'] ?? 'unknown';
        $timeout = (int) config('services.ollama.timeout', 300);
        if ($timeout <= 0) {
            $timeout = 300;
        }
    
        Log::info(self::LOG_PREFIX . ' Enviando petición HTTP a Ollama (curl CLI)', [
            'expense_id' => $expenseId,
            'url' => $url,
            'model' => $modelName,
            'timeout_seconds' => $timeout,
        ]);
    
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new RuntimeException('No se pudo serializar la petición del OCR: ' . json_last_error_msg());
        }
    
        $bodyLength = strlen($body);
        Log::debug(self::LOG_PREFIX . ' Payload serializado', [
            'expense_id' => $expenseId,
            'body_size_bytes' => $bodyLength,
            'body_size_mb' => round($bodyLength / 1024 / 1024, 2),
        ]);
    
        // Comprobar que el binario curl está disponible
        $whichCurl = trim((string) shell_exec('command -v curl 2>/dev/null'));
        if ($whichCurl === '') {
            throw new RuntimeException('curl no está disponible en el sistema. Instala curl o usa la extensión cURL de PHP.');
        }
    
        // Crear fichero temporal con el body (evita problemas de escaping y límites)
        $tmpDir = sys_get_temp_dir();
        $bodyFile = tempnam($tmpDir, 'ollama_body_');
        if ($bodyFile === false) {
            throw new RuntimeException('No se pudo crear fichero temporal para el body de la petición.');
        }
        file_put_contents($bodyFile, $body);
    
        // Construir comando curl (muy similar al que usas en la terminal)
        $curlCmd = [
            $whichCurl,
            '--http1.1',
            '-i',               // incluir cabeceras en la salida
            '-sS',              // silencioso pero mostrar errores en stderr
            '-X', 'POST',
            '-H', 'Content-Type: application/json',
            '-H', 'Expect:',    // evitar 100-continue
            '--data-binary', '@' . $bodyFile,
            '--max-time', (string) $timeout,
            '--connect-timeout', '30',
            '--no-keepalive',
            escapeshellarg($url),
        ];
    
        // Join de forma segura sobre el array (ya escapamos url; body file está con @)
        $cmd = implode(' ', $curlCmd);
    
        // Ejecutar con proc_open para capturar stdout y stderr
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];
    
        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            @unlink($bodyFile);
            throw new RuntimeException('No se pudo ejecutar curl externo.');
        }
    
        // No escribimos nada en stdin
        fclose($pipes[0]);
    
        // Leer salida (stdin cerrado)
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
    
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
    
        $exitCode = proc_close($process);
    
        // Borrar fichero temporal
        @unlink($bodyFile);
    
        $requestDuration = round((microtime(true) - $requestStartTime) * 1000, 2);
    
        Log::debug(self::LOG_PREFIX . ' cURL CLI stderr output', [
            'expense_id' => $expenseId,
            'stderr' => $this->trimForError($stderr),
        ]);
    
        Log::info(self::LOG_PREFIX . ' cURL CLI completed', [
            'expense_id' => $expenseId,
            'exit_code' => $exitCode,
            'duration_ms' => $requestDuration,
            'response_size_bytes' => is_string($stdout) ? strlen($stdout) : 0,
        ]);
    
        // Si curl devolvió un código de error en el sistema
        if ($exitCode !== 0) {
            $msg = sprintf(
                'Error ejecutando curl externo (exit code %d): %s',
                $exitCode,
                $this->trimForError($stderr ?: $stdout)
            );
            throw new RuntimeException($msg);
        }
    
        // stdout incluye cabeceras + body (-i). Extraer último bloque de cabeceras y body.
        $stdoutNormalized = str_replace("\r\n", "\n", $stdout);
        // Separar por doble salto de línea, hay que tomar el último bloque como body (por si hubo redirecciones)
        $parts = preg_split("/\n\n/", $stdoutNormalized);
        if ($parts === false || count($parts) === 0) {
            throw new RuntimeException('Respuesta inválida de curl externo: salida vacía.');
        }
    
        // El body real es el último segmento
        $bodyContent = array_pop($parts);
        // Los headers están en el último segmento restante (si hay varios bloques, el último bloque de cabeceras es el que nos interesa)
        $headersCombined = implode("\n\n", $parts);
    
        // Obtener último HTTP status (por si hubo varios bloque HTTP/... por redirecciones)
        $httpCode = null;
        if (preg_match_all('/HTTP\/[0-9.]+\s+([0-9]{3})/i', $headersCombined, $matches)) {
            $httpMatches = $matches[1];
            if (!empty($httpMatches)) {
                $httpCode = (int) end($httpMatches);
            }
        }
    
        if ($httpCode === null) {
            // Fallback: si no encontramos cabecera, intentar en todo stdout
            if (preg_match_all('/HTTP\/[0-9.]+\s+([0-9]{3})/i', $stdoutNormalized, $matchesAll)) {
                $codesAll = $matchesAll[1];
                if (!empty($codesAll)) {
                    $httpCode = (int) end($codesAll);
                }
            }
        }
    
        if ($httpCode === null) {
            // No se pudo determinar código HTTP
            throw new RuntimeException('No se pudo determinar el código HTTP de la respuesta del servidor.');
        }
    
        Log::debug(self::LOG_PREFIX . ' cURL CLI parsed response', [
            'expense_id' => $expenseId,
            'http_code' => $httpCode,
            'response_preview' => $this->trimForError($bodyContent),
            'headers_preview' => $this->trimForError($headersCombined),
        ]);
    
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(sprintf(
                'OCR falló con HTTP %d: %s',
                $httpCode,
                $this->trimForError($bodyContent ?: $stderr)
            ));
        }
    
        // Decodificar JSON del body
        $decoded = json_decode($bodyContent, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(
                'El OCR devolvió JSON inválido: ' . $this->trimForError($bodyContent)
            );
        }
    
        Log::info(self::LOG_PREFIX . ' Respuesta del OCR recibida correctamente (CLI curl)', [
            'expense_id' => $expenseId,
            'response_keys' => array_keys($decoded),
            'has_response_field' => isset($decoded['response']),
            'response_length' => isset($decoded['response']) ? strlen((string)$decoded['response']) : 0,
            'model_used' => $decoded['model'] ?? 'unknown',
        ]);
    
        return $decoded;
    }

    /**
     * Obtiene mensaje descriptivo para errores de cURL
     */
    private function getCurlErrorMessage(int $errno, string $error): string
    {
        $descriptions = [
            CURLE_OPERATION_TIMEDOUT => 'TIMEOUT - La operación excedió el tiempo límite. El servidor Ollama puede estar sobrecargado o el modelo puede requerir más tiempo para procesar la imagen.',
            CURLE_COULDNT_CONNECT => 'CONNECTION_REFUSED - No se pudo conectar al servidor Ollama. Verificar que el servicio esté ejecutándose.',
            CURLE_COULDNT_RESOLVE_HOST => 'DNS_ERROR - No se pudo resolver el nombre del host. Verificar la configuración de red.',
            CURLE_GOT_NOTHING => 'EMPTY_RESPONSE - El servidor cerró la conexión sin enviar datos.',
            CURLE_RECV_ERROR => 'RECEIVE_ERROR - Error al recibir datos del servidor.',
            CURLE_SEND_ERROR => 'SEND_ERROR - Error al enviar datos al servidor.',
            CURLE_SSL_CONNECT_ERROR => 'SSL_ERROR - Error en la conexión SSL/TLS.',
            CURLE_TOO_MANY_REDIRECTS => 'TOO_MANY_REDIRECTS - Demasiadas redirecciones.',
        ];

        $description = isset($descriptions[$errno]) ? $descriptions[$errno] : "ERROR_CODE_{$errno}";

        return "{$description} ({$error})";
    }

    private function trimForError(string $value): string
    {
        $value = trim($value);

        if (function_exists('mb_strlen')) {
            $len = mb_strlen($value);
        } else {
            $len = strlen($value);
        }

        if ($len <= 500) {
            return $value;
        }

        if (function_exists('mb_substr')) {
            $prefix = mb_substr($value, 0, 500);
        } else {
            $prefix = substr($value, 0, 500);
        }

        return $prefix . '…';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonFromContent(string $content, $expenseId = null): array
    {
        $content = trim($content);

        Log::debug(self::LOG_PREFIX . ' Intentando decodificar JSON', [
            'expense_id' => $expenseId,
            'content_length' => strlen($content),
            'content_preview' => $this->trimForError($content),
        ]);

        // Paso 0: Manejar el wrapper {"text": "..."} de glm-ocr
        $wrapperDecoded = json_decode($content, true);
        if (is_array($wrapperDecoded) && isset($wrapperDecoded['text']) && is_string($wrapperDecoded['text'])) {
            Log::debug(self::LOG_PREFIX . ' Detectado wrapper {"text": "..."}, extrayendo contenido', [
                'expense_id' => $expenseId,
            ]);
            $content = trim($wrapperDecoded['text']);
        }

        // Intento 1: JSON directo
        $direct = json_decode($content, true);
        if (is_array($direct)) {
            Log::debug(self::LOG_PREFIX . ' JSON decodificado directamente', [
                'expense_id' => $expenseId,
                'method' => 'direct',
            ]);
            return $direct;
        }

        Log::debug(self::LOG_PREFIX . ' Decodificación directa falló, intentando strip fenced', [
            'expense_id' => $expenseId,
            'json_error' => json_last_error_msg(),
        ]);

        // Intento 2: Quitar bloques de código markdown
        $stripped = $this->stripFencedJson($content);
        $fenced = json_decode($stripped, true);
        if (is_array($fenced)) {
            Log::debug(self::LOG_PREFIX . ' JSON decodificado después de strip fenced', [
                'expense_id' => $expenseId,
                'method' => 'strip_fenced',
            ]);
            return $fenced;
        }

        Log::debug(self::LOG_PREFIX . ' Strip fenced falló, intentando extraer objeto JSON', [
            'expense_id' => $expenseId,
            'json_error' => json_last_error_msg(),
        ]);

        // Intento 3: Extraer primer objeto JSON
        $extracted = $this->extractFirstJsonObject($content);
        if ($extracted !== null) {
            $decoded = json_decode($extracted, true);
            if (is_array($decoded)) {
                Log::debug(self::LOG_PREFIX . ' JSON extraído y decodificado', [
                    'expense_id' => $expenseId,
                    'method' => 'extract_first_object',
                    'extracted_length' => strlen($extracted),
                ]);
                return $decoded;
            }
        }

        Log::error(self::LOG_PREFIX . ' No se pudo parsear el JSON por ningún método', [
            'expense_id' => $expenseId,
            'content_preview' => $this->trimForError($content),
            'json_error' => json_last_error_msg(),
        ]);

        throw new RuntimeException('No se pudo parsear el JSON del OCR.');
    }

    private function stripFencedJson(string $content): string
    {
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/sU', $content, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $content, $matches)) {
            return trim($matches[1]);
        }

        return $content;
    }

    private function extractFirstJsonObject(string $content): ?string
    {
        $content = trim($content);
        $length = strlen($content);
        $start = strpos($content, '{');

        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;

        for ($i = $start; $i < $length; $i++) {
            $ch = $content[$i];

            if ($inString) {
                if ($escape) {
                    $escape = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escape = true;
                    continue;
                }
                if ($ch === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($ch === '"') {
                $inString = true;
                continue;
            }

            if ($ch === '{') {
                $depth++;
                continue;
            }

            if ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private function parseDecimal($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $value = str_replace([' ', "\t", "\n", "\r"], '', $value);
        $value = str_replace(',', '.', $value);

        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function formatDecimal(float $value, int $decimals): string
    {
        return number_format($value, $decimals, '.', '');
    }
}
