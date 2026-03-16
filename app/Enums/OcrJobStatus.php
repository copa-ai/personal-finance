<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OcrJobStatus: string implements HasLabel
{
    case PENDING = 'PENDING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'Pendiente',
            self::COMPLETED => 'Realizado',
            self::FAILED => 'Fallido',
        };
    }
}
