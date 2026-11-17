<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ReminderStatus: string implements HasLabel
{
    case PENDING = 'PENDING';
    case DONE = 'DONE';
    case SKIPPED = 'SKIPPED';

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'Pendiente',
            self::DONE => 'Hecho',
            self::SKIPPED => 'Saltado',
        };
    }
}
