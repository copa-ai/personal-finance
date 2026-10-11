<?php

namespace App\Models;

use App\Enums\InvestmentTransactionType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestmentTransaction extends Model
{
    use HasUuids;

    protected $fillable = [
        'platform_id',
        'instrument_id',
        'date',
        'type',
        'quantity',
        'price',
        'amount',
        'fees',
        'currency',
        'fx_rate_eur',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'type' => InvestmentTransactionType::class,
        'quantity' => 'decimal:10',
        'price' => 'decimal:10',
        'amount' => 'decimal:2',
        'fees' => 'decimal:2',
        'fx_rate_eur' => 'decimal:10',
    ];

    public function platform(): BelongsTo
    {
        return $this->belongsTo(InvestmentPlatform::class, 'platform_id');
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class, 'instrument_id');
    }
}
