<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ReminderKind: string implements HasLabel
{
    case CONTRIBUTION = 'CONTRIBUTION';
    case STALE_PRICE = 'STALE_PRICE';
    case DEPOSIT_MATURITY = 'DEPOSIT_MATURITY';
    case REBALANCE = 'REBALANCE';
    case TAX = 'TAX';
    case PRICE_ALERT = 'PRICE_ALERT';
    case CUSTOM = 'CUSTOM';

    public function getLabel(): string
    {
        return match ($this) {
            self::CONTRIBUTION => 'Aportación',
            self::STALE_PRICE => 'Valoración desactualizada',
            self::DEPOSIT_MATURITY => 'Vencimiento',
            self::REBALANCE => 'Rebalanceo',
            self::TAX => 'Fiscal',
            self::PRICE_ALERT => 'Alerta de precio',
            self::CUSTOM => 'Personalizado',
        };
    }
}
