<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum InstrumentKind: string implements HasLabel
{
    case STOCK = 'STOCK';
    case ETF = 'ETF';
    case FUND = 'FUND';
    case CRYPTO = 'CRYPTO';
    case BOND = 'BOND';
    case DEPOSIT = 'DEPOSIT';
    case CASH = 'CASH';
    case COMMODITY = 'COMMODITY';
    case PENSION_PLAN = 'PENSION_PLAN';
    case OTHER = 'OTHER';

    public function getLabel(): string
    {
        return match ($this) {
            self::STOCK => 'Acción',
            self::ETF => 'ETF',
            self::FUND => 'Fondo de inversión',
            self::CRYPTO => 'Cripto',
            self::BOND => 'Bono / renta fija',
            self::DEPOSIT => 'Depósito',
            self::CASH => 'Efectivo',
            self::COMMODITY => 'Materia prima',
            self::PENSION_PLAN => 'Plan de pensiones',
            self::OTHER => 'Otro',
        };
    }
}
