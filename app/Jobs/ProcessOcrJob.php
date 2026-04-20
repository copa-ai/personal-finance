<?php

namespace App\Jobs;

use App\Enums\OcrJobStatus;
use App\Models\OcrJob;
use App\Services\OcrService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessOcrJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public string $ocrJobId)
    {
        \Log::info($ocrJobId);
    }

    public function handle(OcrService $ocrService): void
    {
        \Log::info("1");
        $ocrJob = OcrJob::query()
            ->with(['expense', 'user'])
            ->findOrFail($this->ocrJobId);

        if ($ocrJob->status !== OcrJobStatus::PENDING) {
            return;
        }

        \Log::info("2");
        $ocrJob->forceFill([
            'started_at' => now(),
            'model' => $ocrService->getOcrModel(),
        ])->save();

        try {
            $expense = $ocrJob->expense;

            \Log::info("3");

            if (!$expense) {
                throw new \RuntimeException('El gasto asociado al OCR ya no existe.');
            }

            if ($expense->items()->exists()) {
                throw new \RuntimeException('Este gasto ya tiene líneas. Borra las líneas antes de ejecutar OCR.');
            }

            \Log::info("4");
            \Log::info(json_encode($expense));
            $count = $ocrService->importExpenseItemsFromTicketOcr($expense);

            $ocrJob->forceFill([
                'status' => OcrJobStatus::COMPLETED,
                'finished_at' => now(),
                'items_imported' => $count,
                'error_message' => null,
                'error_trace' => null,
            ])->save();

            $this->notifySuccess($ocrJob, $count);
        } catch (Throwable $e) {
            $ocrJob->forceFill([
                'status' => OcrJobStatus::FAILED,
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString(),
            ])->save();

            $this->notifyFailure($ocrJob, $e);

            throw $e;
        }
    }

    private function notifySuccess(OcrJob $ocrJob, int $count): void
    {
        if (!$ocrJob->user) {
            return;
        }

        Notification::make()
            ->title('OCR completado')
            ->body("Se crearon {$count} líneas y el gasto quedó pendiente de revisar.")
            ->success()
            ->sendToDatabase($ocrJob->user);
    }

    private function notifyFailure(OcrJob $ocrJob, Throwable $e): void
    {
        if (!$ocrJob->user) {
            return;
        }

        Notification::make()
            ->title('Error de OCR')
            ->body($e->getMessage())
            ->danger()
            ->sendToDatabase($ocrJob->user);
    }
}
