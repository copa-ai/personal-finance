<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PriceAlertCondition: string implements HasLabel
{
    case ABOVE = 'ABOVE';
    case BELOW = 'BELOW';
    case CHANGE_PCT = 'CHANGE_PCT';

    public function getLabel(): string
    {
        return match ($this) {
            self::ABOVE => 'Precio por encima de',
            self::BELOW => 'Precio por debajo de',
            self::CHANGE_PCT => 'Variación diaria (±%) mayor que',
        };
    }
}
