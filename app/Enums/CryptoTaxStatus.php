<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CryptoTaxStatus: string implements HasLabel
{
    case DETECTED = 'DETECTED';
    case NOT_DETECTED = 'NOT_DETECTED';
    case UNKNOWN = 'UNKNOWN';

    public function getLabel(): string
    {
        return match ($this) {
            self::DETECTED => 'Detectada por el fisco',
            self::NOT_DETECTED => 'No detectada por el fisco',
            self::UNKNOWN => 'Sin determinar',
        };
    }
}
