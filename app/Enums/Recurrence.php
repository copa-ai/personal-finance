<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Recurrence: string implements HasLabel
{
    case NONE = 'NONE';
    case WEEKLY = 'WEEKLY';
    case MONTHLY = 'MONTHLY';
    case QUARTERLY = 'QUARTERLY';
    case YEARLY = 'YEARLY';

    public function getLabel(): string
    {
        return match ($this) {
            self::NONE => 'Ninguna',
            self::WEEKLY => 'Semanal',
            self::MONTHLY => 'Mensual',
            self::QUARTERLY => 'Trimestral',
            self::YEARLY => 'Anual',
        };
    }
}
