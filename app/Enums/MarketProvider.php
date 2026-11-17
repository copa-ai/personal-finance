<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MarketProvider: string implements HasLabel
{
    case YAHOO = 'YAHOO';
    case BINANCE = 'BINANCE';
    /** Sin cotización: el precio se apunta a mano (depósitos, planes de pensiones, fondos sin símbolo). */
    case MANUAL = 'MANUAL';

    public function getLabel(): string
    {
        return match ($this) {
            self::YAHOO => 'Yahoo Finance',
            self::BINANCE => 'Binance',
            self::MANUAL => 'Manual',
        };
    }
}
