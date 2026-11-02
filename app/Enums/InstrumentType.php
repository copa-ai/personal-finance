<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum InstrumentType: string implements HasLabel
{
    case STOCK = 'STOCK';
    case ETF = 'ETF';
    case FUND = 'FUND';
    case CRYPTO = 'CRYPTO';
    case FX = 'FX';

    public function getLabel(): string
    {
        return match ($this) {
            self::STOCK => 'Acción',
            self::ETF => 'ETF',
            self::FUND => 'Fondo de inversión',
            self::CRYPTO => 'Criptomoneda',
            self::FX => 'Divisa',
        };
    }
}
