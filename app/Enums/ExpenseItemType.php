<?php

namespace App\Enums;

enum ExpenseItemType: string
{
    case FIXED = 'FIXED';
    case VARIABLE_REGULAR = 'VARIABLE_REGULAR';
    case VARIABLE_IRREGULAR = 'VARIABLE_IRREGULAR';
}
