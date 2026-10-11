<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PlatformType: string implements HasLabel
{
    case BROKER = 'BROKER';
    case EXCHANGE = 'EXCHANGE';
    case BANK = 'BANK';
    case WALLET = 'WALLET';
    case FUND_MANAGER = 'FUND_MANAGER';
    case PENSION_PLAN = 'PENSION_PLAN';
    case OTHER = 'OTHER';

    public function getLabel(): string
    {
        return match ($this) {
            self::BROKER => 'Broker',
            self::EXCHANGE => 'Exchange',
            self::BANK => 'Banco',
            self::WALLET => 'Wallet',
            self::FUND_MANAGER => 'Gestora de fondos',
            self::PENSION_PLAN => 'Plan de pensiones',
            self::OTHER => 'Otra',
        };
    }
}
