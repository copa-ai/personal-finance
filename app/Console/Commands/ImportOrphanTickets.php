<?php

namespace App\Console\Commands;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Services\OcrService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportOrphanTickets extends Command
{
    protected $signature = 'expenses:import-orphan-tickets
        {--dry-run : Solo muestra qué tickets se importarían}
        {--limit= : Limita el número de tickets a procesar}';

    protected $description = 'Da de alta los tickets huérfanos de storage/private/tickets como expenses y ejecuta OCR sobre cada uno.';

    public function handle(OcrService $ocrService): int
    {
        $disk = Storage::disk('local');
        $tickets = collect($disk->allFiles('tickets'))
            ->filter(fn (string $path): bool => $disk->exists($path))
            ->values();

        if ($tickets->isEmpty()) {
            $this->info('No se encontraron tickets en storage/private/tickets.');

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $tickets = $tickets->take($limit)->values();
        }

        $existingHashes = Expense::query()
            ->whereNotNull('ticket_photo_hash')
            ->pluck('ticket_photo_hash')
            ->map(fn (?string $hash): string => $this->normalizeTicketPath($hash))
            ->flip();

        $dryRun = (bool) $this->option('dry-run');
        $processed = 0;
        $created = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($tickets as $ticketPath) {
            $normalizedTicketPath = $this->normalizeTicketPath($ticketPath);

            if ($existingHashes->has($normalizedTicketPath)) {
                $skipped++;
                $this->line("Saltado (ya existe en un expense): {$ticketPath}");
                continue;
            }

            $processed++;

            if ($dryRun) {
                $this->line("Se importaría: {$ticketPath}");
                continue;
            }

            try {
                $expense = DB::transaction(function () use ($ticketPath): Expense {
                    return Expense::query()->create([
                        'establishment_id' => null,
                        'date' => Carbon::now(),
                        'total' => null,
                        'status' => ExpenseStatus::PAID,
                        'ticket_photo_hash' => $this->normalizeTicketPath($ticketPath),
                        'pending_review' => true,
                        'created_at' => Carbon::now(),
                    ]);
                });

                $created++;
                $this->info("Expense creado para {$ticketPath} ({$expense->id}). Ejecutando OCR...");

                $itemsImported = $ocrService->importExpenseItemsFromTicketOcr($expense);

                $this->info("OCR completado para {$ticketPath}: {$itemsImported} líneas importadas.");
            } catch (Throwable $e) {
                $failed++;
                $this->error("Error procesando {$ticketPath}: {$e->getMessage()}");
                report($e);
            }
        }

        $this->newLine();
        $this->info('Resumen:');
        $this->line("  Encontrados: {$tickets->count()}");
        $this->line("  Procesados: {$processed}");
        $this->line("  Creados: {$created}");
        $this->line("  Saltados: {$skipped}");
        $this->line("  Fallidos: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function normalizeTicketPath(?string $path): string
    {
        $normalized = trim((string) $path);
        $normalized = ltrim($normalized, '/');

        return $normalized;
    }
}
