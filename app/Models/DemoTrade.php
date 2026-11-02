<?php

namespace App\Models;

use App\Enums\TradeSide;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoTrade extends Model
{
    use HasUuids;

    protected $fillable = [
        'demo_portfolio_id',
        'instrument_id',
        'side',
        'date',
        'units',
        'price',
        'fx_rate',
        'fee',
        'total_points',
    ];

    protected $casts = [
        'side' => TradeSide::class,
        'date' => 'date',
        'units' => 'decimal:8',
        'price' => 'decimal:8',
        'fx_rate' => 'decimal:8',
        'fee' => 'decimal:8',
        'total_points' => 'decimal:8',
    ];

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(DemoPortfolio::class, 'demo_portfolio_id');
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class, 'instrument_id');
    }
}
