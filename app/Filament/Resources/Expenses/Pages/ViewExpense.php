<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Services\Acciones\LanzaOcr;
use App\Models\Expense;
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
                ->color(fn (Expense $record): string => $record->isOcrAvailable() ? 'primary' : 'gray')
                ->action(function (Expense $record): void {
                    // Las reglas (foto, sin lineas, sin OCR pendiente) y el encolado viven en LanzaOcr: las comparte el chat.
                    $resultado = app(LanzaOcr::class)->lanzar($record, auth()->id());

                    $notificacion = Notification::make();
                    if ($resultado['ok']) {
                        $notificacion->success()->title('OCR en cola')->body('Te avisaremos cuando finalice el procesamiento.');
                    } else {
                        $notificacion->warning()->title('OCR no lanzado')->body($resultado['mensaje']);
                    }
                    $notificacion->send();
                }),
            EditAction::make(),
        ];
    }
}
