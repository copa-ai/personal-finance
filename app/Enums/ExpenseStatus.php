<?php

namespace App\Enums;

enum ExpenseStatus: string
{
    case PAID = 'PAID';
    case PENDING = 'PENDING';
    case REFUNDED = 'REFUNDED';
    case BLOCKED = 'BLOCKED';
}
