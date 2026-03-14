<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MovementType: string implements HasLabel
{
    case PURCHASE = 'PURCHASE';
    case CONSUMPTION = 'CONSUMPTION';
    case ADJUSTMENT = 'ADJUSTMENT';
    case LOSS = 'LOSS';

    public function getLabel(): string
    {
        return match ($this) {
            self::PURCHASE => 'Compra',
            self::CONSUMPTION => 'Consumo',
            self::ADJUSTMENT => 'Ajuste',
            self::LOSS => 'Pérdida',
        };
    }
}
