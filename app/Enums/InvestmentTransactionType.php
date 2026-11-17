<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum InvestmentTransactionType: string implements HasLabel
{
    case BUY = 'BUY';
    case SELL = 'SELL';
    case DIVIDEND = 'DIVIDEND';
    case INTEREST = 'INTEREST';
    case FEE = 'FEE';
    case TRANSFER_IN = 'TRANSFER_IN';
    case TRANSFER_OUT = 'TRANSFER_OUT';
    case STAKING_REWARD = 'STAKING_REWARD';
    case SPLIT = 'SPLIT';
    case ADJUSTMENT = 'ADJUSTMENT';

    public function getLabel(): string
    {
        return match ($this) {
            self::BUY => 'Compra',
            self::SELL => 'Venta',
            self::DIVIDEND => 'Dividendo',
            self::INTEREST => 'Interés',
            self::FEE => 'Comisión',
            self::TRANSFER_IN => 'Traspaso de entrada',
            self::TRANSFER_OUT => 'Traspaso de salida',
            self::STAKING_REWARD => 'Recompensa de staking',
            self::SPLIT => 'Split',
            self::ADJUSTMENT => 'Ajuste de saldo',
        };
    }

    /**
     * Tipos que suman unidades a la posición (abren lotes FIFO).
     */
    public function addsUnits(): bool
    {
        return in_array($this, [self::BUY, self::TRANSFER_IN, self::STAKING_REWARD], true);
    }

    /**
     * Tipos que restan unidades (consumen lotes FIFO).
     */
    public function removesUnits(): bool
    {
        return in_array($this, [self::SELL, self::TRANSFER_OUT], true);
    }
}
