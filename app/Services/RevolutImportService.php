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
     * @var array<string, array<int, bool>>
     */
    private array $duplicateIndex = [];

    /**
     * @return array{
     *     imported_count: int,
     *     skipped_duplicate_count: int,
     *     skipped_duplicates: array<int, array{
     *         line: int,
     *         date: string,
     *         description: string,
     *         total: string,
     *         rounded_total: int
     *     }>
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
        ];

        DB::transaction(function () use ($rows, $csvPath, $leaveSubexpensesEmpty, $allowDuplicates, &$summary): void {
            foreach ($rows as $row) {
                if ($this->normalizeText($row['Tipo'] ?? null) !== 'Pago con tarjeta') {
                    continue;
                }

                $description = $this->normalizeDescription($row['Descripción'] ?? null);
                $date = $this->parseDate($row['Fecha de inicio'] ?? null);
                $total = $this->normalizeMoney($row['Importe'] ?? null);
                $roundedTotal = (int) round($total);

                if (! $allowDuplicates && $this->isDuplicateExpense($date, $roundedTotal)) {
                    $summary['skipped_duplicate_count']++;
                    $summary['skipped_duplicates'][] = [
                        'line' => $row['__line'],
                        'date' => $date->toDateString(),
                        'description' => $description,
                        'total' => number_format($total, 2, '.', ''),
                        'rounded_total' => $roundedTotal,
                    ];

                    continue;
                }

                $establishment = Establishment::query()->firstOrCreate([
                    'name' => $description,
                ]);

                $expense = Expense::query()->create([
                    'establishment_id' => $establishment->id,
                    'date' => $date,
                    'total' => number_format($total, 2, '.', ''),
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
                        'unit_price' => number_format($total, 2, '.', ''),
                        'tags' => [],
                        'item_type' => ExpenseItemType::VARIABLE_IRREGULAR,
                        'is_consumable' => true,
                        'recurrence' => Recurrence::NONE,
                        'actual_start_date' => $date->toDateString(),
                    ]);
                }

                $summary['imported_count']++;

                if (! $allowDuplicates) {
                    $this->markDuplicateExpense($date, $roundedTotal);
                }
            }
        });

        Log::info(self::LOG_PREFIX . ' Importación de Revolut completada', [
            'csv_path' => $csvPath,
            'imported_count' => $summary['imported_count'],
            'skipped_duplicate_count' => $summary['skipped_duplicate_count'],
        ]);

        return $summary;
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

            $requiredHeaders = [
                'Tipo',
                'Fecha de inicio',
                'Descripción',
                'Importe',
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

        return abs((float) $normalized);
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

    private function isDuplicateExpense(Carbon $date, int $roundedTotal): bool
    {
        $dayKey = $date->toDateString();

        if (! array_key_exists($dayKey, $this->duplicateIndex)) {
            $this->duplicateIndex[$dayKey] = [];

            Expense::query()
                ->whereDate('date', $dayKey)
                ->whereNotNull('total')
                ->get(['total'])
                ->each(function (Expense $expense) use ($dayKey): void {
                    $this->duplicateIndex[$dayKey][(int) round((float) $expense->total)] = true;
                });
        }

        return isset($this->duplicateIndex[$dayKey][$roundedTotal]);
    }

    private function markDuplicateExpense(Carbon $date, int $roundedTotal): void
    {
        $this->duplicateIndex[$date->toDateString()][$roundedTotal] = true;
    }
}
