<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemoPortfolio extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'initial_points',
        'start_date',
        'fee_fixed',
        'fee_percent',
        'benchmark_instrument_id',
        'notes',
    ];

    protected $casts = [
        'initial_points' => 'decimal:2',
        'start_date' => 'date',
        'fee_fixed' => 'decimal:2',
        'fee_percent' => 'decimal:4',
    ];

    public function trades(): HasMany
    {
        return $this->hasMany(DemoTrade::class, 'demo_portfolio_id');
    }

    public function benchmarkInstrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class, 'benchmark_instrument_id');
    }
}
