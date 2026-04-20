<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Enums\OcrJobStatus;
use App\Jobs\ProcessOcrJob;
use App\Models\Expense;
use App\Models\OcrJob;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewExpense extends ViewRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ocrTicket')
                ->label('OCR del ticket')
                ->icon('heroicon-o-document-magnifying-glass')
                ->visible(fn (Expense $record): bool => filled($record->ticket_photo_hash))
                ->color(fn (Expense $record): string => $record->items()->exists() ? 'gray' : 'primary')
                ->action(function (Expense $record): void {
                    if ($record->items()->exists()) {
                        Notification::make()
                            ->warning()
                            ->title('OCR bloqueado')
                            ->body('Este gasto ya tiene líneas. Borra las líneas antes de ejecutar OCR.')
                            ->send();

                        return;
                    }

                    $alreadyPending = OcrJob::query()
                        ->where('expense_id', $record->id)
                        ->where('status', OcrJobStatus::PENDING)
                        ->exists();

                    if ($alreadyPending) {
                        Notification::make()
                            ->warning()
                            ->title('OCR ya en cola')
                            ->body('Ya existe un proceso OCR pendiente para este gasto.')
                            ->send();

                        return;
                    }

                    $ocrJob = OcrJob::query()->create([
                        'expense_id' => $record->id,
                        'user_id' => auth()->id(),
                        'status' => OcrJobStatus::PENDING,
                        'ticket_path' => $record->ticket_photo_hash,
                    ]);

                    ProcessOcrJob::dispatch($ocrJob->id);

                    Notification::make()
                        ->success()
                        ->title('OCR en cola')
                        ->body('Te avisaremos cuando finalice el procesamiento.')
                        ->send();
                }),
            EditAction::make(),
        ];
    }
}
