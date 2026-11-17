<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ContributionFrequency: string implements HasLabel
{
    case WEEKLY = 'WEEKLY';
    case MONTHLY = 'MONTHLY';
    case QUARTERLY = 'QUARTERLY';
    case YEARLY = 'YEARLY';

    public function getLabel(): string
    {
        return match ($this) {
            self::WEEKLY => 'Semanal',
            self::MONTHLY => 'Mensual',
            self::QUARTERLY => 'Trimestral',
            self::YEARLY => 'Anual',
        };
    }

    public function advance(\Carbon\CarbonInterface $date): \Carbon\CarbonInterface
    {
        return match ($this) {
            self::WEEKLY => $date->copy()->addWeek(),
            self::MONTHLY => $date->copy()->addMonthNoOverflow(),
            self::QUARTERLY => $date->copy()->addMonthsNoOverflow(3),
            self::YEARLY => $date->copy()->addYearNoOverflow(),
        };
    }
}
