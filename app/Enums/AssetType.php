<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AssetType: string implements HasLabel
{
    case CUENTA_CORRIENTE = 'CUENTA_CORRIENTE';
    case CUENTA_AHORRO = 'CUENTA_AHORRO';
    case DEPOSITO = 'DEPOSITO';
    case FONDO_INVERSION = 'FONDO_INVERSION';
    case ACCIONES = 'ACCIONES';
    case PLAN_PENSIONES = 'PLAN_PENSIONES';
    case OTRO = 'OTRO';

    public function getLabel(): string
    {
        return match ($this) {
            self::CUENTA_CORRIENTE => 'Cuenta corriente',
            self::CUENTA_AHORRO => 'Cuenta de ahorro',
            self::DEPOSITO => 'Depósito a plazo',
            self::FONDO_INVERSION => 'Fondo de inversión',
            self::ACCIONES => 'Acciones / valores',
            self::PLAN_PENSIONES => 'Plan de pensiones',
            self::OTRO => 'Otro',
        };
    }
}
