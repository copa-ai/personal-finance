<?php

namespace App\Enums;

enum Recurrence: string
{
    case NONE = 'NONE';
    case MONTHLY = 'MONTHLY';
    case QUARTERLY = 'QUARTERLY';
    case YEARLY = 'YEARLY';
}
