<?php

namespace App\Services;

use App\Enums\ExpenseItemType;
use App\Enums\ExpenseStatus;
use App\Enums\Recurrence;
use App\Models\Establishment;
use App\Models\Expense;
use App\Models\ExpenseItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RevolutImportService
{
    private const LOG_PREFIX = '[RevolutImportService]';

    /**
     * Índice en memoria para detectar duplicados dentro de la misma importación y base de datos.
     * @var array<string, array<string, bool>>
     */
    private array $duplicateIndex = [];

    /**
     * Registro de fechas ya consultadas en la base de datos para evitar consultas repetitivas (N+1).
     * @var array<string, bool>
     */
    private array $loadedDates = [];

    /**
     * @return array{
     * imported_count: int,
     * skipped_duplicate_count: int,
     * skipped_duplicates: array<int, array{
     * line: int,
     * date: string,
     * description: string,
     * total: string
     * }>,
     * duplicates_report_path: string|null
     * }
     */
    public function importCsv(string $csvPath, bool $leaveSubexpensesEmpty = false, bool $allowDuplicates = false): array
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($csvPath)) {
            throw new RuntimeException("No se encontró el CSV de importación en {$csvPath}.");
        }

        $rows = $this->readRows($disk->path($csvPath));

        if ($rows === []) {
            throw new RuntimeException('El CSV no contiene filas para importar.');
        }

        Log::info(self::LOG_PREFIX . ' Iniciando importación de Revolut', [
            'csv_path' => $csvPath,
            'rows_found' => count($rows),
            'leave_subexpenses_empty' => $leaveSubexpensesEmpty,
            'allow_duplicates' => $allowDuplicates,
        ]);

        $summary = [
            'imported_count' => 0,
            'skipped_duplicate_count' => 0,
            'skipped_duplicates' => [],
            'duplicates_report_path' => null,
        ];

        DB::transaction(function () use ($rows, $csvPath, $leaveSubexpensesEmpty, $allowDuplicates, &$summary): void {
            foreach ($rows as $row) {
                $type = $this->normalizeText($row['Type'] ?? null);

                // Solo procesamos pagos con tarjeta y transferencias
                if ($type !== 'Card Payment' && $type !== 'Transfer') {
                    continue;
                }

                $signedTotal = $this->normalizeMoney($row['Amount'] ?? null);

                // Si es Transferencia, solo agregamos los Gastos (transferencias negativas)
                if ($type === 'Transfer' && $signedTotal >= 0) {
                    continue;
                }

                $description = $this->normalizeDescription($row['Description'] ?? null);
                $date = $this->parseDate($row['Started Date'] ?? null);
                
                // Convertimos a valor absoluto para registrar el gasto
                $total = abs($signedTotal);
                $formattedTotal = number_format($total, 2, '.', '');

                if (! $allowDuplicates && $this->isDuplicateExpense($date, $total)) {
                    $summary['skipped_duplicate_count']++;
                    $summary['skipped_duplicates'][] = [
                        'line' => $row['__line'],
                        'date' => $date->toDateString(),
                        'description' => $description,
                        'total' => $formattedTotal,
                    ];

                    continue;
                }

                $establishment = Establishment::query()->firstOrCreate([
                    'name' => $description,
                ]);

                $expense = Expense::query()->create([
                    'establishment_id' => $establishment->id,
                    'date' => $date,
                    'total' => $formattedTotal,
                    'import_csv_path' => $csvPath,
                    'status' => ExpenseStatus::PAID,
                    'pending_review' => false,
                    'created_at' => now(),
                ]);

                if (! $leaveSubexpensesEmpty) {
                    ExpenseItem::query()->create([
                        'expense_id' => $expense->id,
                        'category_id' => null,
                        'concept' => $description,
                        'quantity' => 1,
                        'unit_price' => $formattedTotal,
                        'tags' => [],
                        'item_type' => ExpenseItemType::VARIABLE_IRREGULAR,
                        'is_consumable' => true,
                        'recurrence' => Recurrence::NONE,
                        'actual_start_date' => $date->toDateString(),
                    ]);
                }

                $summary['imported_count']++;

                if (! $allowDuplicates) {
                    $this->markDuplicateExpense($date, $total);
                }
            }
        });

        // Almacenamiento de duplicados en un JSON si existen elementos omitidos
        if ($summary['skipped_duplicate_count'] > 0) {
            $summary['duplicates_report_path'] = $this->saveDuplicatesReport($disk, $csvPath, $summary['skipped_duplicates']);
        }

        Log::info(self::LOG_PREFIX . ' Importación de Revolut completada', [
            'csv_path' => $csvPath,
            'imported_count' => $summary['imported_count'],
            'skipped_duplicate_count' => $summary['skipped_duplicate_count'],
            'duplicates_report_path' => $summary['duplicates_report_path'],
        ]);

        return $summary;
    }

    /**
     * Guarda el array de duplicados en un archivo JSON dentro del storage local.
     *
     * @param \Illuminate\Contracts\Filesystem\Filesystem $disk
     * @param array<int, array<string, mixed>> $duplicates
     */
    private function saveDuplicatesReport($disk, string $csvPath, array $duplicates): string
    {
        // Extraemos el nombre base del archivo original (ej: 'revolut_2026_05.csv' -> 'revolut_2026_05')
        $baseName = pathinfo($csvPath, PATHINFO_FILENAME);
        
        // Generamos un nombre único usando un timestamp
        $timestamp = now()->format('Ymd_His');
        $reportPath = "revolut/duplicates/{$baseName}_duplicates_{$timestamp}.json";

        $jsonData = json_encode([
            'source_csv' => $csvPath,
            'generated_at' => now()->toIso8601String(),
            'total_skipped' => count($duplicates),
            'items' => $duplicates
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $disk->put($reportPath, $jsonData);

        return $disk->path($reportPath); // Retorna la ruta absoluta para el summary/logs
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readRows(string $absolutePath): array
    {
        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("No se pudo abrir el CSV: {$absolutePath}.");
        }

        try {
            $headers = fgetcsv($handle);

            if ($headers === false) {
                return [];
            }

            $headers = array_map(fn ($header): string => $this->normalizeHeader($header), $headers);

            // Columns: Type,Product,Started Date,Completed Date,Description,Amount,Fee,Currency,State,Balance
            $requiredHeaders = [
                'Type',
                'Started Date',
                'Description',
                'Amount',
            ];

            foreach ($requiredHeaders as $requiredHeader) {
                if (! in_array($requiredHeader, $headers, true)) {
                    throw new RuntimeException("El CSV no contiene la columna requerida {$requiredHeader}.");
                }
            }

            $rows = [];
            $lineNumber = 1;

            while (($rawRow = fgetcsv($handle)) !== false) {
                $lineNumber++;

                if ($rawRow === [null] || $rawRow === []) {
                    continue;
                }

                $row = [];

                foreach ($headers as $index => $header) {
                    $row[$header] = $rawRow[$index] ?? null;
                }

                $row['__line'] = $lineNumber;
                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function normalizeHeader(mixed $header): string
    {
        $value = trim((string) $header);

        return ltrim($value, "\xEF\xBB\xBF");
    }

    private function normalizeText(mixed $value): string
    {
        return trim((string) $value);
    }

    private function normalizeDescription(mixed $value): string
    {
        $description = preg_replace('/\s+/u', ' ', trim((string) $value));

        if ($description === '' || $description === null) {
            throw new RuntimeException('La descripción del movimiento no puede estar vacía.');
        }

        return $description;
    }

    private function normalizeMoney(mixed $value): float
    {
        $normalized = trim((string) $value);

        if ($normalized === '') {
            throw new RuntimeException('El importe del movimiento no puede estar vacío.');
        }

        $normalized = str_replace(['€', ' '], '', $normalized);
        $normalized = str_replace(',', '.', $normalized);

        if (! is_numeric($normalized)) {
            throw new RuntimeException("Importe inválido: {$value}");
        }

        return (float) $normalized;
    }

    private function parseDate(mixed $value): Carbon
    {
        $rawDate = trim((string) $value);

        if ($rawDate === '') {
            throw new RuntimeException('La fecha de inicio del movimiento no puede estar vacía.');
        }

        $date = Carbon::createFromFormat('Y-m-d H:i:s', $rawDate);

        if ($date === false) {
            throw new RuntimeException("Fecha inválida: {$rawDate}");
        }

        return $date;
    }

    private function isDuplicateExpense(Carbon $date, float $total): bool
    {
        $formattedTotal = number_format($total, 2, '.', '');
        
        // Ampliamos el rango a fecha_csv +/- 1 día
        $targetDates = [
            $date->copy()->subDay()->toDateString(),
            $date->toDateString(),
            $date->copy()->addDay()->toDateString(),
        ];

        foreach ($targetDates as $targetDate) {
            // Lazy load para cada fecha en el rango para evitar consultas constantes
            if (! isset($this->loadedDates[$targetDate])) {
                $this->loadedDates[$targetDate] = true;
                $this->duplicateIndex[$targetDate] = $this->duplicateIndex[$targetDate] ?? [];

                Expense::query()
                    ->whereDate('date', $targetDate)
                    ->whereNull('import_csv_path') // Excluimos gastos que ya posean un import_csv_path
                    ->whereNotNull('total')
                    ->get(['total'])
                    ->each(function (Expense $expense) use ($targetDate): void {
                        $totalStr = number_format((float) $expense->total, 2, '.', '');
                        $this->duplicateIndex[$targetDate][$totalStr] = true;
                    });
            }

            // Comprobación exacta con dos decimales en memoria
            if (isset($this->duplicateIndex[$targetDate][$formattedTotal])) {
                return true;
            }
        }

        return false;
    }

    private function markDuplicateExpense(Carbon $date, float $total): void
    {
        $formattedTotal = number_format($total, 2, '.', '');
        $dateStr = $date->toDateString();
        
        if (! isset($this->duplicateIndex[$dateStr])) {
            $this->duplicateIndex[$dateStr] = [];
        }
        
        $this->duplicateIndex[$dateStr][$formattedTotal] = true;
    }
}