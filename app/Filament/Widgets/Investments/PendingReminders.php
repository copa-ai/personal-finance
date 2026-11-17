<?php

namespace App\Filament\Widgets\Investments;

use App\Models\Reminder;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class PendingReminders extends TableWidget
{
    protected static ?string $heading = 'Pendiente esta semana';

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Reminder::query()
                ->pending()
                ->where('due_on', '<=', now(config('app.display_timezone'))->addDays(7)->toDateString())
                ->orderBy('due_on'))
            ->columns([
                TextColumn::make('due_on')->label('Fecha')->date('d/m/Y')
                    ->color(fn ($state): string => $state->isPast() ? 'danger' : 'gray'),
                TextColumn::make('kind')->label('Tipo')->badge(),
                TextColumn::make('title')->label('Recordatorio')->description(fn (Reminder $record): ?string => $record->body)->wrap(),
            ])
            ->recordUrl(fn (): string => \App\Filament\Resources\Reminders\ReminderResource::getUrl('index'))
            ->paginated([5])
            ->emptyStateHeading('Nada pendiente')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
