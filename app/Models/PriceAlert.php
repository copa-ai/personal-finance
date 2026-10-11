<?php

namespace App\Models;

use App\Enums\PriceAlertCondition;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PriceAlert extends Model
{
    use HasUuids;

    protected $fillable = [
        'market_symbol',
        'condition',
        'threshold',
        'active',
        'notes',
    ];

    protected $casts = [
        'condition' => PriceAlertCondition::class,
        'threshold' => 'decimal:10',
        'last_price' => 'decimal:10',
        'active' => 'boolean',
        'last_triggered_at' => 'datetime',
    ];

    protected function marketSymbol(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        // Mismo formato que pf-market-data: tipo en minúsculas, código en mayúsculas.
        return \Illuminate\Database\Eloquent\Casts\Attribute::set(
            fn (?string $value): ?string => blank($value) ? null : (\App\Services\MarketData\MarketSymbol::normalize($value) ?? trim($value)),
        );
    }
}
