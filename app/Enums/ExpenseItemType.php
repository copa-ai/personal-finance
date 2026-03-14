<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ExpenseItemType: string implements HasLabel
{
    case FIXED = 'FIXED';
    case VARIABLE_REGULAR = 'VARIABLE_REGULAR';
    case VARIABLE_IRREGULAR = 'VARIABLE_IRREGULAR';

    public function getLabel(): string
    {
        return match ($this) {
            self::FIXED => 'Fijo',
            self::VARIABLE_REGULAR => 'Variable (regular)',
            self::VARIABLE_IRREGULAR => 'Variable (irregular)',
        };
    }
}
