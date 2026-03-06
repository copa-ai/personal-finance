<?php

namespace App\Enums;

enum MovementType: string
{
    case PURCHASE = 'PURCHASE';
    case CONSUMPTION = 'CONSUMPTION';
    case ADJUSTMENT = 'ADJUSTMENT';
    case LOSS = 'LOSS';
}
