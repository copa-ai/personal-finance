<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ExpenseStatus: string implements HasLabel
{
    case PAID = 'PAID';
    case PENDING = 'PENDING';
    case REFUNDED = 'REFUNDED';
    case BLOCKED = 'BLOCKED';

    public function getLabel(): string
    {
        return match ($this) {
            self::PAID => 'Pagado',
            self::PENDING => 'Pendiente',
            self::REFUNDED => 'Reembolsado',
            self::BLOCKED => 'Bloqueado',
        };
    }
}
