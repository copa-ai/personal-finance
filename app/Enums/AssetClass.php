<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AssetClass: string implements HasLabel
{
    case EQUITY = 'EQUITY';
    case FIXED_INCOME = 'FIXED_INCOME';
    case MONEY_MARKET = 'MONEY_MARKET';
    case CRYPTO = 'CRYPTO';
    case COMMODITY = 'COMMODITY';
    case REAL_ESTATE = 'REAL_ESTATE';
    case CASH = 'CASH';
    case MIXED = 'MIXED';
    case OTHER = 'OTHER';

    public function getLabel(): string
    {
        return match ($this) {
            self::EQUITY => 'Renta variable',
            self::FIXED_INCOME => 'Renta fija',
            self::MONEY_MARKET => 'Monetario',
            self::CRYPTO => 'Cripto',
            self::COMMODITY => 'Materias primas',
            self::REAL_ESTATE => 'Inmobiliario',
            self::CASH => 'Liquidez',
            self::MIXED => 'Mixto',
            self::OTHER => 'Otro',
        };
    }
}
