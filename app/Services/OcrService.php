<?php

namespace App\Services;

use App\Enums\ExpenseItemType;
use App\Enums\Recurrence;
use App\Models\Expense;
use App\Models\ExpenseItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class OcrService
{
    /**
     * @return array<int, array{concept: string, quantity: string, unit_price: string}>
     */
    public function extractTicketItems(Expense $expense): array
    {
        $ticketPath = $expense->ticket_photo_hash;

        if (blank($ticketPath)) {
            throw new RuntimeException('El gasto no tiene foto de ticket.');
        }

        if (! Storage::disk('local')->exists($ticketPath)) {
            throw new RuntimeException('No se encontró el fichero del ticket en storage.');
        }

        $absolutePath = Storage::disk('local')->path($ticketPath);

        $contents = @file_get_contents($absolutePath);

        if ($contents === false) {
            throw new RuntimeException('No se pudo leer el fichero del ticket.');
        }

        $mimeType = $this->detectMimeType($absolutePath) ?? 'image/png';
        $dataUrl = sprintf('data:%s;base64,%s', $mimeType, base64_encode($contents));

        $payload = [
            'model' => $this->ocrModel(),
            'temperature' => 0,
            'stream' => false,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $this->prompt(),
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => $dataUrl,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson($this->chatCompletionsUrl(), $payload);

        $content = data_get($response, 'choices.0.message.content');

        if (! is_string($content) || blank($content)) {
            throw new RuntimeException('La respuesta del OCR no contiene contenido.');
        }

        return $this->parseResponseContent($content);
    }

    public function importExpenseItemsFromTicketOcr(Expense $expense): int
    {
        $items = $this->extractTicketItems($expense);

        return $this->importExpenseItems($expense, $items);
    }

    /**
     * @param  array<int, array{concept: string, quantity: string, unit_price: string}>  $items
     */
    public function importExpenseItems(Expense $expense, array $items): int
    {
        return DB::transaction(function () use ($expense, $items): int {
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

            return count($items);
        });
    }

    /**
     * @return array<int, array{concept: string, quantity: string, unit_price: string}>
     */
    public function parseResponseContent(string $content): array
    {
        $decoded = $this->decodeJsonFromContent($content);

        $items = $decoded['items'] ?? null;

        if (! is_array($items)) {
            throw new RuntimeException('El OCR no devolvió un JSON válido con la clave "items".');
        }

        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $concept = isset($item['concept']) ? trim((string) $item['concept']) : '';
            $concept = function_exists('mb_substr')
                ? mb_substr($concept, 0, 255)
                : substr($concept, 0, 255);

            $quantity = $this->parseDecimal($item['quantity'] ?? null);
            $unitPrice = $this->parseDecimal($item['unit_price'] ?? null);

            if (blank($concept) || ($quantity === null) || ($unitPrice === null)) {
                continue;
            }

            if ($quantity <= 0) {
                continue;
            }

            if ($unitPrice < 0) {
                continue;
            }

            $normalized[] = [
                'concept' => $concept,
                'quantity' => $this->formatDecimal($quantity, 3),
                'unit_price' => $this->formatDecimal($unitPrice, 2),
            ];
        }

        if (empty($normalized)) {
            throw new RuntimeException('El OCR no devolvió ninguna línea válida.');
        }

        return $normalized;
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
Extrae las líneas de productos/servicios del ticket y devuélvelas SOLO como JSON válido (sin texto adicional, sin markdown).

Formato exacto:
{
  "items": [
    { "concept": "string", "quantity": 1, "unit_price": 0.0 }
  ]
}

Reglas:
- "concept" debe ser el nombre/descripción de la línea (sin el precio).
- "quantity" debe ser numérico (usa 1 si no aparece).
- "unit_price" debe ser el precio unitario numérico (usa 0 si no aparece).
PROMPT;
    }

    private function chatCompletionsUrl(): string
    {
        $base = trim((string) config('services.ollama.url', 'host.docker.internal:11434'));

        if (! str_starts_with($base, 'http://') && ! str_starts_with($base, 'https://')) {
            $base = 'http://' . $base;
        }

        $base = rtrim($base, '/');

        return $base . '/v1/chat/completions';
    }

    private function ocrModel(): string
    {
        $model = trim((string) config('services.ollama.ocr_model', 'glm-ocr'));

        return filled($model) ? $model : 'glm-ocr';
    }

    /**
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            throw new RuntimeException('No se pudo serializar la petición del OCR.');
        }

        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('No se pudo inicializar cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 120,
        ]);

        $responseBody = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($responseBody === false) {
            throw new RuntimeException('Error cURL ejecutando OCR: ' . ($curlError ?: 'desconocido'));
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(sprintf('OCR falló con HTTP %d: %s', $httpCode, $this->trimForError($responseBody)));
        }

        $decoded = json_decode($responseBody, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('El OCR devolvió JSON inválido: ' . $this->trimForError($responseBody));
        }

        return $decoded;
    }

    private function trimForError(string $value): string
    {
        $value = trim($value);

        $len = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

        if ($len <= 500) {
            return $value;
        }

        $prefix = function_exists('mb_substr') ? mb_substr($value, 0, 500) : substr($value, 0, 500);

        return $prefix . '…';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonFromContent(string $content): array
    {
        $content = trim($content);

        $direct = json_decode($content, true);
        if (is_array($direct)) {
            return $direct;
        }

        $content = $this->stripFencedJson($content);

        $fenced = json_decode($content, true);
        if (is_array($fenced)) {
            return $fenced;
        }

        $extracted = $this->extractFirstJsonObject($content);

        if ($extracted !== null) {
            $decoded = json_decode($extracted, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new RuntimeException('No se pudo parsear el JSON del OCR.');
    }

    private function stripFencedJson(string $content): string
    {
        if (preg_match('/```(?:json)?\\s*(\\{.*\\})\\s*```/sU', $content, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/^```(?:json)?\\s*(.*?)\\s*```$/s', $content, $matches)) {
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

    private function parseDecimal(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $value = str_replace([' ', "\t", "\n", "\r"], '', $value);
        $value = str_replace(',', '.', $value);

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function formatDecimal(float $value, int $decimals): string
    {
        return number_format($value, $decimals, '.', '');
    }

    private function detectMimeType(string $path): ?string
    {
        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($path);

            return is_string($mime) && filled($mime) ? $mime : null;
        }

        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($path);

            return is_string($mime) && filled($mime) ? $mime : null;
        }

        return null;
    }
}
