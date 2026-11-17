<?php

namespace App\Filament\Resources\Reminders\Pages;

use App\Enums\ReminderKind;
use App\Enums\ReminderStatus;
use App\Filament\Resources\Reminders\ReminderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReminders extends ListRecords
{
    protected static string $resource = ReminderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nuevo recordatorio')
                ->mutateDataUsing(fn (array $data): array => [
                    ...$data,
                    'kind' => ReminderKind::CUSTOM,
                    'status' => ReminderStatus::PENDING,
                ]),
        ];
    }
}
