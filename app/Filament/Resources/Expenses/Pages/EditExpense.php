<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Services\OcrService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditExpense extends EditRecord
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
                ->action(function (Expense $record, OcrService $ocrService): void {
                    if ($record->items()->exists()) {
                        Notification::make()
                            ->warning()
                            ->title('OCR bloqueado')
                            ->body('Este gasto ya tiene líneas. Borra las líneas antes de ejecutar OCR.')
                            ->send();

                        return;
                    }

                    try {
                        $count = $ocrService->importExpenseItemsFromTicketOcr($record);

                        Notification::make()
                            ->success()
                            ->title('OCR completado')
                            ->body("Se crearon {$count} líneas y el gasto quedó pendiente de revisar.")
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('Error de OCR')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),
            DeleteAction::make(),
        ];
    }
}
