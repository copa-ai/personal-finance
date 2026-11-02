<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TradeSide: string implements HasColor, HasLabel
{
    case BUY = 'BUY';
    case SELL = 'SELL';

    public function getLabel(): string
    {
        return match ($this) {
            self::BUY => 'Compra',
            self::SELL => 'Venta',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::BUY => 'success',
            self::SELL => 'danger',
        };
    }
}
